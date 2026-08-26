<?php

declare(strict_types=1);

namespace Scrutiny\Fields;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Scrutiny\Admin\AuditLogAdmin;
use Scrutiny\Audit\AuditDetail;
use Scrutiny\Audit\AuditTimestamp;
use Scrutiny\Audit\Interfaces\AuditRepository;
use Scrutiny\Privacy\PersonalDataFields;
use function add_query_arg;
use function admin_url;
use function apply_filters;
use function current_user_can;
use function esc_attr;
use function esc_html;
use function esc_html__;
use function esc_url;
use function get_the_title;

/**
 * Audit History Renderer
 *
 * Renders the audit trail Scrutiny holds for a single record as an HTML
 * table. This is the whole of the GdprAuditHistory ACF field's behaviour;
 * {@see GdprAuditHistory} is a thin adapter that reads ACF's field settings
 * and hands them here, which keeps the query, the capability gate and the
 * markup testable without ACF on the classpath.
 *
 * The output is fully escaped — callers echo it as-is.
 *
 * Capability gate: the same audit data is otherwise only reachable through
 * {@see AuditLogAdmin}, which requires `manage_options`, so that is the
 * default here too. Sites that delegate member administration more widely
 * can lower it with the `scrutiny_audit_history_capability` filter:
 *
 *     add_filter('scrutiny_audit_history_capability', fn() => 'edit_others_posts');
 */
final class AuditHistoryRenderer
{
    /**
     * Default entity type. Members are what this field exists for; the
     * setting is there because the audit log records groups, meetings and
     * positions against post IDs in exactly the same way.
     */
    public const DEFAULT_ENTITY_TYPE = 'member';

    /**
     * Default number of entries shown.
     */
    public const DEFAULT_MAX_ENTRIES = 20;

    /**
     * Ceiling on the number of entries shown.
     *
     * Matches the cap GdprAuditRepository::find() applies to `per_page`:
     * asking for more silently returns 200, so the field's own limit says so
     * rather than promising a page size it cannot deliver.
     */
    public const MAX_ENTRIES_CEILING = 200;

    /**
     * Filter name for the capability required to see the history.
     *
     * Receives the default capability and the entity ID being rendered.
     */
    public const CAPABILITY_FILTER = 'scrutiny_audit_history_capability';

    private AuditRepository $repository;

    public function __construct(AuditRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Render the audit history for a single record.
     *
     * @param int $entityId The post ID of the record being edited. Zero (an
     *                      unsaved post) renders an explanatory notice rather
     *                      than an empty table.
     * @param array{
     *     entity_type?: string,
     *     max_entries?: int,
     *     action?: string,
     *     show_ip?: bool
     * } $options Field settings.
     * @return string Escaped HTML.
     */
    public function render(int $entityId, array $options = []): string
    {
        $capability = (string) apply_filters(
            self::CAPABILITY_FILTER,
            AuditLogAdmin::CAPABILITY,
            $entityId
        );

        if (!current_user_can($capability)) {
            return $this->notice(
                esc_html__('You do not have permission to view the audit history for this record.', 'scrutiny')
            );
        }

        if ($entityId <= 0) {
            return $this->notice(
                esc_html__('The audit history appears once this record has been saved.', 'scrutiny')
            );
        }

        $entityType = trim((string) ($options['entity_type'] ?? self::DEFAULT_ENTITY_TYPE));
        if ($entityType === '') {
            $entityType = self::DEFAULT_ENTITY_TYPE;
        }

        $maxEntries = (int) ($options['max_entries'] ?? self::DEFAULT_MAX_ENTRIES);
        $maxEntries = max(1, min($maxEntries, self::MAX_ENTRIES_CEILING));

        $action = trim((string) ($options['action'] ?? ''));
        $showIp = (bool) ($options['show_ip'] ?? false);

        $criteria = [
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
        ];
        if ($action !== '') {
            $criteria['action'] = $action;
        }

        // Counted before the page is fetched, and with the same criteria, so
        // "showing N of M" reports the real total rather than the page size.
        $total   = $this->repository->count($criteria);
        $entries = $this->repository->find($criteria + ['per_page' => $maxEntries, 'page' => 1]);

        if ($entries === []) {
            return $this->notice(
                esc_html__('No audit entries have been recorded for this record.', 'scrutiny')
            );
        }

        return '<div class="scrutiny-audit-history">'
            . $this->table($entries, $showIp)
            . $this->footer($entityType, $entityId, count($entries), $total)
            . '</div>';
    }

    /**
     * Render the entries table.
     *
     * @param array<int, object> $entries
     * @return string
     */
    private function table(array $entries, bool $showIp): string
    {
        $html  = '<table class="widefat striped scrutiny-audit-history__table">';
        $html .= '<thead><tr>';
        $html .= '<th scope="col">' . esc_html__('Date / Time', 'scrutiny') . '</th>';
        $html .= '<th scope="col">' . esc_html__('User', 'scrutiny') . '</th>';
        $html .= '<th scope="col">' . esc_html__('Action', 'scrutiny') . '</th>';
        $html .= '<th scope="col">' . esc_html__('Field', 'scrutiny') . '</th>';
        $html .= '<th scope="col">' . esc_html__('Detail', 'scrutiny') . '</th>';
        if ($showIp) {
            $html .= '<th scope="col">' . esc_html__('IP', 'scrutiny') . '</th>';
        }
        $html .= '</tr></thead><tbody>';

        foreach ($entries as $entry) {
            $action = (string) ($entry->action ?? '');

            $html .= '<tr>';
            $html .= '<td>' . esc_html(AuditTimestamp::forDisplay((string) ($entry->logged_at ?? ''))) . '</td>';
            $html .= '<td>' . esc_html((string) ($entry->user_login ?? '')) . '</td>';
            $html .= '<td><span class="scrutiny-badge scrutiny-badge--' . esc_attr($action) . '">'
                . esc_html(ucfirst($action)) . '</span></td>';
            $html .= '<td>' . esc_html(PersonalDataFields::getLabel((string) ($entry->field_name ?? ''))) . '</td>';
            // AuditDetail returns escaped HTML — it links the requester or
            // caller named in Reach's structured detail strings.
            $html .= '<td>' . AuditDetail::render($entry) . '</td>';
            if ($showIp) {
                $html .= '<td><code>' . esc_html((string) ($entry->ip_address ?? '')) . '</code></td>';
            }
            $html .= '</tr>';
        }

        return $html . '</tbody></table>';
    }

    /**
     * Render the "showing N of M" line, with a link through to the full log
     * for users who can reach the Audit Log page.
     */
    private function footer(string $entityType, int $entityId, int $shown, int $total): string
    {
        $summary = sprintf(
            /* translators: 1: number of entries shown, 2: total number of entries. */
            esc_html__('Showing the %1$d most recent of %2$d entries.', 'scrutiny'),
            $shown,
            $total
        );

        $html = '<p class="scrutiny-audit-history__summary description">' . $summary;

        if (current_user_can(AuditLogAdmin::CAPABILITY)) {
            $html .= ' <a href="' . esc_url($this->fullLogUrl($entityType, $entityId)) . '">'
                . esc_html__('View the full audit log', 'scrutiny') . '</a>';
        }

        return $html . '</p>';
    }

    /**
     * Build a link to the Audit Log page pre-filtered to this record.
     *
     * The admin page filters by entity type plus a free-text title search
     * (there is no by-ID filter), so the record's own title is used as the
     * query. It resolves back to this record and any namesakes, which is
     * close enough for a "see more" link, and degrades to the entity-type
     * filter alone when the post has no title yet.
     */
    private function fullLogUrl(string $entityType, int $entityId): string
    {
        $args = ['page' => AuditLogAdmin::MENU_SLUG, 'entity_type' => $entityType];

        $title = (string) get_the_title($entityId);
        if ($title !== '') {
            $args['entity_query'] = $title;
        }

        return (string) add_query_arg($args, admin_url('admin.php'));
    }

    /**
     * Wrap an already-escaped message in the field's notice markup.
     */
    private function notice(string $escapedMessage): string
    {
        return '<div class="scrutiny-audit-history">'
            . '<p class="scrutiny-audit-history__empty description">' . $escapedMessage . '</p>'
            . '</div>';
    }
}
