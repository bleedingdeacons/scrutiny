<?php

declare(strict_types=1);

namespace Scrutiny;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use RuntimeException;
use Scrutiny\Admin\AuditLogAdmin;
use Scrutiny\Audit\AuditLogger;
use Scrutiny\Audit\AuditRepository;
use Scrutiny\Audit\AuditTracker;
use Scrutiny\Audit\Interfaces\AuditLoggerInterface;
use Scrutiny\Audit\Interfaces\AuditRepositoryInterface;
use Scrutiny\Privacy\DataObscurer;
use Scrutiny\Privacy\Interfaces\DataObscurerInterface;
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
 *   AuditRepository  – stores audit log entries in a custom database table
 *   AuditLogger      – writes log entries (who, what, when — no raw PII)
 *   AuditTracker     – hooks into Unity member and group lifecycle to capture changes
 *   DataObscurer     – masks personal data in the admin UI
 *   AuditLogAdmin    – read-only admin page for viewing the audit trail
 *
 * Capabilities:
 *   scrutiny_view_personal_data – grants a user the right to see unmasked values
 *                                  (assigned to administrators on activation)
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

        self::logInfo('Scrutiny initialised', ['version' => defined('SCRUTINY_VERSION') ? SCRUTINY_VERSION : 'unknown']);

        // Start Monitoring Changes
        self::$container->get(MemberChangeTracker::class);

        self::$container->get(PositionChangeTracker::class);

        self::$container->get(GroupChangeTracker::class);

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
     * @param Container $container
     */
    private static function registerServices(Container $container): void
    {
        // Audit Repository
        $container->register(AuditRepositoryInterface::class, function () {
            return new AuditRepository();
        });

        // Audit Logger
        $container->register(AuditLoggerInterface::class, function (ContainerInterface $c) {
            return new AuditLogger(
                $c->get(AuditRepositoryInterface::class)
            );
        });

        // Audit Tracker (hooks into member lifecycle)
        $container->register(AuditTracker::class, function (ContainerInterface $c) {
            return new AuditTracker(
                $c->get(Configuration::class),
                $c->get(AuditLoggerInterface::class)
            );
        });

        // Data Obscurer
        $container->register(DataObscurerInterface::class, function (ContainerInterface $c) {
            return new DataObscurer(
                $c->get(Configuration::class),
                $c->get(AuditLoggerInterface::class)
            );
        });

        // Audit Log Admin Page
        $container->register(AuditLogAdmin::class, function (ContainerInterface $c) {
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