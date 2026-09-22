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

/*
 * Tests for the Audit Log screen.
 *
 * The screen is the only place the audit trail is read by a human, so what is
 * asserted here is mostly "did the filter the admin typed reach the
 * repository, and did the row that came back render as the right thing".
 *
 * Techniques, following Integrity's SettingsPageTest:
 *
 *   - Registration runs for real; the constructor's hooks are read from this
 *     plugin's own recording add_action() (see MemberPrunerAdminTest's header
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
 */

covers(AuditLogAdmin::class);

function grantAuditLogCapability(): void
{
    $GLOBALS['scrutiny_test_capabilities'][AuditLogAdmin::CAPABILITY] = true;
}

/**
 * Build an audit row in the shape GdprAuditRepository hands back.
 *
 * @param array<string, mixed> $overrides
 */
function auditRow(array $overrides = []): object
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

function detailCell(object $entry): string
{
    return (new ReflectionMethod(AuditLogAdmin::class, 'renderDetailCell'))->invoke(null, $entry);
}

/**
 * @return int[]
 */
function postIdsWithTitle(string $search): array
{
    return (new ReflectionMethod(AuditLogAdmin::class, 'findPostIdsByTitle'))->invoke(null, $search);
}

/**
 * Start a purge request for $days (or none, to exercise the default).
 */
function requestPurge(?string $days = null): void
{
    grantAuditLogCapability();
    $_GET['scrutiny_purge'] = '1';

    if ($days !== null) {
        $_GET['scrutiny_purge_days'] = $days;
    }
}

beforeEach(function () {
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

    // Drive the screen with the given repository results and hand back the
    // markup, line endings normalised so assertions read the same on Windows
    // and on CI.
    $this->render = function (array $entries = [], ?int $total = null): string {
        grantAuditLogCapability();

        $this->repository->shouldReceive('find')->andReturn($entries);
        $this->repository->shouldReceive('count')->andReturn($total ?? count($entries));

        return captureOutput(fn () => $this->page->renderPage());
    };

    // Capture the arguments the screen builds for the repository.
    $this->queryArgs = function (): array {
        grantAuditLogCapability();

        $captured = [];
        $this->repository->shouldReceive('find')
            ->andReturnUsing(function (array $args) use (&$captured): array {
                $captured = $args;

                return [];
            });
        $this->repository->shouldReceive('count')->andReturn(0);

        captureOutput(fn () => $this->page->renderPage());

        return $captured;
    };
});

afterEach(function () {
    $_GET = [];
    unset($GLOBALS['wpdb']);
});

// ── registration ──────────────────────────────────────────────────
describe('registration', function () {
    it('hooks the menu and the purge handler on construction', function () {
        $hooks = array_column($GLOBALS['scrutiny_test_actions'], 'priority', 'hook');

        expect($hooks)->toHaveKeys(['admin_menu', 'admin_init'])
            ->and($hooks['admin_menu'])->toBe(20);
    });

    // This screen deliberately sits under Intergroup rather than the Scrutiny
    // menu: it is a working tool, not a configuration page.
    it('registers a submenu under the Intergroup menu', function () {
        $this->page->registerMenu();

        expect(WpState::$menus[0])->toBe([
            'type'   => 'submenu',
            'parent' => 'intergroup',
            'slug'   => AuditLogAdmin::MENU_SLUG,
            'title'  => 'Audit Log',
            'cap'    => AuditLogAdmin::CAPABILITY,
        ]);
    });
});

// ── purge ─────────────────────────────────────────────────────────
describe('purge', function () {
    // admin_init fires on every admin request, so an ordinary page load must
    // not reach the repository.
    it('ignores a request that did not ask for it', function () {
        grantAuditLogCapability();
        $this->repository->shouldNotReceive('purge');

        $this->page->handlePurge();

        expect($this->logger->entries)->toBe([]);
    });

    // The purge is a destructive GET, so the capability is checked before the
    // nonce — a user without it gets nothing at all, not a nonce failure.
    it('ignores a user without the capability', function () {
        $_GET['scrutiny_purge'] = '1';
        $this->repository->shouldNotReceive('purge');

        $this->page->handlePurge();

        expect($this->logger->entries)->toBe([]);
    });

    it('deletes using the requested retention window', function () {
        requestPurge('90');
        $this->repository->shouldReceive('purge')->once()->with(90)->andReturn(12);

        $this->page->handlePurge();

        expect($this->logger->entries[0]['detail'])->toBe('Purged 12 entries older than 90 days');
    });

    // The button on the screen only offers 365 days, but the window arrives in
    // the query string, so the handler needs its own default.
    it('falls back to a year without an explicit window', function () {
        requestPurge();
        $this->repository->shouldReceive('purge')->once()->with(365)->andReturn(0);

        $this->page->handlePurge();

        expect($this->logger->entries[0]['detail'])->toBe('Purged 0 entries older than 365 days');
    });

    // Deleting audit entries is itself an auditable act — otherwise the one
    // action a bad actor would most want to hide is the one the log forgets.
    it('writes its own audit entry', function () {
        requestPurge('30');
        $this->repository->shouldReceive('purge')->once()->andReturn(5);

        $this->page->handlePurge();

        expect($this->logger->entries)->toHaveCount(1)
            ->and($this->logger->entries[0])->toBe([
                'action'     => 'purge',
                'entityType' => 'audit_log',
                'entityId'   => 0,
                'fieldName'  => 'all',
                'detail'     => 'Purged 5 entries older than 30 days',
            ]);
    });

    it('queues an admin notice reporting what it removed', function () {
        requestPurge('30');
        $this->repository->shouldReceive('purge')->once()->andReturn(5);

        $this->page->handlePurge();

        $notices = array_values(array_filter(
            $GLOBALS['scrutiny_test_actions'],
            static fn (array $a): bool => $a['hook'] === 'admin_notices'
        ));

        expect($notices)->toHaveCount(1)
            ->and(captureOutput($notices[0]['callback']))
            ->toContain('notice-success', 'Purged 5 audit log entries older than 30 days.');
    });
});

// ── render: guard ─────────────────────────────────────────────────
it('refuses to render the screen for a user without the capability', function () {
    $this->page->renderPage();
})->throws(WpDieException::class);

// ── render: chrome ────────────────────────────────────────────────
describe('the screen chrome', function () {
    it('renders an empty log as the table with a placeholder row', function () {
        expect(($this->render)())->toContain(
            'Scrutiny – Audit Log',
            'No entries found.',
            '<strong>0</strong> entries found.',
        );
    });

    // The Help link's href is the guide itself rather than "#", and the
    // handler only cancels that navigation once window.open() has returned a
    // window. A popup blocker refusing it returns null, and an unconditional
    // preventDefault() would leave the link doing nothing at all.
    it('keeps the Help link working when the popup is blocked', function () {
        expect(($this->render)())
            ->toContain(
                'assets/docs/scrutiny.html" target="_blank"',
                'rel="noopener"',
                'if (help) { event.preventDefault();',
            )
            ->not->toContain('href="#"');
    });

    it('offers every entity, action and field in the filter form', function () {
        expect(($this->render)())->toContain(
            '<option value="member"',
            '<option value="audit_log"',
            '<option value="purge"',
            '<option value="' . PersonalDataFields::MOBILE_NUMBER . '"',
        );
    });

    it('gives the purge button a nonce and the one-year window', function () {
        expect(($this->render)())->toContain(
            'scrutiny_purge=1',
            'scrutiny_purge_days=365',
            '_wpnonce=nonce-' . AuditLogAdmin::NONCE_ACTION,
        );
    });

    // The dropdown is built from whoever actually appears in the log, so an
    // intergroup with three admins does not get a list of every WP user.
    it('lists only users who appear in the log in the user filter', function () {
        $this->wpdb->results = [
            (object) ['user_id' => 3, 'user_login' => 'chair'],
            (object) ['user_id' => 7, 'user_login' => 'secretary'],
        ];

        expect(($this->render)())->toContain('chair', 'secretary')
            ->and($this->wpdb->lastQuery())->toContain('scrutiny_audit_log');
    });
});

// ── render: rows ──────────────────────────────────────────────────
describe('the rows', function () {
    it('renders the user, action, field and IP of a row', function () {
        expect(($this->render)([auditRow()]))
            ->not->toContain('No entries found.')
            ->toContain('secretary', '(#7)', 'scrutiny-badge--update', 'Update', 'Mobile Number', '192.168.0.x');
    });

    // The entity_id is a post ID and the post title is the member's anonymous
    // name, which is what an administrator recognises — a bare number is not.
    it('links a member row to the member using their anonymous name', function () {
        WpState::addPost(42, ['post_title' => 'John D.']);

        expect(($this->render)([auditRow()]))
            ->toContain('John D.')
            // &#038;, not &: WordPress encodes the separator in an href.
            ->toContain('post.php?post=42&#038;action=edit');
    });

    // `user` rows store a WP user ID in entity_id, not a post ID, so there is
    // no title to find and the raw reference is shown instead.
    it('falls back to the raw id when there is no post title', function () {
        expect(($this->render)([auditRow(['entity_type' => 'user', 'entity_id' => 99])]))->toContain('#99');
    });

    it('renders a dash rather than a broken link for a row with no entity', function () {
        expect(($this->render)([auditRow(['entity_id' => 0])]))
            ->toContain('—')
            ->not->toContain('post.php?post=0');
    });

    // Timestamps are stored in UTC; the screen shows them in the site's
    // timezone using the site's own date and time formats.
    it("renders a timestamp in the site's configured format", function () {
        $GLOBALS['scrutiny_test_options']['date_format'] = 'Y-m-d';
        $GLOBALS['scrutiny_test_options']['time_format'] = 'H:i';

        expect(($this->render)([auditRow(['logged_at' => '2026-03-01 09:30:00'])]))->toContain('2026-03-01 09:30');
    });

    // An unparseable stored value is shown as-is rather than swallowed — a
    // corrupt row should be visible, not invisible.
    it('renders an unparseable timestamp verbatim', function () {
        expect(($this->render)([auditRow(['logged_at' => 'not a date at all'])]))->toContain('not a date at all');
    });

    it('renders an unrecognised entity type rather than dropping it', function () {
        expect(($this->render)([auditRow(['entity_type' => 'sponsorship'])]))->toContain('sponsorship');
    });
});

// ── render: filters ───────────────────────────────────────────────
describe('the filters', function () {
    it('asks only for the first page when unfiltered', function () {
        expect(($this->queryArgs)())->toBe(['per_page' => 50, 'page' => 1]);
    });

    it('passes the dropdown filters through to the repository', function () {
        $_GET = [
            'entity_type'   => 'member',
            'filter_action' => 'view',
            'field_name'    => PersonalDataFields::PERSONAL_EMAIL,
            'user_id'       => '7',
            'date_from'     => '2026-01-01',
            'date_to'       => '2026-01-31',
        ];

        expect(($this->queryArgs)())
            ->entity_type->toBe('member')
            ->action->toBe('view')
            ->field_name->toBe(PersonalDataFields::PERSONAL_EMAIL)
            ->user_id->toBe(7, 'user_id should be an int')
            ->date_from->toBe('2026-01-01')
            ->date_to->toBe('2026-01-31');
    });

    // Empty query-string values mean "no filter", not "filter on the empty
    // string" — otherwise submitting the form with everything blank would
    // return nothing.
    it('drops blank filter fields rather than querying on them', function () {
        $_GET = [
            'entity_type'   => '',
            'filter_action' => '',
            'field_name'    => '',
            'user_id'       => '0',
            'date_from'     => '',
            'date_to'       => '',
        ];

        expect(($this->queryArgs)())->toBe(['per_page' => 50, 'page' => 1]);
    });

    it('reads the page number from the query string', function () {
        $_GET['paged'] = '4';

        expect(($this->queryArgs)()['page'])->toBe(4);
    });

    it('clamps a zero or negative page to the first page', function () {
        $_GET['paged'] = '-3';

        expect(($this->queryArgs)()['page'])->toBe(1);
    });

    // The Member box takes either an ID or a name fragment. A numeric entry is
    // an exact ID match; anything else is resolved to post titles first.
    it('turns a numeric member filter into an exact id match', function () {
        $_GET['entity_query'] = '42';

        expect(($this->queryArgs)())
            ->entity_id->toBe(42)
            ->not->toHaveKey('entity_ids')
            ->not->toHaveKey('entity_query', message: 'the raw box value is not a repository argument');
    });

    it('resolves a name member filter to the matching post ids', function () {
        $_GET['entity_query'] = 'John';
        $this->wpdb->col      = ['11', '12'];

        expect(($this->queryArgs)())
            ->entity_ids->toBe([11, 12])
            ->not->toHaveKey('entity_id')
            ->and($this->wpdb->queries[0])->toContain('post_title LIKE');
    });

    // A name matching nothing has to produce an empty result rather than
    // silently dropping the filter and showing the whole log.
    it('forces an empty result for a name filter matching nothing', function () {
        $_GET['entity_query'] = 'Nobody';
        $this->wpdb->col      = [];

        expect(($this->queryArgs)()['entity_ids'])->toBe([0]);
    });

    it('names each filter in force in the active filter summary', function () {
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

        expect(($this->render)())->toContain(
            'Active Filters:',
            'Entity: Member',
            'Action: View',
            'Field: Personal Email',
            'Member: John',
            'From: 2026-01-01',
            'To: 2026-01-31',
        );
    });

    // get_userdata() returns false for a deleted user, and the summary has to
    // stay readable rather than rendering an empty "User: ".
    it('falls back to the id for a filter on a deleted user', function () {
        $_GET['user_id'] = '7';

        expect(($this->render)())->toContain('User: ID #7');
    });

    it('names a known user in the filter summary', function () {
        Functions\when('get_userdata')->justReturn((object) ['user_login' => 'chair']);
        $_GET['user_id'] = '3';

        expect(($this->render)())->toContain('User: chair');
    });

    it('summarises a numeric member filter as an id', function () {
        $_GET['entity_query'] = '42';

        expect(($this->render)())->toContain('Member ID: #42');
    });

    it('shows no summary when nothing is filtered', function () {
        expect(($this->render)())->not->toContain('Active Filters:');
    });
});

// ── render: pagination ────────────────────────────────────────────
describe('pagination', function () {
    it('shows no pagination for a single page of results', function () {
        expect(($this->render)([auditRow()], 20))
            ->not->toContain('tablenav')
            ->not->toContain('Page 1 of');
    });

    // 50 rows per page, so 120 entries is three pages, and the page the admin
    // is on is rendered as plain text rather than a link to itself.
    it('links multiple pages with the current one marked', function () {
        $_GET['paged'] = '2';

        expect(($this->render)([auditRow()], 120))
            ->toContain('Page 2 of 3.', '<strong>[2]</strong>', 'paged=1', 'paged=3');
    });

    // Paging must not silently drop the filters the admin applied.
    it('carries the active filters forward in the page links', function () {
        $_GET = ['entity_type' => 'member', 'filter_action' => 'view', 'paged' => '1'];

        expect(($this->render)([auditRow()], 120))->toContain('entity_type=member', 'filter_action=view');
    });
});

// ── detail cell (reflection: private statics) ─────────────────────
describe('the detail cell', function () {
    // Reach writes a structured detail string for the view and call steps.
    // Everything else — legacy rows, other plugins, earlier versions — has to
    // survive as escaped plain text.
    it('renders the detail of a non-Reach action as plain text', function () {
        expect(detailCell(auditRow(['action' => 'update', 'detail' => 'caller:John D.#11'])))
            ->toBe('caller:John D.#11')
            ->not->toContain('<a');
    });

    it('escapes a detail string rendered as text', function () {
        expect(detailCell(auditRow(['action' => 'update', 'detail' => '<script>alert(1)</script>'])))
            ->not->toContain('<script>');
    });

    it('names the requester of a view row and links to them', function () {
        expect(detailCell(auditRow(['action' => AuditLogger::ACTION_VIEW, 'detail' => 'caller:John D.#11'])))
            ->toContain('Requester:', 'John D.', 'post=11');
    });

    // The same detail format serves both actions, but a call is placed by a
    // "caller" while a view is run by a "requester".
    it('names the caller of a call row and its result', function () {
        expect(detailCell(auditRow([
            'action' => AuditLogger::ACTION_CALL,
            'detail' => 'caller:John D.#11;result:No answer',
        ])))->toContain('Caller:', 'John D.', 'Result: No answer');
    });

    it('names an unknown caller but does not link them', function () {
        expect(detailCell(auditRow([
            'action' => AuditLogger::ACTION_CALL,
            'detail' => 'caller:unknown;result:Engaged',
        ])))
            ->toContain('Caller: unknown', 'Result: Engaged')
            ->not->toContain('<a');
    });

    // get_edit_post_link() returns null for a post the current user cannot
    // edit; the name still has to render, just without the link.
    it('renders a caller with no editable post unlinked', function () {
        Functions\when('get_edit_post_link')->justReturn(null);

        expect(detailCell(auditRow(['action' => AuditLogger::ACTION_VIEW, 'detail' => 'caller:John D.#11'])))
            ->toContain('Requester: John D.')
            ->not->toContain('<a');
    });

    it('falls back to plain text for a malformed Reach detail', function (string $detail) {
        expect(detailCell(auditRow(['action' => AuditLogger::ACTION_VIEW, 'detail' => $detail])))
            ->toBe(htmlspecialchars($detail, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    })->with([
        'no caller prefix'   => ['requester:John D.#11'],
        'empty'              => [''],
        'no hash'            => ['caller:John D.'],
        'empty name'         => ['caller:#11'],
        'non-numeric id'     => ['caller:John D.#abc'],
        'zero id'            => ['caller:John D.#0'],
        'empty result label' => ['caller:John D.#11;result:'],
    ]);

    // An anonymous name containing a '#' is unusual but legal, so the id is
    // split off the last '#' rather than the first.
    it('splits a name containing a hash on the last one', function () {
        expect(detailCell(auditRow(['action' => AuditLogger::ACTION_VIEW, 'detail' => 'caller:John #2 D.#11'])))
            ->toContain('John #2 D.', 'post=11');
    });

    it('treats a missing detail property as empty', function () {
        expect(detailCell((object) ['action' => 'update']))->toBe('');
    });
});

// ── title lookup (reflection: private static) ─────────────────────
describe('the title lookup', function () {
    // An empty search would otherwise LIKE '%%' and match every post in the
    // site, so it short-circuits to the no-match sentinel instead.
    it('matches nothing for an empty search, without querying', function () {
        expect(postIdsWithTitle(''))->toBe([0])
            ->and($this->wpdb->queries)->toBe([], 'no query should have been run');
    });

    it('excludes revisions and trashed posts', function () {
        $this->wpdb->col = ['5'];

        expect(postIdsWithTitle('John'))->toBe([5])
            ->and($this->wpdb->lastQuery())->toContain(
                "post_type NOT IN ('revision', 'nav_menu_item')",
                "post_status NOT IN ('auto-draft', 'trash')",
                'LIMIT 200',
            );
    });
});
