<?php

declare(strict_types=1);

namespace Scrutiny\Admin;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Scrutiny\Audit\Interfaces\AuditLogger;
use Scrutiny\Audit\Interfaces\AuditRepository;
use Scrutiny\Privacy\PersonalDataFields;
use function add_action;
use function add_submenu_page;
use function admin_url;
use function check_admin_referer;
use function current_user_can;
use function esc_attr;
use function esc_html;
use function esc_url;
use function get_option;
use function get_the_title;
use function get_userdata;
use function wp_date;
use function wp_nonce_url;
use function wp_timezone;

/**
 * Audit Log Admin Page
 *
 * Provides a read-only admin interface for viewing the GDPR audit trail.
 * Accessible under the Intergroup menu to administrators only.
 */
class AuditLogAdmin
{
    private AuditRepository $repository;
    private AuditLogger $logger;

    public const MENU_SLUG = 'scrutiny-audit-log';
    public const CAPABILITY = 'manage_options';
    public const NONCE_ACTION = 'scrutiny_purge_log';

    /**
     * Available action types for filtering
     */
    private const ACTION_TYPES = ['view', 'create', 'update', 'delete', 'export', 'import', 'call', 'message', 'purge'];

    /**
     * Available entity types for filtering
     */
    private const ENTITY_TYPES = [
            'member' => 'Member',
            'group' => 'Group',
            'meeting' => 'Meeting',
            'position' => 'Position',
            'user' => 'User',
            'audit_log' => 'Audit Log',
    ];

    public function __construct(AuditRepository $repository, AuditLogger $logger)
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
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix; cannot be parameterised with prepare()
                "SELECT DISTINCT user_id, user_login 
             FROM `" . esc_sql($table) . "` 
             ORDER BY user_login ASC"
        );

        return $users ?: [];
    }

    /**
     * Resolve a free-text Member filter to a list of matching post IDs.
     *
     * Performs a case-insensitive substring match against post_title
     * across all post types — the audit log records member, group,
     * meeting and position entities, all of which are CPTs whose post
     * title is the entity's display (anonymous) name.
     *
     * Excludes revisions and auto-drafts. Capped at 200 matches to keep
     * the resulting `IN (...)` clause bounded; the user should narrow
     * the query if their search is genuinely that broad.
     *
     * Returns [0] when nothing matches, so callers passing the result
     * straight into the repository's `entity_ids` argument get an empty
     * result set rather than silently disabling the filter.
     *
     * @return int[]
     */
    private static function findPostIdsByTitle(string $query): array
    {
        global $wpdb;

        if ($query === '') {
            return [0];
        }

        $like = '%' . $wpdb->esc_like($query) . '%';

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepare() used below
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM `{$wpdb->posts}`
             WHERE post_title LIKE %s
               AND post_type NOT IN ('revision', 'nav_menu_item')
               AND post_status NOT IN ('auto-draft', 'trash')
             LIMIT 200",
            $like
        ));

        $ids = array_map('intval', $ids ?: []);

        return $ids !== [] ? $ids : [0];
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
                'entity_type'  => sanitize_text_field($_GET['entity_type'] ?? ''),
                'entity_query' => trim(sanitize_text_field($_GET['entity_query'] ?? '')),
                'action'       => sanitize_text_field($_GET['filter_action'] ?? ''),
                'field_name'   => sanitize_text_field($_GET['field_name'] ?? ''),
                'user_id'      => (int) ($_GET['user_id'] ?? 0),
                'date_from'    => sanitize_text_field($_GET['date_from'] ?? ''),
                'date_to'      => sanitize_text_field($_GET['date_to'] ?? ''),
                'per_page'     => 50,
                'page'         => max((int) ($_GET['paged'] ?? 1), 1),
        ];

        // Resolve the Member filter. The input box accepts either a
        // numeric ID (exact match against entity_id) or a name fragment
        // (matched against post titles — i.e. the member's anonymous
        // name). A name match resolves to a set of post IDs passed via
        // `entity_ids`; if no posts match, the repository forces an
        // empty result rather than silently returning everything.
        $queryArgs = $filters;
        unset($queryArgs['entity_query']);
        if ($filters['entity_query'] !== '') {
            if (ctype_digit($filters['entity_query'])) {
                $queryArgs['entity_id'] = (int) $filters['entity_query'];
            } else {
                $queryArgs['entity_ids'] = self::findPostIdsByTitle($filters['entity_query']);
            }
        }

        // Remove empty filters
        $queryArgs = array_filter($queryArgs, function ($v) {
            return $v !== '' && $v !== 0 && $v !== [];
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
                Scrutiny – Audit Log
                <button type="button" class="page-title-action" onclick="location.reload();" title="Refresh the audit log" style="margin-left: 10px;">
                    <span class="dashicons dashicons-update" style="vertical-align: middle;"></span> Refresh
                </button>
                <a href="#" class="page-title-action" title="View Scrutiny documentation" style="margin-left: 5px;"
                   onclick="event.preventDefault(); window.name = 'scrutiny-admin'; window.open('<?php echo esc_js(plugins_url('assets/docs/scrutiny.html', dirname(__DIR__, 2) . '/scrutiny.php')); ?>' + '?back=' + encodeURIComponent(window.location.href), 'scrutiny-help');">
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
                    <!-- Member Filter (ID or anonymous name) -->
                    <label>
                        <span style="display:block; font-weight:600; margin-bottom:2px;">Member</span>
                        <input type="text" name="entity_query" value="<?php echo esc_attr($filters['entity_query']); ?>" style="width: 180px;" placeholder="ID or name (e.g. 123 or John D)">
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
                        $activeFilters[] = 'Field: ' . PersonalDataFields::getLabel($filters['field_name']);
                    }
                    if (!empty($filters['user_id'])) {
                        $userData = get_userdata($filters['user_id']);
                        $activeFilters[] = 'User: ' . ($userData ? $userData->user_login : "ID #{$filters['user_id']}");
                    }
                    if (!empty($filters['entity_query'])) {
                        $activeFilters[] = ctype_digit($filters['entity_query'])
                            ? "Member ID: #{$filters['entity_query']}"
                            : "Member: {$filters['entity_query']}";
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
                    <th>Date / Time (<?php echo esc_html(wp_date('T')); ?>)</th>
                    <th>User</th>
                    <th>Action</th>
                    <th>Entity Type</th>
                    <th>Field</th>
                    <th>Member</th>
                    <th>Detail</th>
                    <th>IP (truncated)</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($entries)): ?>
                    <tr><td colspan="8" style="text-align:center;">No entries found.</td></tr>
                <?php else: ?>
                    <?php foreach ($entries as $entry): ?>
                        <?php
                        // Timestamps are stored in UTC (see GdprAuditLogger::log()); convert to the
                        // site's configured timezone for display. Falls back to the raw value if the
                        // stored string cannot be parsed.
                        $loggedAtDisplay = $entry->logged_at;
                        try {
                            $utc = new \DateTimeImmutable($entry->logged_at, new \DateTimeZone('UTC'));
                            $local = $utc->setTimezone(wp_timezone());
                            $loggedAtDisplay = wp_date(
                                    get_option('date_format') . ' ' . get_option('time_format'),
                                    $local->getTimestamp()
                            );
                        } catch (\Exception $e) {
                            // Keep $entry->logged_at as-is if parsing fails.
                        }
                        ?>
                        <tr>
                            <td><?php echo esc_html($loggedAtDisplay); ?></td>
                            <td><?php echo esc_html($entry->user_login); ?> <small>(#<?php echo esc_html((string) $entry->user_id); ?>)</small></td>
                            <td>
                                    <span class="scrutiny-badge scrutiny-badge--<?php echo esc_attr($entry->action); ?>">
                                        <?php echo esc_html(ucfirst($entry->action)); ?>
                                    </span>
                            </td>
                            <td><?php echo esc_html(self::ENTITY_TYPES[$entry->entity_type] ?? $entry->entity_type); ?></td>
                            <td><?php echo esc_html(PersonalDataFields::getLabel($entry->field_name)); ?></td>
                            <td>
                                <?php if ((int) $entry->entity_id > 0): ?>
                                    <?php
                                    // The entity_id is a member (or other CPT) post ID; the post
                                    // title is the member's anonymous name (e.g. "John D."), which
                                    // is far more useful to administrators than a numeric ID.
                                    // Fall back to "#<id>" when no title is available — for example,
                                    // `user` rows where entity_id is a WP user ID, not a post ID.
                                    $entityTitle = get_the_title((int) $entry->entity_id);
                                    $entityLabel = $entityTitle !== ''
                                        ? $entityTitle
                                        : '#' . (string) $entry->entity_id;
                                    ?>
                                    <a href="<?php echo esc_url(get_edit_post_link((int) $entry->entity_id) ?? '#'); ?>">
                                        <?php echo esc_html($entityLabel); ?>
                                    </a>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td><?php echo self::renderDetailCell($entry); ?></td>
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
                        foreach (['entity_type' => $filters['entity_type'], 'filter_action' => $filters['action'], 'field_name' => $filters['field_name'], 'user_id' => $filters['user_id'], 'entity_query' => $filters['entity_query'], 'date_from' => $filters['date_from'], 'date_to' => $filters['date_to']] as $key => $val) {
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
            .scrutiny-badge--import { background: #e0f2fe; color: #0369a1; }
            .scrutiny-badge--call   { background: #fef3c7; color: #92400e; }
            .scrutiny-badge--purge  { background: #f1f1f1; color: #555; }
        </style>
        <?php
    }

    /**
     * Render the Detail column for a single audit row.
     *
     * Reach writes structured detail strings for both the view step
     * (when contact details are exposed in a nearest-members lookup)
     * and the call step (when the visitor logs a phone-call result):
     *
     *   View: `caller:<name>#<id>`          (or `caller:unknown`)
     *   Call: `caller:<name>#<id>;result:<label>`
     *                                       (or `caller:unknown;result:<label>`)
     *
     * View rows render as a linked "Requester: <name>"; call rows
     * render as "Caller: <name>   Result: <label>". The link points
     * to the requester/caller's *own* member edit page (the row's
     * entity_id already points to the member who was viewed / called,
     * and is linked separately in the Member ID column).
     *
     * Any other shape, or any other action, renders as escaped plain
     * text — preserving legacy rows and audit entries from other
     * plugins or earlier versions.
     */
    private static function renderDetailCell(object $entry): string
    {
        $detail = (string) ($entry->detail ?? '');
        $action = (string) ($entry->action ?? '');

        if ($action !== AuditLogger::ACTION_CALL
            && $action !== AuditLogger::ACTION_VIEW
        ) {
            return esc_html($detail);
        }

        $parts = self::parseCallerDetail($detail);
        if ($parts === null) {
            return esc_html($detail);
        }

        [$callerName, $callerId, $result] = $parts;

        // Caller fragment — link the name when we have a usable id.
        if ($callerId !== null) {
            $editUrl = get_edit_post_link($callerId);
            $callerHtml = $editUrl
                ? sprintf('<a href="%s">%s</a>', esc_url($editUrl), esc_html($callerName))
                : esc_html($callerName);
        } else {
            $callerHtml = esc_html($callerName);
        }

        // The same structured detail format is used for both view and
        // call rows, but the human label changes: a view exposes
        // contact data to a "requester" running a search, while a
        // call attempt is logged by the "caller" who placed the call.
        $personLabel = $action === AuditLogger::ACTION_CALL ? 'Caller' : 'Requester';

        if ($result === null) {
            return sprintf('%s: %s', $personLabel, $callerHtml);
        }

        return sprintf(
            '%s: %s &nbsp; Result: %s',
            $personLabel,
            $callerHtml,
            esc_html($result),
        );
    }

    /**
     * Parse a Reach audit-detail string into [name, id|null, result|null].
     *
     * Accepts either `caller:<name>#<id>` (view rows) or
     * `caller:<name>#<id>;result:<label>` (call rows), with the
     * `unknown` sentinel allowed in place of `<name>#<id>` in both
     * cases. Returns null when the string doesn't match, so the
     * caller can fall back to plain-text rendering.
     *
     * @return array{0:string,1:?int,2:?string}|null
     */
    private static function parseCallerDetail(string $detail): ?array
    {
        if (!str_starts_with($detail, 'caller:')) {
            return null;
        }

        $payload = substr($detail, strlen('caller:'));

        // Optional `;result:<label>` suffix.
        $result    = null;
        $semicolon = strpos($payload, ';result:');
        if ($semicolon !== false) {
            $result = substr($payload, $semicolon + strlen(';result:'));
            if ($result === '') {
                return null;
            }
            $payload = substr($payload, 0, $semicolon);
        }

        if ($payload === 'unknown') {
            return ['unknown', null, $result];
        }

        // Split on the *last* '#' so anonymous names containing '#'
        // (unusual but possible) don't poison the parse.
        $hashPos = strrpos($payload, '#');
        if ($hashPos === false) {
            return null;
        }

        $name   = substr($payload, 0, $hashPos);
        $idPart = substr($payload, $hashPos + 1);

        if ($name === '' || !ctype_digit($idPart) || (int) $idPart <= 0) {
            return null;
        }

        return [$name, (int) $idPart, $result];
    }
}