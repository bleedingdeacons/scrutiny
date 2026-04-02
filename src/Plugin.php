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
use Scrutiny\Privacy\PersonalDataObscurer;
use Scrutiny\Privacy\Interfaces\DataObscurer;
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
 *   GdprAuditRepository  – stores audit log entries in a custom database table
 *   GdprAuditLogger      – writes log entries (who, what, when — no raw PII)
 *   AuditTracker     – hooks into Unity member and group lifecycle to capture changes
 *   PersonalDataObscurer     – masks personal data in the admin UI
 *   AuditLogAdmin    – read-only admin page for viewing the audit trail
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

        // Always initialise the obscurer so personal data is masked
        self::$container->get(DataObscurer::class);

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
                $c->get(DataObscurer::class)
            );
        });

        // Data Obscurer
        $container->register(DataObscurer::class, function (ContainerInterface $c) {
            return new PersonalDataObscurer(
                $c->get(Configuration::class),
                $c->get(AuditLogger::class)
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
     * This method checks once per plugin version (tracked via a
     * wp_option) and adds any missing capabilities.
     */
    private static function ensureCapabilities(): void
    {
        $optionKey = 'scrutiny_caps_version';
        $currentVersion = defined('SCRUTINY_VERSION') ? SCRUTINY_VERSION : '0.0.0';

        if (get_option($optionKey) === $currentVersion) {
            return;
        }

        $adminRole = get_role('administrator');
        if ($adminRole) {
            $adminRole->add_cap(PersonalDataObscurer::VIEW_CAPABILITY);
            $adminRole->add_cap(PersonalDataObscurer::EDIT_CAPABILITY);
        }

        update_option($optionKey, $currentVersion);
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
            $adminRole->add_cap(PersonalDataObscurer::VIEW_CAPABILITY);
            $adminRole->add_cap(PersonalDataObscurer::EDIT_CAPABILITY);
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