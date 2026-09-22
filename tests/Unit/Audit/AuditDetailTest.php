<?php

declare(strict_types=1);

namespace Scrutiny\Tests\Unit\Audit;

use Brain\Monkey\Functions;
use Scrutiny\Audit\AuditDetail;
use stdClass;

/*
 * Tests for AuditDetail, the Detail-column renderer shared by the Audit Log
 * admin page and the GdprAuditHistory field.
 *
 * Reach writes `caller:<name>#<id>` and `caller:<name>#<id>;result:<label>`
 * into the detail column. Everything else — legacy rows, entries from other
 * plugins, anything malformed — has to survive as plain escaped text rather
 * than disappearing or half-parsing, so the fallback path gets as much
 * attention here as the happy one.
 */

covers(AuditDetail::class);

function auditEntry(string $action, string $detail): stdClass
{
    $entry = new stdClass();
    $entry->action = $action;
    $entry->detail = $detail;

    return $entry;
}

// ──────────────────────────────────────────────
//  Plain-text fallback
// ──────────────────────────────────────────────
describe('plain-text fallback', function () {
    it('renders other actions as plain text', function () {
        expect(AuditDetail::render(auditEntry('update', 'Changed from x to y')))->toBe('Changed from x to y');
    });

    it('escapes the plain text it falls back to', function () {
        expect(AuditDetail::render(auditEntry('update', '<script>alert(1)</script>')))->not->toContain('<script');
    });

    it('falls back when the caller string does not parse', function (string $detail) {
        // A half-parsed caller string would put a wrong name against a
        // member's record, which is worse than showing the raw value.
        expect(AuditDetail::render(auditEntry('view', $detail)))->toBe($detail);
    })->with([
        'no caller prefix'   => ['Viewed contact details'],
        'empty result label' => ['caller:John D.#7;result:'],
        'no hash at all'     => ['caller:John D.'],
        'empty name'         => ['caller:#7'],
        'non-numeric id'     => ['caller:John D.#abc'],
        'zero id'            => ['caller:John D.#0'],
    ]);
});

// ──────────────────────────────────────────────
//  Structured caller strings
// ──────────────────────────────────────────────
describe('structured caller strings', function () {
    it('labels a view row as a requester and links them', function () {
        Functions\when('get_edit_post_link')->justReturn('https://example.test/edit-7');

        expect(AuditDetail::render(auditEntry('view', 'caller:John D.#7')))
            ->toContain('Requester: ', 'href="https://example.test/edit-7"', '>John D.</a>');
    });

    it('labels a call row as a caller and shows the result', function () {
        Functions\when('get_edit_post_link')->justReturn('https://example.test/edit-7');

        expect(AuditDetail::render(auditEntry('call', 'caller:John D.#7;result:No answer')))
            ->toContain('Caller: ', 'Result: No answer');
    });

    it('drops the link when the caller has no edit screen', function () {
        // get_edit_post_link() returns null for a post the current user cannot
        // edit, or one that no longer exists. The name still has to render.
        Functions\when('get_edit_post_link')->justReturn(null);

        expect(AuditDetail::render(auditEntry('view', 'caller:John D.#7')))
            ->toContain('Requester: John D.')
            ->not->toContain('<a ');
    });

    it('renders the unknown sentinel without a link', function () {
        expect(AuditDetail::render(auditEntry('view', 'caller:unknown')))->toBe('Requester: unknown');
    });

    it('renders the unknown sentinel with a call result', function () {
        expect(AuditDetail::render(auditEntry('call', 'caller:unknown;result:Engaged')))
            ->toContain('Caller: unknown', 'Result: Engaged');
    });

    it('splits on the last hash so names containing one survive', function () {
        Functions\when('get_edit_post_link')->justReturn('https://example.test/edit-7');

        expect(AuditDetail::render(auditEntry('view', 'caller:John #2 D.#7')))
            ->toContain('John #2 D.', 'edit-7');
    });

    it('escapes a name and result taken from the detail string', function () {
        Functions\when('get_edit_post_link')->justReturn(null);

        expect(AuditDetail::render(auditEntry('call', 'caller:<b>John</b>#7;result:<script>alert(1)</script>')))
            ->not->toContain('<b>')
            ->not->toContain('<script');
    });

    it('tolerates a row with no detail or action at all', function () {
        // $wpdb rows are NOT NULL in the schema, but the renderer is handed
        // whatever the caller has; an absent property must not fatal.
        expect(AuditDetail::render(new stdClass()))->toBe('');
    });
});
