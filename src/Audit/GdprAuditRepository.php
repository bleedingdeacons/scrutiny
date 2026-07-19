<?php

declare(strict_types=1);

namespace Scrutiny\Audit;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Scrutiny\Audit\Interfaces\AuditRepository;

/**
 * Audit Repository
 *
 * Handles database operations for the GDPR audit log.
 * Stores audit entries in a dedicated WordPress custom table.
 */
class GdprAuditRepository implements AuditRepository
{
    private const TABLE_NAME = 'scrutiny_audit_log';

    /**
     * Get the full table name including WordPress prefix
     *
     * @return string
     */
    private static function tableName(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_NAME;
    }

    /**
     * @inheritDoc
     */
    public function insert(array $entry): int|false
    {
        global $wpdb;

        $result = $wpdb->insert(
            self::tableName(),
            [
                'action'      => $entry['action'],
                'entity_type' => $entry['entity_type'],
                'entity_id'   => $entry['entity_id'],
                'field_name'  => $entry['field_name'],
                'detail'      => $entry['detail'] ?? '',
                'user_id'     => $entry['user_id'],
                'user_login'  => $entry['user_login'],
                'ip_address'  => $entry['ip_address'],
                'logged_at'   => $entry['logged_at'],
            ],
            ['%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s']
        );

        if ($result === false) {
            \Scrutiny\Plugin::logError('Scrutiny: Failed to insert audit log entry: ' . $wpdb->last_error);
            return false;
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * @inheritDoc
     */
    public function find(array $args = []): array
    {
        global $wpdb;

        $table = self::tableName();
        $where = [];
        $values = [];

        if (!empty($args['entity_type'])) {
            $where[] = 'entity_type = %s';
            $values[] = $args['entity_type'];
        }

        if (!empty($args['entity_id'])) {
            $where[] = 'entity_id = %d';
            $values[] = $args['entity_id'];
        }

        if (!empty($args['entity_ids']) && is_array($args['entity_ids'])) {
            // Coerce, drop zeros/non-positives, de-dupe. If nothing valid
            // is left, force an impossible match so the query returns no
            // rows (a name search with no matching posts must not silently
            // degrade to "all entries").
            $ids = array_values(array_unique(array_filter(
                array_map('intval', $args['entity_ids']),
                static fn(int $id): bool => $id > 0
            )));
            if (empty($ids)) {
                $ids = [0];
            }
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $where[] = "entity_id IN ({$placeholders})";
            foreach ($ids as $id) {
                $values[] = $id;
            }
        }

        if (!empty($args['action'])) {
            $where[] = 'action = %s';
            $values[] = $args['action'];
        }

        if (!empty($args['user_id'])) {
            $where[] = 'user_id = %d';
            $values[] = $args['user_id'];
        }

        if (!empty($args['field_name'])) {
            $where[] = 'field_name = %s';
            $values[] = $args['field_name'];
        }

        if (!empty($args['date_from'])) {
            $where[] = 'logged_at >= %s';
            $values[] = $args['date_from'];
        }

        if (!empty($args['date_to'])) {
            $where[] = 'logged_at <= %s';
            $values[] = $args['date_to'];
        }

        $whereClause = '';
        if (!empty($where)) {
            $whereClause = 'WHERE ' . implode(' AND ', $where);
        }

        $perPage = min((int) ($args['per_page'] ?? 50), 200);
        $page = max((int) ($args['page'] ?? 1), 1);
        $offset = ($page - 1) * $perPage;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix; cannot be parameterised with prepare()
        $sql = "SELECT * FROM `" . esc_sql($table) . "` {$whereClause} ORDER BY logged_at DESC LIMIT %d OFFSET %d";
        $values[] = $perPage;
        $values[] = $offset;

        // $values always holds at least the LIMIT and OFFSET params
        // appended above, so there is nothing to guard against.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $sql = $wpdb->prepare($sql, ...$values);

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return $wpdb->get_results($sql);
    }

    /**
     * @inheritDoc
     */
    public function count(array $args = []): int
    {
        global $wpdb;

        $table = self::tableName();
        $where = [];
        $values = [];

        if (!empty($args['entity_type'])) {
            $where[] = 'entity_type = %s';
            $values[] = $args['entity_type'];
        }

        if (!empty($args['entity_id'])) {
            $where[] = 'entity_id = %d';
            $values[] = $args['entity_id'];
        }

        if (!empty($args['entity_ids']) && is_array($args['entity_ids'])) {
            // Mirror find(): coerce/de-dupe and force [0] when empty so a
            // "no posts matched" name search counts zero, not everything.
            $ids = array_values(array_unique(array_filter(
                array_map('intval', $args['entity_ids']),
                static fn(int $id): bool => $id > 0
            )));
            if (empty($ids)) {
                $ids = [0];
            }
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $where[] = "entity_id IN ({$placeholders})";
            foreach ($ids as $id) {
                $values[] = $id;
            }
        }

        if (!empty($args['action'])) {
            $where[] = 'action = %s';
            $values[] = $args['action'];
        }

        if (!empty($args['user_id'])) {
            $where[] = 'user_id = %d';
            $values[] = $args['user_id'];
        }

        if (!empty($args['field_name'])) {
            $where[] = 'field_name = %s';
            $values[] = $args['field_name'];
        }

        if (!empty($args['date_from'])) {
            $where[] = 'logged_at >= %s';
            $values[] = $args['date_from'];
        }

        if (!empty($args['date_to'])) {
            $where[] = 'logged_at <= %s';
            $values[] = $args['date_to'];
        }

        $whereClause = '';
        if (!empty($where)) {
            $whereClause = 'WHERE ' . implode(' AND ', $where);
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix; cannot be parameterised with prepare()
        $sql = "SELECT COUNT(*) FROM `" . esc_sql($table) . "` {$whereClause}";

        if (!empty($values)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $sql = $wpdb->prepare($sql, ...$values);
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return (int) $wpdb->get_var($sql);
    }

    /**
     * @inheritDoc
     */
    public static function createTable(): void
    {
        global $wpdb;

        $table = self::tableName();
        $charsetCollate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            action VARCHAR(20) NOT NULL,
            entity_type VARCHAR(50) NOT NULL,
            entity_id BIGINT UNSIGNED NOT NULL,
            field_name VARCHAR(100) NOT NULL,
            detail VARCHAR(255) NOT NULL DEFAULT '',
            user_id BIGINT UNSIGNED NOT NULL,
            user_login VARCHAR(60) NOT NULL,
            ip_address VARCHAR(45) NOT NULL DEFAULT '',
            logged_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_entity (entity_type, entity_id),
            KEY idx_user (user_id),
            KEY idx_action (action),
            KEY idx_logged_at (logged_at),
            KEY idx_field (field_name)
        ) {$charsetCollate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * @inheritDoc
     */
    public function purge(int $days): int
    {
        global $wpdb;

        $table = self::tableName();
        $cutoff = gmdate('Y-m-d H:i:s', strtotime("-{$days} days"));

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix; cannot be parameterised with prepare()
        return (int) $wpdb->query(
            $wpdb->prepare("DELETE FROM `" . esc_sql($table) . "` WHERE logged_at < %s", $cutoff)
        );
    }
}