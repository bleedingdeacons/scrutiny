<?php

declare(strict_types=1);

namespace Scrutiny;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use RuntimeException;
use Scrutiny\Admin\AuditLogAdmin;
use Scrutiny\Admin\MemberPrunerAdmin;
use Scrutiny\Admin\Members\PersonalDataMinder;
use Scrutiny\Admin\ScrutinyMenu;
use Scrutiny\Audit\GdprAuditLogger;
use Scrutiny\Audit\GdprAuditRepository;
use Scrutiny\Audit\AuditTracker;
use Scrutiny\Audit\Interfaces\AuditLogger;
use Scrutiny\Audit\Interfaces\AuditRepository;
use Scrutiny\Cleanup\MemberPruner;
use Scrutiny\Cleanup\MemberTrashCleaner;
use Scrutiny\Cleanup\PrunerCron;
use Scrutiny\Cleanup\PrunerSettings;
use Scrutiny\Privacy\GroupFieldsObscurer;
use Scrutiny\Privacy\MemberFieldsObscurer;
use Scrutiny\Privacy\PersonalDataPolicy;
use Scrutiny\Privacy\ResponderCertificationGuard;
use Scrutiny\Privacy\PrivacyPolicyFormatter;
use Scrutiny\Rest\PrivacyPolicyController;
use Scrutiny\Shortcodes\PrivacyPolicyShortcode;
use Psr\Container\ContainerInterface;
use TsmlForUnity\PrivacyPolicies\TsmlPrivacyPolicyFactory;
use TsmlForUnity\PrivacyPolicies\TsmlPrivacyPolicyRepository;
use Unity\Core\Interfaces\Container;
use Unity\Core\Interfaces\Configuration;
use Unity\Members\Interfaces\MemberChangeTracker;
use Unity\Members\Interfaces\MemberRepository;
use Unity\Groups\Interfaces\GroupChangeTracker;
use Unity\Positions\Interfaces\PositionChangeTracker;
use Unity\PrivacyPolicies\Interfaces\PrivacyPolicyFactory;
use Unity\PrivacyPolicies\Interfaces\PrivacyPolicyRepository;
use function add_action;
use function is_admin;

/**
 * Main Scrutiny Plugin Class
 *
 * Provides GDPR-compliant audit logging and personal data obscuring for Unity.
 *
 * Architecture:
 *   GdprAuditRepository   – stores audit log entries in a custom database table
 *   GdprAuditLogger       – writes log entries (who, what, when — no raw PII)
 *   AuditTracker          – hooks into Unity member and group lifecycle to capture changes
 *   PersonalDataPolicy    – capability checks, tier resolution, and the obscuring helpers
 *   MemberFieldsObscurer  – obscures the two ACF personal-data fields on member edit screens
 *   GroupFieldsObscurer   – masks and write-protects TSML's nine named-contact fields
 *                           (contact_1_name … contact_3_phone) on the meeting and group
 *                           edit screens
 *   AuditLogAdmin         – read-only admin page for viewing the audit trail
 *                           (lives under the Intergroup menu — operational tool)
 *   ScrutinyMenu          – registers the top-level "Scrutiny" admin menu
 *                           used as parent for all Scrutiny configuration pages
 *   MemberPruner          – trashes rotated officers and inactive home-group
 *                           non-GSRs; invoked deliberately, never on every load
 *   PrunerSettings        – wraps wp_options storage of the two pruner thresholds
 *                           (rotation grace, inactivity months); single source of
 *                           truth for both the pruner and its admin page
 *   MemberPrunerAdmin     – settings page for the pruner thresholds, under the
 *                           top-level Scrutiny menu (does not run the pruner itself)
 *   PrunerCron            – schedules the weekly WP-Cron event that runs the
 *                           pruner unattended, and clears it on deactivation
 *   MemberTrashCleaner    – permanently deletes trashed members past the
 *                           retention period; runs after each cron pruner pass
 *   PrivacyPolicyController – read-only REST endpoints for the privacy-policy
 *                           CPT (the CPT itself has show_in_rest=false), so
 *                           frontends and other services can fetch the
 *                           currently-active policy without admin access
 *   PrivacyPolicyFactory  – Unity interface bound to TsmlPrivacyPolicyFactory;
 *                           constructs PrivacyPolicy entities from a post ID
 *                           or from raw values
 *   PrivacyPolicyRepository – Unity interface bound to TsmlPrivacyPolicyRepository;
 *                           CRUD over the privacy-policy CPT plus a
 *                           findActive() helper for the "single policy
 *                           in force" query that controller, shortcode,
 *                           and audit consumers all need
 *   PrivacyPolicyShortcode – frontend-facing [scrutiny_privacy_policy]
 *                           shortcode that renders the active policy's
 *                           metadata (contact, email, version, modified)
 *                           plus its WYSIWYG body inline. Shares the
 *                           PrivacyPolicyFormatter binding with the
 *                           controller so the shortcode and the REST
 *                           endpoint render identical content
 *   PrivacyPolicyFormatter – stateless projector that maps a domain
 *                           PrivacyPolicy to the flat REST/shortcode
 *                           shape; held by both the controller and the
 *                           shortcode
 *
 * Capabilities:
 *   scrutiny_view_personal_data – grants a user the right to see unmasked values
 *                                  (assigned to administrators on activation)
 *   scrutiny_edit_personal_data – grants a user the right to update personal data
 *                                  fields (assigned to administrators on activation)
 *   scrutiny_edit_responder_certification – grants a user the right to change a
 *                                  member's responder-certification stage; without
 *                                  it the value is visible but read-only (assigned
 *                                  to administrators on activation)
 */
class Plugin
{
    use \Scrutiny\Logger\HasLogger;

    protected static function logChannel(): string
    {
        return 'scrutiny';
    }

    private static ?ContainerInterface $container = null;
    private static bool $initialized = false;

    /**
     * Initialize the plugin
     *
     * @param Container $unityContainer The Unity dependency container
     */
    public static function init(Container $unityContainer): void
    {
        if (self::$initialized) {
            return;
        }

        self::$container = $unityContainer;
        self::registerServices($unityContainer);
        self::$initialized = true;

        // Start Monitoring Changes
        self::$container->get(MemberChangeTracker::class);

        self::$container->get(PositionChangeTracker::class);

        self::$container->get(GroupChangeTracker::class);

        // Always initialise the tracker so changes are logged
        self::$container->get(AuditTracker::class);

        // Ensure capabilities are up-to-date (handles upgrades where
        // the activation hook did not re-run after new caps were added)
        self::ensureCapabilities();

        // Wire up the two obscurers. Each registers its own WP hooks
        // in register() — neither has side effects in the constructor.
        //
        // Both register unconditionally: MemberFieldsObscurer's
        // acf/format_value filters fire on the frontend, and
        // GroupFieldsObscurer's save_post_* hooks fire wherever a
        // post is saved (admin, REST, WP-CLI). Each obscurer gates
        // its admin-only hooks internally.
        self::$container->get(MemberFieldsObscurer::class)->register();
        self::$container->get(GroupFieldsObscurer::class)->register();

        // Responder Certification Guard — registers acf/prepare_field and
        // acf/update_value filters so the certification stage is visible but
        // only editable by users with the scrutiny_edit_responder_certification
        // capability. Registers unconditionally: the update_value guard must
        // fire on every save path, and prepare_field is admin-only anyway.
        self::$container->get(ResponderCertificationGuard::class)->register();

        // Cron handler — wires the WP-Cron action and the defensive
        // re-scheduling check. Runs on every page load (not just
        // admin) because WP-Cron's wake-up cycle fires on whichever
        // request hits first; gating this behind is_admin() would
        // miss front-end cron triggers entirely.
        self::$container->get(PrunerCron::class)->register();

        // REST controller for the privacy-policy CPT. Registers on
        // every request (not just admin) because rest_api_init fires
        // on REST requests, which don't go through the admin
        // bootstrap. Read-only and public; see the controller
        // docblock for the route surface.
        self::$container->get(PrivacyPolicyController::class)->register();

        // Privacy policy shortcode — registers unconditionally
        // because shortcodes are resolved by content rendering on
        // any request (frontend, REST, admin previews). Calling
        // add_shortcode is idempotent and side-effect free until
        // the tag is actually used in content, so registering
        // alongside the REST controller costs nothing on requests
        // where the shortcode never appears.
        self::$container->get(PrivacyPolicyShortcode::class)->register();

        // Initialise admin page when in the dashboard
        if (is_admin()) {
            // Register the top-level Scrutiny menu first. The menu
            // class is hook-only and stateless, so we wire its
            // static callbacks directly rather than going through
            // the container. Priority 10 (default) so the parent
            // menu exists before page classes register their
            // submenus on priority 20; priority 999 for the
            // remove-default-submenu cleanup so it runs after every
            // submenu registration.
            add_action('admin_menu', [ScrutinyMenu::class, 'registerMenu'], 10);
            add_action('admin_menu', [ScrutinyMenu::class, 'removeDefaultSubmenu'], 999);

            self::$container->get(AuditLogAdmin::class);
            self::$container->get(PersonalDataMinder::class);
            self::$container->get(MemberPrunerAdmin::class);
        }

        self::logDebug('Initialised', ['version' => defined('SCRUTINY_VERSION') ? SCRUTINY_VERSION : 'unknown']);
    }

    /**
     * Register all Scrutiny services in Unity's container
     *
     * @param Container $container
     */
    private static function registerServices(Container $container): void
    {
        // Privacy Policy Factory — binds the Unity interface to the
        // TSML concrete. Doing this here (rather than in tsml-for-unity's
        // Plugin.php where the analogous Member binding lives) is a
        // deliberate exception: it gives Scrutiny a hard dependency on
        // tsml-for-unity for the privacy-policy data path. Acceptable
        // because privacy policies are intrinsically a Scrutiny concern
        // (the controller, shortcode, and audit logger all live here),
        // and Scrutiny has no other implementation to swap in.
        //
        // Stateless; constructor takes no dependencies, so a fresh
        // instance per resolution is fine.
        $container->register(PrivacyPolicyFactory::class, function () {
            return new TsmlPrivacyPolicyFactory();
        });

        // Privacy Policy Repository — pulls the factory back out of the
        // container rather than constructing one inline so the binding
        // above remains the single source of truth. Mirrors the pattern
        // tsml-for-unity uses for MemberRepository → TsmlMemberRepository.
        $container->register(PrivacyPolicyRepository::class, function (ContainerInterface $c) {
            return new TsmlPrivacyPolicyRepository(
                $c->get(PrivacyPolicyFactory::class)
            );
        });

        // Audit Repository
        $container->register(AuditRepository::class, function () {
            return new GdprAuditRepository();
        });

        // Audit Logger
        $container->register(AuditLogger::class, function (ContainerInterface $c) {
            return new GdprAuditLogger(
                $c->get(AuditRepository::class)
            );
        });

        // Audit Tracker (hooks into member lifecycle)
        $container->register(AuditTracker::class, function (ContainerInterface $c) {
            return new AuditTracker(
                $c->get(Configuration::class),
                $c->get(AuditLogger::class),
                $c->get(PersonalDataPolicy::class)
            );
        });

        // Personal Data Policy — shared stateless helpers (capabilities,
        // tier resolution, obscuring). Consumed by both obscurers and by
        // AuditTracker for its capability checks.
        $container->register(PersonalDataPolicy::class, function () {
            return new PersonalDataPolicy();
        });

        // Member Fields Obscurer — ACF personal email and mobile number.
        $container->register(MemberFieldsObscurer::class, function (ContainerInterface $c) {
            return new MemberFieldsObscurer(
                $c->get(Configuration::class),
                $c->get(PersonalDataPolicy::class)
            );
        });

        // Group Fields Obscurer — TSML meeting/group contact postmeta.
        $container->register(GroupFieldsObscurer::class, function (ContainerInterface $c) {
            return new GroupFieldsObscurer(
                $c->get(PersonalDataPolicy::class)
            );
        });

        // Responder Certification Guard — makes the member
        // responder-certification field read-only for users without the
        // scrutiny_edit_responder_certification capability.
        $container->register(ResponderCertificationGuard::class, function (ContainerInterface $c) {
            return new ResponderCertificationGuard(
                $c->get(Configuration::class)
            );
        });

        // Audit Log Admin Page
        $container->register(AuditLogAdmin::class, function (ContainerInterface $c) {
            return new AuditLogAdmin(
                $c->get(AuditRepository::class),
                $c->get(AuditLogger::class)
            );
        });

        // Personal Data Minder (Clear/Undo buttons on member edit screen)
        $container->register(PersonalDataMinder::class, function (ContainerInterface $c) {
            return new PersonalDataMinder(
                $c->get(Configuration::class)
            );
        });

        // Pruner Settings — single source of truth for the two
        // thresholds the pruner reads, and the enabled flag.
        // Stateless wrapper around get_option / update_option, so
        // a fresh instance per resolution is fine.
        $container->register(PrunerSettings::class, function () {
            return new PrunerSettings();
        });

        // Member Pruner — trashes rotated officers and inactive
        // home-group non-GSRs. Hook-free; invoked deliberately by an
        // admin action, WP-CLI command, or scheduled job. Receives
        // PrunerSettings so prune() can short-circuit when the
        // pruner is disabled in settings — the safety boundary lives
        // on the service itself, not on individual callers.
        $container->register(MemberPruner::class, function (ContainerInterface $c) {
            return new MemberPruner(
                $c->get(MemberRepository::class),
                null,
                $c->get(PrunerSettings::class)
            );
        });

        // Pruner Settings admin page — registers its own admin_menu
        // and admin_init hooks in the constructor, so resolving the
        // service is what wires it up.
        $container->register(MemberPrunerAdmin::class, function (ContainerInterface $c) {
            return new MemberPrunerAdmin(
                $c->get(PrunerSettings::class)
            );
        });

        // Member Trash Cleaner — permanently deletes trashed
        // members past the retention threshold. Stateless, hook-free;
        // invoked by the cron handler after a successful prune pass.
        $container->register(MemberTrashCleaner::class, function (ContainerInterface $c) {
            return new MemberTrashCleaner(
                $c->get(MemberRepository::class)
            );
        });

        // Pruner Cron — schedules the weekly cron event and handles
        // it when WP-Cron fires. Stateless wrapper around three
        // injected dependencies, so a fresh instance is fine.
        $container->register(PrunerCron::class, function (ContainerInterface $c) {
            return new PrunerCron(
                $c->get(MemberPruner::class),
                $c->get(PrunerSettings::class),
                $c->get(MemberTrashCleaner::class)
            );
        });

        // Privacy Policy formatter — projects a domain PrivacyPolicy
        // into the flat REST/shortcode shape. Stateless and shared
        // between the REST controller and the shortcode so both
        // surfaces emit the same field set, ordering, and timestamp
        // format. Registered as a single binding so a future change
        // to the projection (e.g. a new field) only has to be made
        // in one place.
        $container->register(PrivacyPolicyFormatter::class, function () {
            return new PrivacyPolicyFormatter();
        });

        // Privacy Policy REST controller — exposes the privacy-policy
        // CPT as a read-only REST resource. Holds the repository for
        // storage access and the formatter for response shaping.
        $container->register(PrivacyPolicyController::class, function (ContainerInterface $c) {
            return new PrivacyPolicyController(
                $c->get(PrivacyPolicyRepository::class),
                $c->get(PrivacyPolicyFormatter::class)
            );
        });

        // Privacy Policy shortcode — the frontend twin of the REST
        // controller. Depends on the repository for findActive() and
        // on the formatter for projection. The two share a formatter
        // binding (registered above) so the shortcode and the REST
        // endpoint cannot disagree about field selection, active-flag
        // coercion, or timestamp shape on the same page load.
        $container->register(PrivacyPolicyShortcode::class, function (ContainerInterface $c) {
            return new PrivacyPolicyShortcode(
                $c->get(PrivacyPolicyRepository::class),
                $c->get(PrivacyPolicyFormatter::class)
            );
        });
    }

    /**
     * Ensure custom capabilities exist on the administrator role.
     *
     * WordPress stores role capabilities in the database. They are only
     * written when add_cap() is explicitly called — typically in the
     * plugin activation hook. If the plugin is updated and new
     * capabilities are introduced (e.g. scrutiny_edit_personal_data)
     * without the activation hook re-running, they will be missing.
     *
     * We check the administrator role directly rather than relying on
     * a stored version flag, because a cooperating-but-broken third
     * party can revoke our caps in its own deactivation hook. For
     * example, an earlier version of this codebase shipped the TSML
     * contact guard as a standalone plugin whose deactivate() loop
     * stripped scrutiny_view_personal_data and scrutiny_edit_personal_data
     * from every role — so deactivating it wiped Scrutiny's own caps.
     * A version-gated bailout would then refuse to re-grant them.
     *
     * add_cap() is a no-op when the role already has the capability
     * (no DB write), so calling it on every load is cheap.
     */
    private static function ensureCapabilities(): void
    {
        $adminRole = get_role('administrator');
        if (!$adminRole) {
            return;
        }

        if (!$adminRole->has_cap(PersonalDataPolicy::VIEW_CAPABILITY)) {
            $adminRole->add_cap(PersonalDataPolicy::VIEW_CAPABILITY);
        }
        if (!$adminRole->has_cap(PersonalDataPolicy::EDIT_CAPABILITY)) {
            $adminRole->add_cap(PersonalDataPolicy::EDIT_CAPABILITY);
        }
        if (!$adminRole->has_cap(ResponderCertificationGuard::EDIT_CAPABILITY)) {
            $adminRole->add_cap(ResponderCertificationGuard::EDIT_CAPABILITY);
        }
    }

    /**
     * Run on plugin activation
     *
     * Creates the audit log database table and assigns the
     * scrutiny_view_personal_data, scrutiny_edit_personal_data and
     * scrutiny_edit_responder_certification capabilities to administrators.
     */
    public static function activate(): void
    {
        GdprAuditRepository::createTable();

        // Grant the capabilities to administrators
        $adminRole = get_role('administrator');
        if ($adminRole) {
            $adminRole->add_cap(PersonalDataPolicy::VIEW_CAPABILITY);
            $adminRole->add_cap(PersonalDataPolicy::EDIT_CAPABILITY);
            $adminRole->add_cap(ResponderCertificationGuard::EDIT_CAPABILITY);
        }

        // Schedule the weekly pruner cron event. Idempotent — the
        // call is a no-op if the event is already in the queue, so
        // reactivating the plugin doesn't create duplicates.
        PrunerCron::schedule();
    }

    /**
     * Deactivation handler.
     *
     * Clears scheduled cron events so a deactivated plugin leaves no
     * lingering entries in the cron queue. Note that user-stored
     * settings (PrunerSettings option keys) are deliberately left
     * intact — deactivation isn't uninstall, and an admin who
     * reactivates the plugin should find their thresholds
     * unchanged.
     */
    public static function deactivate(): void
    {
        PrunerCron::unschedule();
    }

    /**
     * Get the dependency container
     *
     * @return ContainerInterface
     * @throws RuntimeException If plugin is not initialized
     */
    public static function getContainer(): ContainerInterface
    {
        if (self::$container === null) {
            throw new RuntimeException('Scrutiny Plugin not initialized');
        }
        return self::$container;
    }
}