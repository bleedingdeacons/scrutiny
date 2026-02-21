<?php

declare(strict_types=1);

namespace Scrutiny\Admin;

use Scrutiny\Audit\Interfaces\AuditLoggerInterface;
use Scrutiny\Audit\Interfaces\AuditRepositoryInterface;
use Scrutiny\Privacy\PersonalDataFields;
use function add_action;
use function add_submenu_page;
use function admin_url;
use function check_admin_referer;
use function current_user_can;
use function esc_attr;
use function esc_html;
use function esc_url;
use function get_userdata;
use function wp_nonce_url;

/**
 * Audit Log Admin Page
 *
 * Provides a read-only admin interface for viewing the GDPR audit trail.
 * Accessible under the Intergroup menu to administrators only.
 */
class AuditLogAdmin
{
    private AuditRepositoryInterface $repository;
    private AuditLoggerInterface $logger;

    public const MENU_SLUG = 'scrutiny-audit-log';
    public const CAPABILITY = 'manage_options';
    public const NONCE_ACTION = 'scrutiny_purge_log';

    /**
     * Available action types for filtering
     */
    private const ACTION_TYPES = ['view', 'create', 'update', 'delete', 'export', 'purge'];

    /**
     * Available entity types for filtering
     */
    private const ENTITY_TYPES = [
            'member' => 'Member',
            'user' => 'User',
            'audit_log' => 'Audit Log',
            'all' => 'All Entities'
    ];

    public function __construct(AuditRepositoryInterface $repository, AuditLoggerInterface $logger)
    {
        $this->repository = $repository;
        $this->logger = $logger;

        add_action('admin_menu', [$this, 'registerMenu'], 20);
        add_action('admin_init', [$this, 'handlePurge']);
    }

    /**
     * Register the audit log submenu page under the Intergroup menu
     */
    public function registerMenu(): void
    {
        add_submenu_page(
                'intergroup',
                'Audit Log',
                'Audit Log',
                self::CAPABILITY,
                self::MENU_SLUG,
                [$this, 'renderPage']
        );
    }

    /**
     * Handle the purge action when the administrator requests it
     */
    public function handlePurge(): void
    {
        if (!isset($_GET['scrutiny_purge']) || !current_user_can(self::CAPABILITY)) {
            return;
        }

        check_admin_referer(self::NONCE_ACTION);

        $days = (int) ($_GET['scrutiny_purge_days'] ?? 365);
        $deleted = $this->repository->purge($days);

        $this->logger->log(
                'purge',
                'audit_log',
                0,
                'all',
                "Purged {$deleted} entries older than {$days} days"
        );

        add_action('admin_notices', function () use ($deleted, $days) {
            echo '<div class="notice notice-success is-dismissible"><p>';
            echo esc_html("Scrutiny: Purged {$deleted} audit log entries older than {$days} days.");
            echo '</p></div>';
        });
    }

    /**
     * Get all users who have made audit log entries
     *
     * @return array Array of user objects with id and login
     */
    private function getAuditUsers(): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'scrutiny_audit_log';

        $users = $wpdb->get_results(
                "SELECT DISTINCT user_id, user_login 
             FROM {$table} 
             ORDER BY user_login ASC"
        );

        return $users ?: [];
    }

    /**
     * Render the audit log admin page
     */
    public function renderPage(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to access this page.', 'scrutiny'));
            return;
        }

        // Collect filter parameters
        $filters = [
                'entity_type' => sanitize_text_field($_GET['entity_type'] ?? ''),
                'entity_id'   => (int) ($_GET['entity_id'] ?? 0),
                'action'      => sanitize_text_field($_GET['filter_action'] ?? ''),
                'field_name'  => sanitize_text_field($_GET['field_name'] ?? ''),
                'user_id'     => (int) ($_GET['user_id'] ?? 0),
                'date_from'   => sanitize_text_field($_GET['date_from'] ?? ''),
                'date_to'     => sanitize_text_field($_GET['date_to'] ?? ''),
                'per_page'    => 50,
                'page'        => max((int) ($_GET['paged'] ?? 1), 1),
        ];

        // Remove empty filters
        $queryArgs = array_filter($filters, function ($v) {
            return $v !== '' && $v !== 0;
        });

        $entries = $this->repository->find($queryArgs);
        $total = $this->repository->count($queryArgs);
        $totalPages = (int) ceil($total / $filters['per_page']);

        $purgeUrl = wp_nonce_url(
                admin_url('admin.php?page=' . self::MENU_SLUG . '&scrutiny_purge=1&scrutiny_purge_days=365'),
                self::NONCE_ACTION
        );

        // Get users for filter dropdown
        $auditUsers = $this->getAuditUsers();

        ?>
        <div class="wrap">
            <h1>
                Scrutiny – GDPR Audit Log
                <button type="button" class="page-title-action" onclick="location.reload();" title="Refresh the audit log" style="margin-left: 10px;">
                    <span class="dashicons dashicons-update" style="vertical-align: middle;"></span> Refresh
                </button>
                <a href="<?php echo esc_url(plugins_url('assets/docs/scrutiny.html', dirname(__DIR__, 2) . '/Scrutiny.php')); ?>" target="_blank" class="page-title-action" title="View Scrutiny documentation" style="margin-left: 5px;">
                    <span class="dashicons dashicons-editor-help" style="vertical-align: middle;"></span> Help
                </a>
            </h1>
            <p class="description">
                All access to and changes of personal member data are recorded here.
                Entries do not contain the actual personal data values — only the field name, member reference, and the identity of the user who performed the action.
            </p>

            <!-- Filters -->
            <form method="get" style="margin-bottom: 15px;">
                <input type="hidden" name="page" value="<?php echo esc_attr(self::MENU_SLUG); ?>">

                <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: end;">
                    <!-- Entity Type Filter -->
                    <label>
                        <span style="display:block; font-weight:600; margin-bottom:2px;">Entity Type</span>
                        <select name="entity_type">
                            <option value="">All Types</option>
                            <?php foreach (self::ENTITY_TYPES as $key => $label): ?>
                                <option value="<?php echo esc_attr($key); ?>" <?php selected($filters['entity_type'], $key); ?>>
                                    <?php echo esc_html($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <!-- Action Filter -->
                    <label>
                        <span style="display:block; font-weight:600; margin-bottom:2px;">Action</span>
                        <select name="filter_action">
                            <option value="">All Actions</option>
                            <?php foreach (self::ACTION_TYPES as $action): ?>
                                <option value="<?php echo esc_attr($action); ?>" <?php selected($filters['action'], $action); ?>>
                                    <?php echo esc_html(ucfirst($action)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <!-- Field Filter -->
                    <label>
                        <span style="display:block; font-weight:600; margin-bottom:2px;">Field</span>
                        <select name="field_name">
                            <option value="">All Fields</option>
                            <?php foreach (PersonalDataFields::LABELS as $key => $label): ?>
                                <option value="<?php echo esc_attr($key); ?>" <?php selected($filters['field_name'], $key); ?>>
                                    <?php echo esc_html($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <!-- User Filter -->
                    <label>
                        <span style="display:block; font-weight:600; margin-bottom:2px;">User</span>
                        <select name="user_id">
                            <option value="">All Users</option>
                            <?php foreach ($auditUsers as $user): ?>
                                <option value="<?php echo esc_attr((string) $user->user_id); ?>" <?php selected($filters['user_id'], (int) $user->user_id); ?>>
                                    <?php echo esc_html($user->user_login); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>

                <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: end; margin-top: 10px;">
                    <!-- Member ID Filter -->
                    <label>
                        <span style="display:block; font-weight:600; margin-bottom:2px;">Member ID</span>
                        <input type="number" name="entity_id" value="<?php echo esc_attr((string) $filters['entity_id']); ?>" min="0" style="width: 100px;" placeholder="e.g., 123">
                    </label>

                    <!-- Date From Filter -->
                    <label>
                        <span style="display:block; font-weight:600; margin-bottom:2px;">From Date</span>
                        <input type="date" name="date_from" value="<?php echo esc_attr($filters['date_from']); ?>">
                    </label>

                    <!-- Date To Filter -->
                    <label>
                        <span style="display:block; font-weight:600; margin-bottom:2px;">To Date</span>
                        <input type="date" name="date_to" value="<?php echo esc_attr($filters['date_to']); ?>">
                    </label>

                    <!-- Filter Buttons -->
                    <button type="submit" class="button button-primary">Apply Filters</button>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::MENU_SLUG)); ?>" class="button">Reset All</a>
                    <button type="button" class="button" onclick="location.reload();" title="Refresh the audit log">
                        <span class="dashicons dashicons-update" style="vertical-align: middle; margin-top: 3px;"></span> Refresh
                    </button>
                    <a href="<?php echo esc_url(plugins_url('assets/docs/scrutiny.html', dirname(__DIR__, 2) . '/Scrutiny.php')); ?>" target="_blank" class="button" title="View Scrutiny documentation">
                        <span class="dashicons dashicons-editor-help" style="vertical-align: middle; margin-top: 3px;"></span> Help
                    </a>
                </div>
            </form>

            <!-- Active Filters Summary -->
            <?php if (!empty(array_filter($queryArgs, function($k) { return $k !== 'per_page' && $k !== 'page'; }, ARRAY_FILTER_USE_KEY))): ?>
                <div style="margin-bottom: 15px; padding: 10px; background: #f0f0f1; border-left: 4px solid #2271b1;">
                    <strong>Active Filters:</strong>
                    <?php
                    $activeFilters = [];
                    if (!empty($filters['entity_type'])) {
                        $activeFilters[] = 'Entity: ' . (self::ENTITY_TYPES[$filters['entity_type']] ?? $filters['entity_type']);
                    }
                    if (!empty($filters['action'])) {
                        $activeFilters[] = 'Action: ' . ucfirst($filters['action']);
                    }
                    if (!empty($filters['field_name'])) {
                        $activeFilters[] = 'Field: ' . (PersonalDataFields::LABELS[$filters['field_name']] ?? $filters['field_name']);
                    }
                    if (!empty($filters['user_id'])) {
                        $userData = get_userdata($filters['user_id']);
                        $activeFilters[] = 'User: ' . ($userData ? $userData->user_login : "ID #{$filters['user_id']}");
                    }
                    if (!empty($filters['entity_id'])) {
                        $activeFilters[] = "Member ID: #{$filters['entity_id']}";
                    }
                    if (!empty($filters['date_from'])) {
                        $activeFilters[] = 'From: ' . $filters['date_from'];
                    }
                    if (!empty($filters['date_to'])) {
                        $activeFilters[] = 'To: ' . $filters['date_to'];
                    }
                    echo esc_html(implode(' • ', $activeFilters));
                    ?>
                </div>
            <?php endif; ?>

            <!-- Summary -->
            <p>
                <strong><?php echo esc_html((string) $total); ?></strong> entries found.
                <?php if ($totalPages > 1): ?>
                    Page <?php echo esc_html((string) $filters['page']); ?> of <?php echo esc_html((string) $totalPages); ?>.
                <?php endif; ?>
            </p>

            <!-- Table -->
            <table class="widefat striped">
                <thead>
                <tr>
                    <th>Date / Time (UTC)</th>
                    <th>User</th>
                    <th>Action</th>
                    <th>Entity Type</th>
                    <th>Field</th>
                    <th>Member ID</th>
                    <th>Detail</th>
                    <th>IP (truncated)</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($entries)): ?>
                    <tr><td colspan="8" style="text-align:center;">No entries found.</td></tr>
                <?php else: ?>
                    <?php foreach ($entries as $entry): ?>
                        <tr>
                            <td><?php echo esc_html($entry->logged_at); ?></td>
                            <td><?php echo esc_html($entry->user_login); ?> <small>(#<?php echo esc_html((string) $entry->user_id); ?>)</small></td>
                            <td>
                                    <span class="scrutiny-badge scrutiny-badge--<?php echo esc_attr($entry->action); ?>">
                                        <?php echo esc_html(ucfirst($entry->action)); ?>
                                    </span>
                            </td>
                            <td><?php echo esc_html(self::ENTITY_TYPES[$entry->entity_type] ?? $entry->entity_type); ?></td>
                            <td><?php echo esc_html(PersonalDataFields::LABELS[$entry->field_name] ?? $entry->field_name); ?></td>
                            <td>
                                <?php if ((int) $entry->entity_id > 0): ?>
                                    <a href="<?php echo esc_url(get_edit_post_link((int) $entry->entity_id) ?? '#'); ?>">
                                        #<?php echo esc_html((string) $entry->entity_id); ?>
                                    </a>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html($entry->detail); ?></td>
                            <td><code><?php echo esc_html($entry->ip_address); ?></code></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <?php
                        $baseUrl = admin_url('admin.php?page=' . self::MENU_SLUG);
                        foreach (['entity_type' => $filters['entity_type'], 'filter_action' => $filters['action'], 'field_name' => $filters['field_name'], 'user_id' => $filters['user_id'], 'entity_id' => $filters['entity_id'], 'date_from' => $filters['date_from'], 'date_to' => $filters['date_to']] as $key => $val) {
                            if ($val !== '' && $val !== 0) {
                                $baseUrl = add_query_arg($key, $val, $baseUrl);
                            }
                        }

                        for ($i = 1; $i <= $totalPages; $i++):
                            $url = add_query_arg('paged', $i, $baseUrl);
                            if ($i === $filters['page']): ?>
                                <strong>[<?php echo $i; ?>]</strong>
                            <?php else: ?>
                                <a href="<?php echo esc_url($url); ?>"><?php echo $i; ?></a>
                            <?php endif;
                        endfor; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Purge -->
            <hr>
            <h2>Data Retention</h2>
            <p>
                Under GDPR Article 5(1)(e), personal data audit logs should not be kept longer than necessary.
                Use the button below to purge entries older than 365 days.
            </p>
            <a href="<?php echo esc_url($purgeUrl); ?>" class="button" onclick="return confirm('Permanently delete audit entries older than 365 days?');">
                Purge entries older than 1 year
            </a>
        </div>

        <style>
            .scrutiny-badge {
                display: inline-block;
                padding: 2px 8px;
                border-radius: 3px;
                font-size: 12px;
                font-weight: 600;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }
            .scrutiny-badge--view   { background: #e8f4fd; color: #1d5d90; }
            .scrutiny-badge--create { background: #e6f9e6; color: #1a7a1a; }
            .scrutiny-badge--update { background: #fff3e0; color: #b45309; }
            .scrutiny-badge--delete { background: #fde8e8; color: #991b1b; }
            .scrutiny-badge--export { background: #f3e8fd; color: #6b21a8; }
            .scrutiny-badge--purge  { background: #f1f1f1; color: #555; }
        </style>
        <?php
    }
}