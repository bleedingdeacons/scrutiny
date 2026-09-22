<?php

declare(strict_types=1);

namespace Scrutiny\Tests\Unit\Audit;

use Scrutiny\Audit\GdprAuditRepository;

/*
 * Tests for GdprAuditRepository's SQL-building read/write paths.
 *
 * A recording $wpdb double captures the values passed to prepare() so the
 * WHERE-clause assembly, pagination and IN-list handling can be asserted
 * without a live database. createTable() is not exercised because it
 * require()s a WordPress core file absent from the unit environment.
 */

covers(GdprAuditRepository::class);

/**
 * @return array<string, mixed>
 */
function auditLogRow(): array
{
    return [
        'action'      => 'update',
        'entity_type' => 'member',
        'entity_id'   => 42,
        'field_name'  => 'personal-email',
        'user_id'     => 7,
        'user_login'  => 'admin',
        'ip_address'  => '127.0.0.1',
        'logged_at'   => '2026-07-01 10:00:00',
    ];
}

beforeEach(function () {
    $GLOBALS['scrutiny_test_log_entries'] = [];

    // esc_sql is a passthrough for the table name in these tests.

    $this->previousWpdb = $GLOBALS['wpdb'] ?? null;
    $this->wpdb = new class {
        public string $prefix = 'wp_';
        public int $insert_id = 0;
        public string $last_error = '';
        /** @var mixed */
        public $insertReturn = 1;
        /** @var array<int, mixed> */
        public array $getResultsReturn = [];
        /** @var int */
        public $getVarReturn = 0;
        /** @var int */
        public $queryReturn = 0;

        /** @var array{0: string, 1: array<string,mixed>}|null */
        public $lastInsert = null;
        /** @var array<int, mixed> */
        public array $lastPrepareValues = [];

        public function insert(string $table, array $data, array $formats)
        {
            $this->lastInsert = [$table, $data];
            return $this->insertReturn;
        }

        public function prepare(string $query, ...$values): string
        {
            $this->lastPrepareValues = $values;
            return $query;
        }

        /** @return array<int, mixed> */
        public function get_results(string $sql): array
        {
            return $this->getResultsReturn;
        }

        public function get_var(string $sql): int
        {
            return $this->getVarReturn;
        }

        public function query(string $sql): int
        {
            return $this->queryReturn;
        }
    };
    $GLOBALS['wpdb'] = $this->wpdb;

    $this->repository = new GdprAuditRepository();
});

afterEach(function () {
    $GLOBALS['wpdb'] = $this->previousWpdb;
});

// ─── insert ─────────────────────────────────────────────────────
describe('insert', function () {
    it('returns the new row id on success', function () {
        $this->wpdb->insertReturn = 1;
        $this->wpdb->insert_id = 555;

        expect($this->repository->insert(auditLogRow()))->toBe(555);

        [$table, $data] = $this->wpdb->lastInsert;

        expect($table)->toBe('wp_scrutiny_audit_log')
            ->and($data['entity_type'])->toBe('member')
            // detail defaults to '' when the entry omits it.
            ->and($data['detail'])->toBe('');
    });

    it('logs and returns false on failure', function () {
        $this->wpdb->insertReturn = false;
        $this->wpdb->last_error = 'db exploded';

        expect($this->repository->insert(auditLogRow()))->toBeFalse();

        $messages = array_column($GLOBALS['scrutiny_test_log_entries'], 'message');

        expect($messages)->not->toBeEmpty()
            ->and($messages[0])->toContain('db exploded');
    });
});

// ─── find ───────────────────────────────────────────────────────
describe('find', function () {
    it('appends the pagination params even with no filters', function () {
        $rows = [(object) ['id' => 1]];
        $this->wpdb->getResultsReturn = $rows;

        expect($this->repository->find())->toBe($rows)
            // Only LIMIT + OFFSET are appended: default per_page 50, page 1.
            ->and($this->wpdb->lastPrepareValues)->toBe([50, 0]);
    });

    it('builds the WHERE values in declaration order', function () {
        $this->repository->find([
            'entity_type' => 'member',
            'entity_id'   => 42,
            'action'      => 'update',
            'per_page'    => 10,
            'page'        => 3,
        ]);

        // entity_type, entity_id, action, then LIMIT, OFFSET ((3-1)*10=20).
        expect($this->wpdb->lastPrepareValues)->toBe(['member', 42, 'update', 10, 20]);
    });

    it('builds the user, field and date WHERE clauses', function () {
        // These four filters are the ones the declaration-order test above
        // does not set, so drive them here to cover their WHERE branches.
        $this->repository->find([
            'user_id'    => 7,
            'field_name' => 'personal-email',
            'date_from'  => '2026-01-01 00:00:00',
            'date_to'    => '2026-12-31 23:59:59',
        ]);

        // user_id, field_name, date_from, date_to, then LIMIT 50, OFFSET 0.
        expect($this->wpdb->lastPrepareValues)
            ->toBe([7, 'personal-email', '2026-01-01 00:00:00', '2026-12-31 23:59:59', 50, 0]);
    });

    it('caps per_page at two hundred', function () {
        $this->repository->find(['per_page' => 5000]);

        expect($this->wpdb->lastPrepareValues)->toBe([200, 0]);
    });

    it('expands entity_ids into an IN list', function () {
        // Duplicates and non-positives are dropped and de-duped.
        $this->repository->find(['entity_ids' => [5, 5, 0, -3, 8]]);

        expect($this->wpdb->lastPrepareValues)->toBe([5, 8, 50, 0]);
    });

    it('forces an impossible id when no valid entity_ids remain', function () {
        // A name search that matched no posts must return nothing, not
        // everything — the IN list collapses to (0).
        $this->repository->find(['entity_ids' => [0, -1]]);

        expect($this->wpdb->lastPrepareValues)->toBe([0, 50, 0]);
    });
});

// ─── count ──────────────────────────────────────────────────────
describe('count', function () {
    it('skips prepare with no filters', function () {
        $this->wpdb->getVarReturn = 12;

        expect($this->repository->count())->toBe(12)
            // No WHERE values → prepare() is never called, so nothing recorded.
            ->and($this->wpdb->lastPrepareValues)->toBe([]);
    });

    it('builds the WHERE values for the supplied filters', function () {
        $this->wpdb->getVarReturn = 3;

        expect($this->repository->count([
            'field_name' => 'personal-email',
            'user_id'    => 7,
            'date_from'  => '2026-01-01 00:00:00',
            'date_to'    => '2026-12-31 23:59:59',
        ]))->toBe(3)
            ->and($this->wpdb->lastPrepareValues)
            ->toBe([7, 'personal-email', '2026-01-01 00:00:00', '2026-12-31 23:59:59']);
    });

    it('builds the entity and action WHERE clauses', function () {
        $this->wpdb->getVarReturn = 4;

        expect($this->repository->count([
            'entity_type' => 'member',
            'entity_id'   => 42,
            'action'      => 'update',
        ]))->toBe(4)
            ->and($this->wpdb->lastPrepareValues)->toBe(['member', 42, 'update']);
    });

    it('expands entity_ids into an IN list', function () {
        $this->wpdb->getVarReturn = 2;

        // Duplicates and non-positives are dropped and de-duped, mirroring find().
        $this->repository->count(['entity_ids' => [5, 5, 0, -3, 8]]);

        expect($this->wpdb->lastPrepareValues)->toBe([5, 8]);
    });

    it('forces an impossible id when no valid entity_ids remain', function () {
        $this->repository->count(['entity_ids' => [0, -1]]);

        expect($this->wpdb->lastPrepareValues)->toBe([0]);
    });
});

// ─── purge ──────────────────────────────────────────────────────
describe('purge', function () {
    it('deletes rows older than the cutoff', function () {
        $this->wpdb->queryReturn = 9;

        expect($this->repository->purge(30))->toBe(9)
            // The prepared cutoff is a single datetime string.
            ->and($this->wpdb->lastPrepareValues)->toHaveCount(1)
            ->and($this->wpdb->lastPrepareValues[0])->toMatch('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/');
    });
});
