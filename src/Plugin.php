<?php

declare(strict_types=1);

namespace Scrutiny;

use RuntimeException;
use Scrutiny\Admin\AuditLogAdmin;
use Scrutiny\Audit\AuditLogger;
use Scrutiny\Audit\AuditRepository;
use Scrutiny\Audit\AuditTracker;
use Scrutiny\Audit\Interfaces\AuditLoggerInterface;
use Scrutiny\Audit\Interfaces\AuditRepositoryInterface;
use Scrutiny\Privacy\DataObscurer;
use Scrutiny\Privacy\Interfaces\DataObscurerInterface;
use Unity\Core\DependencyContainer;
use Unity\Core\Interfaces\Configuration;
use function add_action;
use function is_admin;

/**
 * Main Scrutiny Plugin Class
 *
 * Provides GDPR-compliant audit logging and personal data obscuring for Unity.
 *
 * Architecture:
 *   AuditRepository  – stores audit log entries in a custom database table
 *   AuditLogger      – writes log entries (who, what, when — no raw PII)
 *   AuditTracker     – hooks into Unity member lifecycle to capture changes
 *   DataObscurer     – masks personal data in the admin UI
 *   AuditLogAdmin    – read-only admin page for viewing the audit trail
 *
 * Capabilities:
 *   scrutiny_view_personal_data – grants a user the right to see unmasked values
 *                                  (assigned to administrators on activation)
 */
class Plugin
{
    private static ?DependencyContainer $container = null;
    private static bool $initialized = false;

    /**
     * Initialize the plugin
     *
     * @param DependencyContainer $unityContainer The Unity dependency container
     */
    public static function init(DependencyContainer $unityContainer): void
    {
        if (self::$initialized) {
            return;
        }

        self::$container = $unityContainer;
        self::registerServices($unityContainer);
        self::$initialized = true;

        // Always initialise the tracker so changes are logged
        self::$container->get(AuditTracker::class);

        // Always initialise the obscurer so personal data is masked
        self::$container->get(DataObscurerInterface::class);

        // Initialise admin page when in the dashboard
        if (is_admin()) {
            self::$container->get(AuditLogAdmin::class);
        }
    }

    /**
     * Register all Scrutiny services in Unity's container
     *
     * @param DependencyContainer $container
     */
    private static function registerServices(DependencyContainer $container): void
    {
        // Audit Repository
        $container->register(AuditRepositoryInterface::class, function () {
            return new AuditRepository();
        });

        // Audit Logger
        $container->register(AuditLoggerInterface::class, function (DependencyContainer $c) {
            return new AuditLogger(
                $c->get(AuditRepositoryInterface::class)
            );
        });

        // Audit Tracker (hooks into member lifecycle)
        $container->register(AuditTracker::class, function (DependencyContainer $c) {
            return new AuditTracker(
                $c->get(Configuration::class),
                $c->get(AuditLoggerInterface::class)
            );
        });

        // Data Obscurer
        $container->register(DataObscurerInterface::class, function (DependencyContainer $c) {
            return new DataObscurer(
                $c->get(AuditLoggerInterface::class)
            );
        });

        // Audit Log Admin Page
        $container->register(AuditLogAdmin::class, function (DependencyContainer $c) {
            return new AuditLogAdmin(
                $c->get(AuditRepositoryInterface::class),
                $c->get(AuditLoggerInterface::class)
            );
        });
    }

    /**
     * Run on plugin activation
     *
     * Creates the audit log database table and assigns the
     * scrutiny_view_personal_data capability to administrators.
     */
    public static function activate(): void
    {
        AuditRepository::createTable();

        // Grant the capability to administrators
        $adminRole = get_role('administrator');
        if ($adminRole) {
            $adminRole->add_cap(DataObscurer::CAPABILITY);
        }
    }

    /**
     * Get the dependency container
     *
     * @return DependencyContainer
     * @throws RuntimeException If plugin is not initialized
     */
    public static function getContainer(): DependencyContainer
    {
        if (self::$container === null) {
            throw new RuntimeException('Scrutiny Plugin not initialized');
        }
        return self::$container;
    }
}
