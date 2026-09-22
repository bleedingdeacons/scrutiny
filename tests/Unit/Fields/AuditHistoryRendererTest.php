<?php

declare(strict_types=1);

namespace Scrutiny\Tests\Unit\Fields;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PHPUnit\Framework\MockObject\MockObject;
use Scrutiny\Admin\AuditLogAdmin;
use Scrutiny\Audit\Interfaces\AuditRepository;
use Scrutiny\Fields\AuditHistoryRenderer;
use Scrutiny\Privacy\PersonalDataFields;
use stdClass;

/*
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
 */

covers(AuditHistoryRenderer::class);

/**
 * Build an audit row in the shape $wpdb->get_results() returns — every column
 * a string, none of them null.
 *
 * @param array<string, string> $overrides
 */
function historyEntry(array $overrides = []): stdClass
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
 * Serve $entries from find() and $total (default: their count) from count().
 *
 * @param array<int, stdClass> $entries
 */
function serveEntries(MockObject $repository, array $entries, ?int $total = null): void
{
    $repository->method('find')->willReturn($entries);
    $repository->method('count')->willReturn($total ?? count($entries));
}

/**
 * Record the criteria find() is called with into $captured, serving nothing.
 */
function captureCriteria(MockObject $repository, mixed &$captured): void
{
    $repository->method('find')
        ->willReturnCallback(function (array $args) use (&$captured): array {
            $captured = $args;
            return [];
        });
    $repository->method('count')->willReturn(0);
}

beforeEach(function () {
    $GLOBALS['scrutiny_test_capabilities'] = [AuditLogAdmin::CAPABILITY => true];

    $this->repository = $this->createMock(AuditRepository::class);
    $this->renderer   = new AuditHistoryRenderer($this->repository);
});

afterEach(function () {
    $GLOBALS['scrutiny_test_capabilities'] = [];
});

// ──────────────────────────────────────────────
//  Capability gate
// ──────────────────────────────────────────────
describe('capability gate', function () {
    it('refuses to render without the capability', function () {
        $GLOBALS['scrutiny_test_capabilities'] = [];

        // The gate has to come before the query, not after it — otherwise the
        // audit trail is read for a user who is never shown it.
        $this->repository->expects($this->never())->method('find');
        $this->repository->expects($this->never())->method('count');

        expect($this->renderer->render(42))
            ->toContain('do not have permission')
            ->not->toContain('<table');
    });

    it('honours a capability lowered by the filter', function () {
        $GLOBALS['scrutiny_test_capabilities'] = ['edit_others_posts' => true];

        Filters\expectApplied(AuditHistoryRenderer::CAPABILITY_FILTER)
            ->once()
            ->andReturn('edit_others_posts');

        serveEntries($this->repository, [historyEntry()]);

        expect($this->renderer->render(42))->toContain('<table');
    });
});

// ──────────────────────────────────────────────
//  Empty states
// ──────────────────────────────────────────────
describe('empty states', function () {
    it('explains itself on an unsaved record', function () {
        // entity_id 0 is what an unsaved post resolves to. Querying it would
        // match nothing anyway, but the message is the point: an empty table
        // on a new member reads like "nobody has ever touched this".
        $this->repository->expects($this->never())->method('find');

        expect($this->renderer->render(0))->toContain('once this record has been saved');
    });

    it('says so when the record has no entries', function () {
        serveEntries($this->repository, []);

        expect($this->renderer->render(42))
            ->toContain('No audit entries')
            ->not->toContain('<table');
    });
});

// ──────────────────────────────────────────────
//  Query criteria
// ──────────────────────────────────────────────
describe('query criteria', function () {
    it('queries the record it was asked for', function () {
        $captured = null;

        $this->repository->expects($this->once())
            ->method('find')
            ->willReturnCallback(function (array $args) use (&$captured): array {
                $captured = $args;
                return [];
            });
        $this->repository->method('count')->willReturn(0);

        $this->renderer->render(42, ['entity_type' => 'group', 'max_entries' => 5]);

        expect($captured)
            ->entity_type->toBe('group')
            ->entity_id->toBe(42)
            ->per_page->toBe(5)
            ->page->toBe(1)
            // No action setting means no action filter — not action ''.
            ->not->toHaveKey('action');
    });

    it('passes a chosen action through as a filter', function () {
        $captured = null;
        captureCriteria($this->repository, $captured);

        $this->renderer->render(42, ['action' => 'view']);

        expect($captured['action'])->toBe('view');
    });

    it('falls back to the member entity type', function () {
        $captured = null;
        captureCriteria($this->repository, $captured);

        $this->renderer->render(42, ['entity_type' => '   ']);

        expect($captured['entity_type'])->toBe(AuditHistoryRenderer::DEFAULT_ENTITY_TYPE);
    });

    // GdprAuditRepository::find() silently caps per_page at 200, so asking
    // for more would promise a page size it never delivers. Zero and negatives
    // would produce a nonsensical LIMIT.
    it('clamps the page size to what the repository will serve', function (int $asked, int $expected) {
        $captured = null;
        captureCriteria($this->repository, $captured);

        $this->renderer->render(42, ['max_entries' => $asked]);

        expect($captured['per_page'])->toBe($expected);
    })->with([
        'above the ceiling' => [5000, AuditHistoryRenderer::MAX_ENTRIES_CEILING],
        'at the ceiling'    => [200, 200],
        'zero'              => [0, 1],
        'negative'          => [-10, 1],
        'ordinary'          => [15, 15],
    ]);
});

// ──────────────────────────────────────────────
//  Table output
// ──────────────────────────────────────────────
describe('table output', function () {
    it('renders a row per entry', function () {
        serveEntries($this->repository, [
            historyEntry(['user_login' => 'alice', 'action' => 'view']),
            historyEntry(['user_login' => 'bob', 'action' => 'update']),
        ]);

        $html = $this->renderer->render(42);

        expect($html)->toContain('alice', 'bob')
            // One badge per body row (the header row has none).
            ->and(substr_count($html, 'class="scrutiny-badge'))->toBe(2)
            // Action badges carry the action in the modifier class, which the
            // stylesheet colours by.
            ->and($html)->toContain('scrutiny-badge--view', 'scrutiny-badge--update');
    });

    it('labels the field rather than naming the meta key', function () {
        serveEntries($this->repository, [historyEntry(['field_name' => PersonalDataFields::PERSONAL_EMAIL])]);

        expect($this->renderer->render(42))->toContain('Personal Email');
    });

    it('hides IP addresses unless asked for them', function () {
        serveEntries($this->repository, [historyEntry(['ip_address' => '203.0.113.0'])]);

        expect($this->renderer->render(42))->not->toContain('203.0.113.0');
    });

    it('shows IP addresses when the setting is on', function () {
        serveEntries($this->repository, [historyEntry(['ip_address' => '203.0.113.0'])]);

        expect($this->renderer->render(42, ['show_ip' => true]))->toContain('203.0.113.0');
    });

    it('renders Reach caller details as a named requester', function () {
        // Reach's structured detail strings are the reason this field exists
        // on a member: they record who was shown that member's contact
        // details. Raw `caller:John D.#7` would be unreadable.
        Functions\when('get_edit_post_link')->justReturn('https://example.test/edit');

        serveEntries($this->repository, [
            historyEntry(['action' => 'view', 'detail' => 'caller:John D.#7']),
        ]);

        expect($this->renderer->render(42))->toContain('Requester: ', 'John D.');
    });
});

// ──────────────────────────────────────────────
//  Summary and full-log link
// ──────────────────────────────────────────────
describe('summary and full-log link', function () {
    it('reports the full total, not the page size', function () {
        // count() is asked separately for exactly this reason: a member with
        // hundreds of view entries must not look like they have two.
        serveEntries($this->repository, [historyEntry(), historyEntry()], 137);

        expect($this->renderer->render(42, ['max_entries' => 2]))->toContain('2 most recent of 137');
    });

    it('links to the full log filtered to this record', function () {
        Functions\when('get_the_title')->justReturn('John D.');
        serveEntries($this->repository, [historyEntry()]);

        expect($this->renderer->render(42))
            ->toContain(AuditLogAdmin::MENU_SLUG, 'entity_query=John', 'View the full audit log');
    });

    it('omits the link for users who cannot open the audit log', function () {
        // The gate was lowered by the filter, so the table renders — but the
        // Audit Log page still requires manage_options, and a link that dies
        // on "You do not have permission" is worse than no link.
        $GLOBALS['scrutiny_test_capabilities'] = ['edit_others_posts' => true];

        Filters\expectApplied(AuditHistoryRenderer::CAPABILITY_FILTER)
            ->andReturn('edit_others_posts');

        serveEntries($this->repository, [historyEntry()]);

        expect($this->renderer->render(42))
            ->toContain('<table')
            ->not->toContain('View the full audit log');
    });
});

// ──────────────────────────────────────────────
//  Escaping
// ──────────────────────────────────────────────
it('escapes every stored value it prints', function () {
    serveEntries($this->repository, [
        historyEntry([
            'user_login' => '<script>alert(1)</script>',
            'detail'     => '<img src=x onerror=alert(1)>',
            'ip_address' => '"><script>alert(1)</script>',
            'action'     => 'update"><script>alert(1)</script>',
        ]),
    ]);

    expect($this->renderer->render(42, ['show_ip' => true]))
        // Nothing stored reaches the page as markup: no tag opens, and the
        // action never breaks out of the badge's class attribute.
        ->not->toContain('<script')
        ->not->toContain('<img')
        ->not->toContain('scrutiny-badge--update"><')
        // It is still all there, escaped, so an audit is not silently
        // redacted.
        ->toContain('&lt;script&gt;alert(1)&lt;/script&gt;');
});
