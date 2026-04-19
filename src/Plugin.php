<?php

declare(strict_types=1);

namespace Scrutiny;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use RuntimeException;
use Scrutiny\Admin\AuditLogAdmin;
use Scrutiny\Admin\Members\PersonalDataMinder;
use Scrutiny\Audit\GdprAuditLogger;
use Scrutiny\Audit\GdprAuditRepository;
use Scrutiny\Audit\AuditTracker;
use Scrutiny\Audit\Interfaces\AuditLogger;
use Scrutiny\Audit\Interfaces\AuditRepository;
use Scrutiny\Privacy\GroupFieldsObscurer;
use Scrutiny\Privacy\MemberFieldsObscurer;
use Scrutiny\Privacy\PersonalDataPolicy;
use Psr\Container\ContainerInterface;
use Unity\Core\Interfaces\Container;
use Unity\Core\Interfaces\Configuration;
use Unity\Members\Interfaces\MemberChangeTracker;
use Unity\Groups\Interfaces\GroupChangeTracker;
use Unity\Positions\Interfaces\PositionChangeTracker;
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
 *
 * Capabilities:
 *   scrutiny_view_personal_data – grants a user the right to see unmasked values
 *                                  (assigned to administrators on activation)
 *   scrutiny_edit_personal_data – grants a user the right to update personal data
 *                                  fields (assigned to administrators on activation)
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

        // Initialise admin page when in the dashboard
        if (is_admin()) {
            self::$container->get(AuditLogAdmin::class);
            self::$container->get(PersonalDataMinder::class);
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
    }

    /**
     * Run on plugin activation
     *
     * Creates the audit log database table and assigns the
     * scrutiny_view_personal_data and scrutiny_edit_personal_data
     * capabilities to administrators.
     */
    public static function activate(): void
    {
        GdprAuditRepository::createTable();

        // Grant the capabilities to administrators
        $adminRole = get_role('administrator');
        if ($adminRole) {
            $adminRole->add_cap(PersonalDataPolicy::VIEW_CAPABILITY);
            $adminRole->add_cap(PersonalDataPolicy::EDIT_CAPABILITY);
        }
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