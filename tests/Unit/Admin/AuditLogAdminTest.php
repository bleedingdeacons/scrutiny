<?php

declare(strict_types=1);

namespace Scrutiny\Tests\Unit\Admin;

use BleedingDeacons\WpMocks\Doubles\FakeWpdb;
use BleedingDeacons\WpMocks\Exceptions\WpDieException;
use BleedingDeacons\WpMocks\WpState;
use Brain\Monkey\Functions;
use Mockery;
use ReflectionMethod;
use Scrutiny\Admin\AuditLogAdmin;
use Scrutiny\Audit\Interfaces\AuditLogger;
use Scrutiny\Audit\Interfaces\AuditRepository;
use Scrutiny\Privacy\PersonalDataFields;
use Scrutiny\Testing\Doubles\SpyAuditLogger;
use Scrutiny\Tests\TestCase;

/**
 * Tests for the Audit Log screen.
 *
 * The screen is the only place the audit trail is read by a human, so what is
 * asserted here is mostly "did the filter the admin typed reach the
 * repository, and did the row that came back render as the right thing".
 *
 * Techniques, following Integrity's SettingsPageTest:
 *
 *   - Registration runs for real; the constructor's hooks are read from this
 *     plugin's own recording add_action() (see MemberPrunerAdminTest's docblock
 *     for why that rather than assertActionAdded()).
 *   - The capability guard on renderPage() calls wp_die(), which the shared
 *     stubs turn into a WpDieException.
 *   - renderPage() is driven inside an output buffer and asserted on as HTML.
 *
 * Nothing here hits the exit wall: handlePurge() returns normally rather than
 * redirecting, so its whole path — including the admin_notices callback it
 * registers — runs in-process. The two private statics are pure string work
 * with more branches than the screen can reach through $_GET alone, so they
 * are driven directly through reflection.
 *
 * The logger is Scrutiny's own SpyAuditLogger rather than a mock: the purge
 * writes an audit entry recording what it deleted, and that entry's contents
 * are the assertion.
 *
 * @covers \Scrutiny\Admin\AuditLogAdmin
 */
final class AuditLogAdminTest extends TestCase
{
    /** @var AuditRepository&Mockery\MockInterface */
    private $repository;
    private SpyAuditLogger $logger;
    private AuditLogAdmin $page;
    private FakeWpdb $wpdb;

    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['scrutiny_test_actions']      = [];
        $GLOBALS['scrutiny_test_capabilities'] = [];
        $GLOBALS['scrutiny_test_options']      = [];

        $_GET = [];

        $this->wpdb      = new FakeWpdb();
        $GLOBALS['wpdb'] = $this->wpdb;

        $this->repository = Mockery::mock(AuditRepository::class);
        $this->logger     = new SpyAuditLogger();
        $this->page       = new AuditLogAdmin($this->repository, $this->logger);

        // Neither is in the shared stub set.
        Functions\when('wp_nonce_url')->alias(
            static fn (string $url, string $action = '-1'): string => $url . '&_wpnonce=nonce-' . $action
        );
        Functions\when('get_userdata')->justReturn(false);
    }

    protected function tearDown(): void
    {
        $_GET = [];
        unset($GLOBALS['wpdb']);

        parent::tearDown();
    }

    private function grantCapability(): void
    {
        $GLOBALS['scrutiny_test_capabilities'][AuditLogAdmin::CAPABILITY] = true;
    }

    /**
     * Build an audit row in the shape GdprAuditRepository hands back.
     *
     * @param array<string, mixed> $overrides
     */
    private function entry(array $overrides = []): object
    {
        return (object) array_merge([
            'id'          => 1,
            'logged_at'   => '2026-03-01 09:30:00',
            'user_id'     => 7,
            'user_login'  => 'secretary',
            'action'      => 'update',
            'entity_type' => 'member',
            'entity_id'   => 42,
            'field_name'  => PersonalDataFields::MOBILE_NUMBER,
            'detail'      => '',
            'ip_address'  => '192.168.0.x',
        ], $overrides);
    }

    /**
     * Drive the screen with the given repository results and hand back the
     * markup, line endings normalised so assertions read the same on Windows
     * and on CI.
     *
     * @param array<int, object> $entries
     */
    private function render(array $entries = [], ?int $total = null): string
    {
        $this->grantCapability();

        $this->repository->shouldReceive('find')->andReturn($entries);
        $this->repository->shouldReceive('count')->andReturn($total ?? count($entries));

        ob_start();
        try {
            $this->page->renderPage();
        } finally {
            $html = (string) ob_get_clean();
        }

        return str_replace("\r\n", "\n", $html);
    }

    /**
     * Capture the arguments the screen builds for the repository.
     *
     * @return array<string, mixed>
     */
    private function captureQueryArgs(): array
    {
        $this->grantCapability();

        $captured = [];
        $this->repository->shouldReceive('find')
            ->andReturnUsing(function (array $args) use (&$captured): array {
                $captured = $args;

                return [];
            });
        $this->repository->shouldReceive('count')->andReturn(0);

        ob_start();
        try {
            $this->page->renderPage();
        } finally {
            ob_end_clean();
        }

        return $captured;
    }

    // ── registration ──────────────────────────────────────────────────

    /** @test */
    public function it_hooks_the_menu_and_the_purge_handler_on_construction(): void
    {
        $hooks = [];
        foreach ($GLOBALS['scrutiny_test_actions'] as $action) {
            $hooks[$action['hook']] = $action['priority'];
        }

        $this->assertArrayHasKey('admin_menu', $hooks);
        $this->assertSame(20, $hooks['admin_menu']);
        $this->assertArrayHasKey('admin_init', $hooks);
    }

    /**
     * This screen deliberately sits under Intergroup rather than the Scrutiny
     * menu: it is a working tool, not a configuration page.
     *
     * @test
     */
    public function it_registers_a_submenu_under_the_intergroup_menu(): void
    {
        $this->page->registerMenu();

        $this->assertSame([
            'type'   => 'submenu',
            'parent' => 'intergroup',
            'slug'   => AuditLogAdmin::MENU_SLUG,
            'title'  => 'Audit Log',
            'cap'    => AuditLogAdmin::CAPABILITY,
        ], WpState::$menus[0]);
    }

    // ── purge ─────────────────────────────────────────────────────────

    /**
     * admin_init fires on every admin request, so an ordinary page load must
     * not reach the repository.
     *
     * @test
     */
    public function the_purge_handler_ignores_a_request_that_did_not_ask_for_it(): void
    {
        $this->grantCapability();
        $this->repository->shouldNotReceive('purge');

        $this->page->handlePurge();

        $this->assertSame([], $this->logger->entries);
    }

    /**
     * The purge is a destructive GET, so the capability is checked before the
     * nonce — a user without it gets nothing at all, not a nonce failure.
     *
     * @test
     */
    public function the_purge_handler_ignores_a_user_without_the_capability(): void
    {
        $_GET['scrutiny_purge'] = '1';
        $this->repository->shouldNotReceive('purge');

        $this->page->handlePurge();

        $this->assertSame([], $this->logger->entries);
    }

    /** @test */
    public function a_purge_deletes_using_the_requested_retention_window(): void
    {
        $this->grantCapability();
        $_GET['scrutiny_purge']      = '1';
        $_GET['scrutiny_purge_days'] = '90';

        $this->repository->shouldReceive('purge')->once()->with(90)->andReturn(12);

        $this->page->handlePurge();

        $this->assertSame(
            'Purged 12 entries older than 90 days',
            $this->logger->entries[0]['detail']
        );
    }

    /**
     * The button on the screen only offers 365 days, but the window arrives in
     * the query string, so the handler needs its own default.
     *
     * @test
     */
    public function a_purge_without_an_explicit_window_falls_back_to_a_year(): void
    {
        $this->grantCapability();
        $_GET['scrutiny_purge'] = '1';

        $this->repository->shouldReceive('purge')->once()->with(365)->andReturn(0);

        $this->page->handlePurge();

        $this->assertSame(
            'Purged 0 entries older than 365 days',
            $this->logger->entries[0]['detail']
        );
    }

    /**
     * Deleting audit entries is itself an auditable act — otherwise the one
     * action a bad actor would most want to hide is the one the log forgets.
     *
     * @test
     */
    public function a_purge_writes_its_own_audit_entry(): void
    {
        $this->grantCapability();
        $_GET['scrutiny_purge']      = '1';
        $_GET['scrutiny_purge_days'] = '30';

        $this->repository->shouldReceive('purge')->once()->andReturn(5);

        $this->page->handlePurge();

        $this->assertCount(1, $this->logger->entries);
        $this->assertSame([
            'action'     => 'purge',
            'entityType' => 'audit_log',
            'entityId'   => 0,
            'fieldName'  => 'all',
            'detail'     => 'Purged 5 entries older than 30 days',
        ], $this->logger->entries[0]);
    }

    /** @test */
    public function a_purge_queues_an_admin_notice_reporting_what_it_removed(): void
    {
        $this->grantCapability();
        $_GET['scrutiny_purge']      = '1';
        $_GET['scrutiny_purge_days'] = '30';

        $this->repository->shouldReceive('purge')->once()->andReturn(5);

        $this->page->handlePurge();

        $notices = array_values(array_filter(
            $GLOBALS['scrutiny_test_actions'],
            static fn (array $a): bool => $a['hook'] === 'admin_notices'
        ));
        $this->assertCount(1, $notices);

        ob_start();
        try {
            ($notices[0]['callback'])();
        } finally {
            $html = (string) ob_get_clean();
        }

        $this->assertStringContainsString('notice-success', $html);
        $this->assertStringContainsString('Purged 5 audit log entries older than 30 days.', $html);
    }

    // ── render: guard ─────────────────────────────────────────────────

    /** @test */
    public function the_screen_refuses_a_user_without_the_capability(): void
    {
        $this->expectException(WpDieException::class);
        $this->page->renderPage();
    }

    // ── render: chrome ────────────────────────────────────────────────

    /** @test */
    public function an_empty_log_renders_the_table_with_a_placeholder_row(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('Scrutiny – Audit Log', $html);
        $this->assertStringContainsString('No entries found.', $html);
        $this->assertStringContainsString('<strong>0</strong> entries found.', $html);
    }

    /** @test */
    public function the_filter_form_offers_every_entity_action_and_field(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('<option value="member"', $html);
        $this->assertStringContainsString('<option value="audit_log"', $html);
        $this->assertStringContainsString('<option value="purge"', $html);
        $this->assertStringContainsString('<option value="' . PersonalDataFields::MOBILE_NUMBER . '"', $html);
    }

    /** @test */
    public function the_purge_button_carries_a_nonce_and_the_one_year_window(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('scrutiny_purge=1', $html);
        $this->assertStringContainsString('scrutiny_purge_days=365', $html);
        $this->assertStringContainsString('_wpnonce=nonce-' . AuditLogAdmin::NONCE_ACTION, $html);
    }

    /**
     * The dropdown is built from whoever actually appears in the log, so an
     * intergroup with three admins does not get a list of every WP user.
     *
     * @test
     */
    public function the_user_filter_lists_only_users_who_appear_in_the_log(): void
    {
        $this->wpdb->results = [
            (object) ['user_id' => 3, 'user_login' => 'chair'],
            (object) ['user_id' => 7, 'user_login' => 'secretary'],
        ];

        $html = $this->render();

        $this->assertStringContainsString('chair', $html);
        $this->assertStringContainsString('secretary', $html);
        $this->assertStringContainsString('scrutiny_audit_log', $this->wpdb->lastQuery());
    }

    // ── render: rows ──────────────────────────────────────────────────

    /** @test */
    public function a_row_renders_its_user_action_field_and_ip(): void
    {
        $html = $this->render([$this->entry()]);

        $this->assertStringNotContainsString('No entries found.', $html);
        $this->assertStringContainsString('secretary', $html);
        $this->assertStringContainsString('(#7)', $html);
        $this->assertStringContainsString('scrutiny-badge--update', $html);
        $this->assertStringContainsString('Update', $html);
        $this->assertStringContainsString('Mobile Number', $html);
        $this->assertStringContainsString('192.168.0.x', $html);
    }

    /**
     * The entity_id is a post ID and the post title is the member's anonymous
     * name, which is what an administrator recognises — a bare number is not.
     *
     * @test
     */
    public function a_member_row_links_to_the_member_using_their_anonymous_name(): void
    {
        WpState::addPost(42, ['post_title' => 'John D.']);

        $html = $this->render([$this->entry()]);

        $this->assertStringContainsString('John D.', $html);
        $this->assertStringContainsString('post.php?post=42&action=edit', $html);
    }

    /**
     * `user` rows store a WP user ID in entity_id, not a post ID, so there is
     * no title to find and the raw reference is shown instead.
     *
     * @test
     */
    public function a_row_with_no_post_title_falls_back_to_the_raw_id(): void
    {
        $html = $this->render([$this->entry(['entity_type' => 'user', 'entity_id' => 99])]);

        $this->assertStringContainsString('#99', $html);
    }

    /** @test */
    public function a_row_with_no_entity_renders_a_dash_rather_than_a_broken_link(): void
    {
        $html = $this->render([$this->entry(['entity_id' => 0])]);

        $this->assertStringContainsString('—', $html);
        $this->assertStringNotContainsString('post.php?post=0', $html);
    }

    /**
     * Timestamps are stored in UTC; the screen shows them in the site's
     * timezone using the site's own date and time formats.
     *
     * @test
     */
    public function a_timestamp_is_rendered_in_the_sites_configured_format(): void
    {
        $GLOBALS['scrutiny_test_options']['date_format'] = 'Y-m-d';
        $GLOBALS['scrutiny_test_options']['time_format'] = 'H:i';

        $html = $this->render([$this->entry(['logged_at' => '2026-03-01 09:30:00'])]);

        $this->assertStringContainsString('2026-03-01 09:30', $html);
    }

    /**
     * An unparseable stored value is shown as-is rather than swallowed — a
     * corrupt row should be visible, not invisible.
     *
     * @test
     */
    public function an_unparseable_timestamp_is_rendered_verbatim(): void
    {
        $html = $this->render([$this->entry(['logged_at' => 'not a date at all'])]);

        $this->assertStringContainsString('not a date at all', $html);
    }

    /** @test */
    public function an_unrecognised_entity_type_is_rendered_rather_than_dropped(): void
    {
        $html = $this->render([$this->entry(['entity_type' => 'sponsorship'])]);

        $this->assertStringContainsString('sponsorship', $html);
    }

    // ── render: filters ───────────────────────────────────────────────

    /** @test */
    public function an_unfiltered_screen_asks_only_for_the_first_page(): void
    {
        $args = $this->captureQueryArgs();

        $this->assertSame(['per_page' => 50, 'page' => 1], $args);
    }

    /** @test */
    public function the_dropdown_filters_are_passed_through_to_the_repository(): void
    {
        $_GET = [
            'entity_type'   => 'member',
            'filter_action' => 'view',
            'field_name'    => PersonalDataFields::PERSONAL_EMAIL,
            'user_id'       => '7',
            'date_from'     => '2026-01-01',
            'date_to'       => '2026-01-31',
        ];

        $args = $this->captureQueryArgs();

        $this->assertSame('member', $args['entity_type']);
        $this->assertSame('view', $args['action']);
        $this->assertSame(PersonalDataFields::PERSONAL_EMAIL, $args['field_name']);
        $this->assertSame(7, $args['user_id'], 'user_id should be an int');
        $this->assertSame('2026-01-01', $args['date_from']);
        $this->assertSame('2026-01-31', $args['date_to']);
    }

    /**
     * Empty query-string values mean "no filter", not "filter on the empty
     * string" — otherwise submitting the form with everything blank would
     * return nothing.
     *
     * @test
     */
    public function blank_filter_fields_are_dropped_rather_than_queried_on(): void
    {
        $_GET = [
            'entity_type'   => '',
            'filter_action' => '',
            'field_name'    => '',
            'user_id'       => '0',
            'date_from'     => '',
            'date_to'       => '',
        ];

        $this->assertSame(['per_page' => 50, 'page' => 1], $this->captureQueryArgs());
    }

    /** @test */
    public function the_page_number_is_read_from_the_query_string(): void
    {
        $_GET['paged'] = '4';

        $this->assertSame(4, $this->captureQueryArgs()['page']);
    }

    /** @test */
    public function a_zero_or_negative_page_is_clamped_to_the_first_page(): void
    {
        $_GET['paged'] = '-3';

        $this->assertSame(1, $this->captureQueryArgs()['page']);
    }

    /**
     * The Member box takes either an ID or a name fragment. A numeric entry is
     * an exact ID match; anything else is resolved to post titles first.
     *
     * @test
     */
    public function a_numeric_member_filter_becomes_an_exact_id_match(): void
    {
        $_GET['entity_query'] = '42';

        $args = $this->captureQueryArgs();

        $this->assertSame(42, $args['entity_id']);
        $this->assertArrayNotHasKey('entity_ids', $args);
        $this->assertArrayNotHasKey('entity_query', $args, 'the raw box value is not a repository argument');
    }

    /** @test */
    public function a_name_member_filter_is_resolved_to_matching_post_ids(): void
    {
        $_GET['entity_query'] = 'John';
        $this->wpdb->col      = ['11', '12'];

        $args = $this->captureQueryArgs();

        $this->assertSame([11, 12], $args['entity_ids']);
        $this->assertArrayNotHasKey('entity_id', $args);
        $this->assertStringContainsString('post_title LIKE', $this->wpdb->queries[0]);
    }

    /**
     * A name matching nothing has to produce an empty result rather than
     * silently dropping the filter and showing the whole log.
     *
     * @test
     */
    public function a_name_filter_matching_nothing_forces_an_empty_result(): void
    {
        $_GET['entity_query'] = 'Nobody';
        $this->wpdb->col      = [];

        $this->assertSame([0], $this->captureQueryArgs()['entity_ids']);
    }

    /** @test */
    public function the_active_filter_summary_names_each_filter_in_force(): void
    {
        $_GET = [
            'entity_type'   => 'member',
            'filter_action' => 'view',
            'field_name'    => PersonalDataFields::PERSONAL_EMAIL,
            'user_id'       => '7',
            'entity_query'  => 'John',
            'date_from'     => '2026-01-01',
            'date_to'       => '2026-01-31',
        ];
        $this->wpdb->col = ['11'];

        $html = $this->render();

        $this->assertStringContainsString('Active Filters:', $html);
        $this->assertStringContainsString('Entity: Member', $html);
        $this->assertStringContainsString('Action: View', $html);
        $this->assertStringContainsString('Field: Personal Email', $html);
        $this->assertStringContainsString('Member: John', $html);
        $this->assertStringContainsString('From: 2026-01-01', $html);
        $this->assertStringContainsString('To: 2026-01-31', $html);
    }

    /**
     * get_userdata() returns false for a deleted user, and the summary has to
     * stay readable rather than rendering an empty "User: ".
     *
     * @test
     */
    public function a_filter_on_a_deleted_user_falls_back_to_their_id(): void
    {
        $_GET['user_id'] = '7';

        $this->assertStringContainsString('User: ID #7', $this->render());
    }

    /** @test */
    public function a_filter_on_a_known_user_names_them(): void
    {
        Functions\when('get_userdata')->justReturn((object) ['user_login' => 'chair']);
        $_GET['user_id'] = '3';

        $this->assertStringContainsString('User: chair', $this->render());
    }

    /** @test */
    public function a_numeric_member_filter_is_summarised_as_an_id(): void
    {
        $_GET['entity_query'] = '42';

        $this->assertStringContainsString('Member ID: #42', $this->render());
    }

    /** @test */
    public function no_summary_is_shown_when_nothing_is_filtered(): void
    {
        $this->assertStringNotContainsString('Active Filters:', $this->render());
    }

    // ── render: pagination ────────────────────────────────────────────

    /** @test */
    public function a_single_page_of_results_has_no_pagination(): void
    {
        $html = $this->render([$this->entry()], 20);

        $this->assertStringNotContainsString('tablenav', $html);
        $this->assertStringNotContainsString('Page 1 of', $html);
    }

    /**
     * 50 rows per page, so 120 entries is three pages, and the page the admin
     * is on is rendered as plain text rather than a link to itself.
     *
     * @test
     */
    public function multiple_pages_are_linked_with_the_current_one_marked(): void
    {
        $_GET['paged'] = '2';

        $html = $this->render([$this->entry()], 120);

        $this->assertStringContainsString('Page 2 of 3.', $html);
        $this->assertStringContainsString('<strong>[2]</strong>', $html);
        $this->assertStringContainsString('paged=1', $html);
        $this->assertStringContainsString('paged=3', $html);
    }

    /**
     * Paging must not silently drop the filters the admin applied.
     *
     * @test
     */
    public function pagination_links_carry_the_active_filters_forward(): void
    {
        $_GET = ['entity_type' => 'member', 'filter_action' => 'view', 'paged' => '1'];

        $html = $this->render([$this->entry()], 120);

        $this->assertStringContainsString('entity_type=member', $html);
        $this->assertStringContainsString('filter_action=view', $html);
    }

    // ── detail cell (reflection: private statics) ─────────────────────

    private function detailCell(object $entry): string
    {
        /** @var string $html */
        $html = (new ReflectionMethod(AuditLogAdmin::class, 'renderDetailCell'))
            ->invoke(null, $entry);

        return $html;
    }

    /**
     * Reach writes a structured detail string for the view and call steps.
     * Everything else — legacy rows, other plugins, earlier versions — has to
     * survive as escaped plain text.
     *
     * @test
     */
    public function a_non_reach_action_renders_its_detail_as_plain_text(): void
    {
        $html = $this->detailCell($this->entry([
            'action' => 'update',
            'detail' => 'caller:John D.#11',
        ]));

        $this->assertSame('caller:John D.#11', $html);
        $this->assertStringNotContainsString('<a', $html);
    }

    /** @test */
    public function a_detail_string_is_escaped_when_it_is_rendered_as_text(): void
    {
        $html = $this->detailCell($this->entry([
            'action' => 'update',
            'detail' => '<script>alert(1)</script>',
        ]));

        $this->assertStringNotContainsString('<script>', $html);
    }

    /** @test */
    public function a_view_row_names_the_requester_and_links_to_them(): void
    {
        $html = $this->detailCell($this->entry([
            'action' => AuditLogger::ACTION_VIEW,
            'detail' => 'caller:John D.#11',
        ]));

        $this->assertStringContainsString('Requester:', $html);
        $this->assertStringContainsString('John D.', $html);
        $this->assertStringContainsString('post=11', $html);
    }

    /**
     * The same detail format serves both actions, but a call is placed by a
     * "caller" while a view is run by a "requester".
     *
     * @test
     */
    public function a_call_row_names_the_caller_and_its_result(): void
    {
        $html = $this->detailCell($this->entry([
            'action' => AuditLogger::ACTION_CALL,
            'detail' => 'caller:John D.#11;result:No answer',
        ]));

        $this->assertStringContainsString('Caller:', $html);
        $this->assertStringContainsString('John D.', $html);
        $this->assertStringContainsString('Result: No answer', $html);
    }

    /** @test */
    public function an_unknown_caller_is_named_but_not_linked(): void
    {
        $html = $this->detailCell($this->entry([
            'action' => AuditLogger::ACTION_CALL,
            'detail' => 'caller:unknown;result:Engaged',
        ]));

        $this->assertStringContainsString('Caller: unknown', $html);
        $this->assertStringContainsString('Result: Engaged', $html);
        $this->assertStringNotContainsString('<a', $html);
    }

    /**
     * get_edit_post_link() returns null for a post the current user cannot
     * edit; the name still has to render, just without the link.
     *
     * @test
     */
    public function a_caller_with_no_editable_post_renders_unlinked(): void
    {
        Functions\when('get_edit_post_link')->justReturn(null);

        $html = $this->detailCell($this->entry([
            'action' => AuditLogger::ACTION_VIEW,
            'detail' => 'caller:John D.#11',
        ]));

        $this->assertStringContainsString('Requester: John D.', $html);
        $this->assertStringNotContainsString('<a', $html);
    }

    /**
     * @test
     * @dataProvider unparseableDetails
     */
    public function a_malformed_reach_detail_falls_back_to_plain_text(string $detail): void
    {
        $html = $this->detailCell($this->entry([
            'action' => AuditLogger::ACTION_VIEW,
            'detail' => $detail,
        ]));

        $this->assertSame(htmlspecialchars($detail, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $html);
    }

    /** @return array<string, array{0: string}> */
    public static function unparseableDetails(): array
    {
        return [
            'no caller prefix'    => ['requester:John D.#11'],
            'empty'               => [''],
            'no hash'             => ['caller:John D.'],
            'empty name'          => ['caller:#11'],
            'non-numeric id'      => ['caller:John D.#abc'],
            'zero id'             => ['caller:John D.#0'],
            'empty result label'  => ['caller:John D.#11;result:'],
        ];
    }

    /**
     * An anonymous name containing a '#' is unusual but legal, so the id is
     * split off the last '#' rather than the first.
     *
     * @test
     */
    public function a_name_containing_a_hash_is_split_on_the_last_one(): void
    {
        $html = $this->detailCell($this->entry([
            'action' => AuditLogger::ACTION_VIEW,
            'detail' => 'caller:John #2 D.#11',
        ]));

        $this->assertStringContainsString('John #2 D.', $html);
        $this->assertStringContainsString('post=11', $html);
    }

    /** @test */
    public function a_missing_detail_property_is_treated_as_empty(): void
    {
        $this->assertSame('', $this->detailCell((object) ['action' => 'update']));
    }

    // ── title lookup (reflection: private static) ─────────────────────

    /**
     * An empty search would otherwise LIKE '%%' and match every post in the
     * site, so it short-circuits to the no-match sentinel instead.
     *
     * @test
     */
    public function an_empty_title_search_matches_nothing_without_querying(): void
    {
        /** @var int[] $ids */
        $ids = (new ReflectionMethod(AuditLogAdmin::class, 'findPostIdsByTitle'))
            ->invoke(null, '');

        $this->assertSame([0], $ids);
        $this->assertSame([], $this->wpdb->queries, 'no query should have been run');
    }

    /** @test */
    public function a_title_search_excludes_revisions_and_trashed_posts(): void
    {
        $this->wpdb->col = ['5'];

        /** @var int[] $ids */
        $ids = (new ReflectionMethod(AuditLogAdmin::class, 'findPostIdsByTitle'))
            ->invoke(null, 'John');

        $this->assertSame([5], $ids);
        $this->assertStringContainsString("post_type NOT IN ('revision', 'nav_menu_item')", $this->wpdb->lastQuery());
        $this->assertStringContainsString("post_status NOT IN ('auto-draft', 'trash')", $this->wpdb->lastQuery());
        $this->assertStringContainsString('LIMIT 200', $this->wpdb->lastQuery());
    }
}
