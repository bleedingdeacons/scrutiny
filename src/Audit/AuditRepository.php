<?php

declare(strict_types=1);

namespace Scrutiny\Audit;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Scrutiny\Audit\Interfaces\AuditRepositoryInterface;

/**
 * Audit Repository
 *
 * Handles database operations for the GDPR audit log.
 * Stores audit entries in a dedicated WordPress custom table.
 */
class AuditRepository implements AuditRepositoryInterface
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
            error_log('Scrutiny: Failed to insert audit log entry: ' . $wpdb->last_error);
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

        $sql = "SELECT * FROM {$table} {$whereClause} ORDER BY logged_at DESC LIMIT %d OFFSET %d";
        $values[] = $perPage;
        $values[] = $offset;

        if (!empty($values)) {
            $sql = $wpdb->prepare($sql, ...$values);
        }

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

        $sql = "SELECT COUNT(*) FROM {$table} {$whereClause}";

        if (!empty($values)) {
            $sql = $wpdb->prepare($sql, ...$values);
        }

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

        return (int) $wpdb->query(
            $wpdb->prepare("DELETE FROM {$table} WHERE logged_at < %s", $cutoff)
        );
    }
}
