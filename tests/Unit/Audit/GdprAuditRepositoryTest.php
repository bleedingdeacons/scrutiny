<?php

declare(strict_types=1);

namespace Scrutiny\Tests\Unit\Audit;

use PHPUnit\Framework\TestCase;
use Scrutiny\Audit\GdprAuditRepository;
use WP_Mock;

/**
 * Tests for GdprAuditRepository's SQL-building read/write paths.
 *
 * A recording $wpdb double captures the values passed to prepare() so the
 * WHERE-clause assembly, pagination and IN-list handling can be asserted
 * without a live database. createTable() is not exercised because it
 * require()s a WordPress core file absent from the unit environment.
 *
 * @covers \Scrutiny\Audit\GdprAuditRepository
 */
class GdprAuditRepositoryTest extends TestCase
{
    /** @var object The previous global $wpdb, restored in tearDown. */
    private $previousWpdb;

    /** @var object The recording double installed as $wpdb. */
    private $wpdb;

    private GdprAuditRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();
        $GLOBALS['scrutiny_test_log_entries'] = [];

        // esc_sql is a passthrough for the table name in these tests.
        WP_Mock::userFunction('esc_sql')->andReturnUsing(fn ($v) => $v);

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
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = $this->previousWpdb;
        WP_Mock::tearDown();
        parent::tearDown();
    }

    private function entry(): array
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

    // ─── insert ─────────────────────────────────────────────────────

    /**
     * @test
     */
    public function insert_returns_the_new_row_id_on_success(): void
    {
        $this->wpdb->insertReturn = 1;
        $this->wpdb->insert_id = 555;

        $this->assertSame(555, $this->repository->insert($this->entry()));

        [$table, $data] = $this->wpdb->lastInsert;
        $this->assertSame('wp_scrutiny_audit_log', $table);
        $this->assertSame('member', $data['entity_type']);
        // detail defaults to '' when the entry omits it.
        $this->assertSame('', $data['detail']);
    }

    /**
     * @test
     */
    public function insert_logs_and_returns_false_on_failure(): void
    {
        $this->wpdb->insertReturn = false;
        $this->wpdb->last_error = 'db exploded';

        $this->assertFalse($this->repository->insert($this->entry()));

        $messages = array_column($GLOBALS['scrutiny_test_log_entries'], 'message');
        $this->assertNotEmpty($messages);
        $this->assertStringContainsString('db exploded', $messages[0]);
    }

    // ─── find ───────────────────────────────────────────────────────

    /**
     * @test
     */
    public function find_appends_pagination_params_even_with_no_filters(): void
    {
        $rows = [(object) ['id' => 1]];
        $this->wpdb->getResultsReturn = $rows;

        $this->assertSame($rows, $this->repository->find());

        // Only LIMIT + OFFSET are appended: default per_page 50, page 1.
        $this->assertSame([50, 0], $this->wpdb->lastPrepareValues);
    }

    /**
     * @test
     */
    public function find_builds_where_values_in_declaration_order(): void
    {
        $this->repository->find([
            'entity_type' => 'member',
            'entity_id'   => 42,
            'action'      => 'update',
            'per_page'    => 10,
            'page'        => 3,
        ]);

        // entity_type, entity_id, action, then LIMIT, OFFSET ((3-1)*10=20).
        $this->assertSame(['member', 42, 'update', 10, 20], $this->wpdb->lastPrepareValues);
    }

    /**
     * @test
     */
    public function find_caps_per_page_at_two_hundred(): void
    {
        $this->repository->find(['per_page' => 5000]);

        $this->assertSame([200, 0], $this->wpdb->lastPrepareValues);
    }

    /**
     * @test
     */
    public function find_expands_entity_ids_into_an_in_list(): void
    {
        // Duplicates and non-positives are dropped and de-duped.
        $this->repository->find(['entity_ids' => [5, 5, 0, -3, 8]]);

        $this->assertSame([5, 8, 50, 0], $this->wpdb->lastPrepareValues);
    }

    /**
     * @test
     */
    public function find_forces_an_impossible_id_when_no_valid_entity_ids_remain(): void
    {
        // A name search that matched no posts must return nothing, not
        // everything — the IN list collapses to (0).
        $this->repository->find(['entity_ids' => [0, -1]]);

        $this->assertSame([0, 50, 0], $this->wpdb->lastPrepareValues);
    }

    // ─── count ──────────────────────────────────────────────────────

    /**
     * @test
     */
    public function count_with_no_filters_skips_prepare(): void
    {
        $this->wpdb->getVarReturn = 12;

        $this->assertSame(12, $this->repository->count());

        // No WHERE values → prepare() is never called, so nothing recorded.
        $this->assertSame([], $this->wpdb->lastPrepareValues);
    }

    /**
     * @test
     */
    public function count_builds_where_values_for_supplied_filters(): void
    {
        $this->wpdb->getVarReturn = 3;

        $this->assertSame(3, $this->repository->count([
            'field_name' => 'personal-email',
            'user_id'    => 7,
            'date_from'  => '2026-01-01 00:00:00',
            'date_to'    => '2026-12-31 23:59:59',
        ]));

        $this->assertSame(
            [7, 'personal-email', '2026-01-01 00:00:00', '2026-12-31 23:59:59'],
            $this->wpdb->lastPrepareValues
        );
    }

    /**
     * @test
     */
    public function find_builds_the_user_field_and_date_where_clauses(): void
    {
        // These four filters are the ones the declaration-order test above
        // does not set, so drive them here to cover their WHERE branches.
        $this->repository->find([
            'user_id'    => 7,
            'field_name' => 'personal-email',
            'date_from'  => '2026-01-01 00:00:00',
            'date_to'    => '2026-12-31 23:59:59',
        ]);

        // user_id, field_name, date_from, date_to, then LIMIT 50, OFFSET 0.
        $this->assertSame(
            [7, 'personal-email', '2026-01-01 00:00:00', '2026-12-31 23:59:59', 50, 0],
            $this->wpdb->lastPrepareValues,
        );
    }

    /**
     * @test
     */
    public function count_builds_the_entity_and_action_where_clauses(): void
    {
        $this->wpdb->getVarReturn = 4;

        $this->assertSame(4, $this->repository->count([
            'entity_type' => 'member',
            'entity_id'   => 42,
            'action'      => 'update',
        ]));

        $this->assertSame(['member', 42, 'update'], $this->wpdb->lastPrepareValues);
    }

    /**
     * @test
     */
    public function count_expands_entity_ids_into_an_in_list(): void
    {
        $this->wpdb->getVarReturn = 2;

        // Duplicates and non-positives are dropped and de-duped, mirroring find().
        $this->repository->count(['entity_ids' => [5, 5, 0, -3, 8]]);

        $this->assertSame([5, 8], $this->wpdb->lastPrepareValues);
    }

    /**
     * @test
     */
    public function count_forces_an_impossible_id_when_no_valid_entity_ids_remain(): void
    {
        $this->repository->count(['entity_ids' => [0, -1]]);

        $this->assertSame([0], $this->wpdb->lastPrepareValues);
    }

    // ─── purge ──────────────────────────────────────────────────────

    /**
     * @test
     */
    public function purge_deletes_rows_older_than_the_cutoff(): void
    {
        $this->wpdb->queryReturn = 9;

        $this->assertSame(9, $this->repository->purge(30));

        // The prepared cutoff is a single datetime string.
        $this->assertCount(1, $this->wpdb->lastPrepareValues);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            $this->wpdb->lastPrepareValues[0]
        );
    }
}
