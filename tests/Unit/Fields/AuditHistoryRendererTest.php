<?php

declare(strict_types=1);

namespace Scrutiny\Tests\Unit\Fields;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Scrutiny\Admin\AuditLogAdmin;
use Scrutiny\Audit\Interfaces\AuditRepository;
use Scrutiny\Fields\AuditHistoryRenderer;
use Scrutiny\Privacy\PersonalDataFields;
use Scrutiny\Tests\TestCase;
use stdClass;

/**
 * Tests for the AuditHistoryRenderer — the whole behaviour of the
 * GdprAuditHistory ACF field, minus ACF.
 *
 * Coverage focuses on the things that would quietly leak or mislead:
 *
 *   - The capability gate, including the filter that relaxes it, and that a
 *     refused render never reaches the repository at all.
 *   - The criteria handed to the repository, since a dropped `entity_id`
 *     would show one member's trail on another member's screen.
 *   - The count/page split behind "showing N of M".
 *   - Escaping, on every column that carries stored text.
 *
 * @covers \Scrutiny\Fields\AuditHistoryRenderer
 */
class AuditHistoryRendererTest extends TestCase
{
    /** @var AuditRepository&\PHPUnit\Framework\MockObject\MockObject */
    private $repository;

    private AuditHistoryRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['scrutiny_test_capabilities'] = [AuditLogAdmin::CAPABILITY => true];

        $this->repository = $this->createMock(AuditRepository::class);
        $this->renderer   = new AuditHistoryRenderer($this->repository);
    }

    protected function tearDown(): void
    {
        $GLOBALS['scrutiny_test_capabilities'] = [];
        parent::tearDown();
    }

    /**
     * Build an audit row in the shape $wpdb->get_results() returns — every
     * column a string, none of them null.
     *
     * @param array<string, string> $overrides
     */
    private function entry(array $overrides = []): stdClass
    {
        $columns = array_merge([
            'id'          => '1',
            'action'      => 'update',
            'entity_type' => 'member',
            'entity_id'   => '42',
            'field_name'  => PersonalDataFields::MOBILE_NUMBER,
            'detail'      => 'Changed',
            'user_id'     => '7',
            'user_login'  => 'admin',
            'ip_address'  => '203.0.113.0',
            'logged_at'   => '2026-03-01 09:30:00',
        ], $overrides);

        $entry = new stdClass();
        foreach ($columns as $key => $value) {
            $entry->{$key} = $value;
        }

        return $entry;
    }

    /**
     * @param array<int, stdClass> $entries
     */
    private function expectEntries(array $entries, ?int $total = null): void
    {
        $this->repository->method('find')->willReturn($entries);
        $this->repository->method('count')->willReturn($total ?? count($entries));
    }

    // ──────────────────────────────────────────────
    //  Capability gate
    // ──────────────────────────────────────────────

    /** @test */
    public function it_refuses_to_render_without_the_capability(): void
    {
        $GLOBALS['scrutiny_test_capabilities'] = [];

        // The gate has to come before the query, not after it — otherwise the
        // audit trail is read for a user who is never shown it.
        $this->repository->expects($this->never())->method('find');
        $this->repository->expects($this->never())->method('count');

        $html = $this->renderer->render(42);

        $this->assertStringContainsString('do not have permission', $html);
        $this->assertStringNotContainsString('<table', $html);
    }

    /** @test */
    public function it_honours_a_capability_lowered_by_the_filter(): void
    {
        $GLOBALS['scrutiny_test_capabilities'] = ['edit_others_posts' => true];

        Filters\expectApplied(AuditHistoryRenderer::CAPABILITY_FILTER)
            ->once()
            ->andReturn('edit_others_posts');

        $this->expectEntries([$this->entry()]);

        $this->assertStringContainsString('<table', $this->renderer->render(42));
    }

    // ──────────────────────────────────────────────
    //  Empty states
    // ──────────────────────────────────────────────

    /** @test */
    public function it_explains_itself_on_an_unsaved_record(): void
    {
        // entity_id 0 is what an unsaved post resolves to. Querying it would
        // match nothing anyway, but the message is the point: an empty table
        // on a new member reads like "nobody has ever touched this".
        $this->repository->expects($this->never())->method('find');

        $html = $this->renderer->render(0);

        $this->assertStringContainsString('once this record has been saved', $html);
    }

    /** @test */
    public function it_says_so_when_the_record_has_no_entries(): void
    {
        $this->expectEntries([]);

        $html = $this->renderer->render(42);

        $this->assertStringContainsString('No audit entries', $html);
        $this->assertStringNotContainsString('<table', $html);
    }

    // ──────────────────────────────────────────────
    //  Query criteria
    // ──────────────────────────────────────────────

    /** @test */
    public function it_queries_the_record_it_was_asked_for(): void
    {
        $captured = null;

        $this->repository->expects($this->once())
            ->method('find')
            ->willReturnCallback(function (array $args) use (&$captured): array {
                $captured = $args;
                return [];
            });
        $this->repository->method('count')->willReturn(0);

        $this->renderer->render(42, ['entity_type' => 'group', 'max_entries' => 5]);

        $this->assertSame('group', $captured['entity_type']);
        $this->assertSame(42, $captured['entity_id']);
        $this->assertSame(5, $captured['per_page']);
        $this->assertSame(1, $captured['page']);
        // No action setting means no action filter — not action ''.
        $this->assertArrayNotHasKey('action', $captured);
    }

    /** @test */
    public function it_passes_a_chosen_action_through_as_a_filter(): void
    {
        $captured = null;

        $this->repository->method('find')
            ->willReturnCallback(function (array $args) use (&$captured): array {
                $captured = $args;
                return [];
            });
        $this->repository->method('count')->willReturn(0);

        $this->renderer->render(42, ['action' => 'view']);

        $this->assertSame('view', $captured['action']);
    }

    /** @test */
    public function it_falls_back_to_the_member_entity_type(): void
    {
        $captured = null;

        $this->repository->method('find')
            ->willReturnCallback(function (array $args) use (&$captured): array {
                $captured = $args;
                return [];
            });
        $this->repository->method('count')->willReturn(0);

        $this->renderer->render(42, ['entity_type' => '   ']);

        $this->assertSame(AuditHistoryRenderer::DEFAULT_ENTITY_TYPE, $captured['entity_type']);
    }

    /**
     * @test
     * @dataProvider clampedEntryCounts
     */
    public function it_clamps_the_page_size_to_what_the_repository_will_serve(int $asked, int $expected): void
    {
        $captured = null;

        $this->repository->method('find')
            ->willReturnCallback(function (array $args) use (&$captured): array {
                $captured = $args;
                return [];
            });
        $this->repository->method('count')->willReturn(0);

        $this->renderer->render(42, ['max_entries' => $asked]);

        $this->assertSame($expected, $captured['per_page']);
    }

    /**
     * GdprAuditRepository::find() silently caps per_page at 200, so asking for
     * more would promise a page size it never delivers. Zero and negatives
     * would produce a nonsensical LIMIT.
     *
     * @return array<string, array{0:int,1:int}>
     */
    public static function clampedEntryCounts(): array
    {
        return [
            'above the ceiling' => [5000, AuditHistoryRenderer::MAX_ENTRIES_CEILING],
            'at the ceiling'    => [200, 200],
            'zero'              => [0, 1],
            'negative'          => [-10, 1],
            'ordinary'          => [15, 15],
        ];
    }

    // ──────────────────────────────────────────────
    //  Table output
    // ──────────────────────────────────────────────

    /** @test */
    public function it_renders_a_row_per_entry(): void
    {
        $this->expectEntries([
            $this->entry(['user_login' => 'alice', 'action' => 'view']),
            $this->entry(['user_login' => 'bob', 'action' => 'update']),
        ]);

        $html = $this->renderer->render(42);

        $this->assertStringContainsString('alice', $html);
        $this->assertStringContainsString('bob', $html);
        // One badge per body row (the header row has none).
        $this->assertSame(2, substr_count($html, 'class="scrutiny-badge'));
        // Action badges carry the action in the modifier class, which the
        // stylesheet colours by.
        $this->assertStringContainsString('scrutiny-badge--view', $html);
        $this->assertStringContainsString('scrutiny-badge--update', $html);
    }

    /** @test */
    public function it_labels_the_field_rather_than_naming_the_meta_key(): void
    {
        $this->expectEntries([$this->entry(['field_name' => PersonalDataFields::PERSONAL_EMAIL])]);

        $this->assertStringContainsString('Personal Email', $this->renderer->render(42));
    }

    /** @test */
    public function it_hides_ip_addresses_unless_asked_for_them(): void
    {
        $this->expectEntries([$this->entry(['ip_address' => '203.0.113.0'])]);

        $this->assertStringNotContainsString('203.0.113.0', $this->renderer->render(42));
    }

    /** @test */
    public function it_shows_ip_addresses_when_the_setting_is_on(): void
    {
        $this->expectEntries([$this->entry(['ip_address' => '203.0.113.0'])]);

        $this->assertStringContainsString('203.0.113.0', $this->renderer->render(42, ['show_ip' => true]));
    }

    /** @test */
    public function it_renders_reach_caller_details_as_a_named_requester(): void
    {
        // Reach's structured detail strings are the reason this field exists
        // on a member: they record who was shown that member's contact
        // details. Raw `caller:John D.#7` would be unreadable.
        Functions\when('get_edit_post_link')->justReturn('https://example.test/edit');

        $this->expectEntries([
            $this->entry(['action' => 'view', 'detail' => 'caller:John D.#7']),
        ]);

        $html = $this->renderer->render(42);

        $this->assertStringContainsString('Requester: ', $html);
        $this->assertStringContainsString('John D.', $html);
    }

    // ──────────────────────────────────────────────
    //  Summary and full-log link
    // ──────────────────────────────────────────────

    /** @test */
    public function it_reports_the_full_total_not_the_page_size(): void
    {
        // count() is asked separately for exactly this reason: a member with
        // hundreds of view entries must not look like they have two.
        $this->expectEntries([$this->entry(), $this->entry()], 137);

        $html = $this->renderer->render(42, ['max_entries' => 2]);

        $this->assertStringContainsString('2 most recent of 137', $html);
    }

    /** @test */
    public function it_links_to_the_full_log_filtered_to_this_record(): void
    {
        Functions\when('get_the_title')->justReturn('John D.');
        $this->expectEntries([$this->entry()]);

        $html = $this->renderer->render(42);

        $this->assertStringContainsString(AuditLogAdmin::MENU_SLUG, $html);
        $this->assertStringContainsString('entity_query=John', $html);
        $this->assertStringContainsString('View the full audit log', $html);
    }

    /** @test */
    public function it_omits_the_link_for_users_who_cannot_open_the_audit_log(): void
    {
        // The gate was lowered by the filter, so the table renders — but the
        // Audit Log page still requires manage_options, and a link that dies
        // on "You do not have permission" is worse than no link.
        $GLOBALS['scrutiny_test_capabilities'] = ['edit_others_posts' => true];

        Filters\expectApplied(AuditHistoryRenderer::CAPABILITY_FILTER)
            ->andReturn('edit_others_posts');

        $this->expectEntries([$this->entry()]);

        $html = $this->renderer->render(42);

        $this->assertStringContainsString('<table', $html);
        $this->assertStringNotContainsString('View the full audit log', $html);
    }

    // ──────────────────────────────────────────────
    //  Escaping
    // ──────────────────────────────────────────────

    /** @test */
    public function it_escapes_every_stored_value_it_prints(): void
    {
        $this->expectEntries([
            $this->entry([
                'user_login' => '<script>alert(1)</script>',
                'detail'     => '<img src=x onerror=alert(1)>',
                'ip_address' => '"><script>alert(1)</script>',
                'action'     => 'update"><script>alert(1)</script>',
            ]),
        ]);

        $html = $this->renderer->render(42, ['show_ip' => true]);

        // Nothing stored reaches the page as markup: no tag opens, and the
        // action never breaks out of the badge's class attribute.
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('scrutiny-badge--update"><', $html);
        // It is still all there, escaped, so an audit is not silently redacted.
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }
}
