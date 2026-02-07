<?php

declare(strict_types=1);

namespace Scrutiny\Audit\Interfaces;

/**
 * Audit Repository Interface
 *
 * Defines the contract for storing and retrieving audit log entries.
 */
interface AuditRepositoryInterface
{
    /**
     * Insert a new audit log entry
     *
     * @param array{
     *     action: string,
     *     entity_type: string,
     *     entity_id: int,
     *     field_name: string,
     *     detail: string,
     *     user_id: int,
     *     user_login: string,
     *     ip_address: string,
     *     logged_at: string
     * } $entry The audit log entry data
     * @return int|false The inserted row ID, or false on failure
     */
    public function insert(array $entry): int|false;

    /**
     * Find audit log entries matching the given criteria
     *
     * @param array{
     *     entity_type?: string,
     *     entity_id?: int,
     *     action?: string,
     *     user_id?: int,
     *     field_name?: string,
     *     date_from?: string,
     *     date_to?: string,
     *     per_page?: int,
     *     page?: int
     * } $args Query arguments
     * @return array Array of audit log entry objects
     */
    public function find(array $args = []): array;

    /**
     * Count audit log entries matching the given criteria
     *
     * @param array $args Query arguments (same as find, excluding pagination)
     * @return int Total count
     */
    public function count(array $args = []): int;

    /**
     * Create the audit log database table
     *
     * @return void
     */
    public static function createTable(): void;

    /**
     * Purge audit log entries older than the specified number of days
     *
     * @param int $days Number of days to retain
     * @return int Number of rows deleted
     */
    public function purge(int $days): int;
}
