<?php

declare(strict_types=1);

namespace Scrutiny\Tests\Unit\Fields;

use BleedingDeacons\WpMocks\WpState;
use Brain\Monkey\Functions;
use Scrutiny\Admin\AuditLogAdmin;
use Scrutiny\Audit\Interfaces\AuditRepository;
use Scrutiny\Fields\AuditHistoryRenderer;
use Scrutiny\Fields\GdprAuditHistory;

/*
 * Tests for the GdprAuditHistory ACF field type.
 *
 * The field type is an adapter, so this covers the adapting: the type
 * definition ACF stores in field groups, the settings it offers, the
 * translation of those settings into renderer options, and the resolution of
 * the post ID being edited.
 *
 * The base class and the three ACF functions come from tests/stubs/acf.php —
 * the same file PHPStan reads — so this suite runs with no ACF installed.
 */

covers(GdprAuditHistory::class);

function resolvedPostId(GdprAuditHistory $field): int
{
    return (new \ReflectionMethod(GdprAuditHistory::class, 'resolvePostId'))->invoke($field);
}

beforeEach(function () {
    $GLOBALS['scrutiny_test_capabilities']       = [AuditLogAdmin::CAPABILITY => true];
    $GLOBALS['scrutiny_test_acf_field_settings'] = [];
    $GLOBALS['scrutiny_test_acf_form_data']      = [];

    $repository = $this->createMock(AuditRepository::class);
    $repository->method('find')->willReturn([]);
    $repository->method('count')->willReturn(0);

    $this->field = new GdprAuditHistory(new AuditHistoryRenderer($repository));

    // The settings the field offers in the field group editor, keyed by name.
    $this->settings = function (): array {
        $this->field->render_field_settings($this->field->defaults);

        return array_column($GLOBALS['scrutiny_test_acf_field_settings'], null, 'name');
    };
});

afterEach(function () {
    $GLOBALS['scrutiny_test_capabilities']  = [];
    $GLOBALS['scrutiny_test_acf_form_data'] = [];
});

// ──────────────────────────────────────────────
//  Type definition
// ──────────────────────────────────────────────
describe('type definition', function () {
    it('registers under the documented type slug', function () {
        // The slug is written into every field group that uses the field.
        // Renaming it orphans them, so it is pinned here as well as in the
        // constant.
        expect(GdprAuditHistory::NAME)->toBe('gdpr_audit_history')
            ->and($this->field->name)->toBe('gdpr_audit_history');
    });

    it('declares itself a layout field that stores nothing', function () {
        // Display-only, exactly like ACF's own message/tab fields: nothing to
        // require, nothing to bind, nothing to expose over REST.
        expect($this->field->category)->toBe('layout')
            ->and($this->field->supports['required'])->toBeFalse()
            ->and($this->field->supports['bindings'])->toBeFalse()
            ->and($this->field->show_in_rest)->toBeFalse();
    });

    it('defaults to the member record type', function () {
        expect($this->field->defaults)
            ->entity_type->toBe(AuditHistoryRenderer::DEFAULT_ENTITY_TYPE)
            ->max_entries->toBe(AuditHistoryRenderer::DEFAULT_MAX_ENTRIES)
            ->audit_action->toBe('');
    });
});

// ──────────────────────────────────────────────
//  Settings UI
// ──────────────────────────────────────────────
describe('settings', function () {
    it('offers a setting for each default', function () {
        // Every default must be reachable from the field group editor, or it
        // is a value nobody can change.
        expect(array_keys(($this->settings)()))->toBe(['entity_type', 'max_entries', 'audit_action', 'show_ip']);
    });

    it('offers the same record type choices as the audit log page', function () {
        expect(($this->settings)()['entity_type']['choices'])->toBe(AuditLogAdmin::ENTITY_TYPES);
    });

    it('offers every action the log records', function () {
        $action = ($this->settings)()['audit_action'];

        expect(array_keys($action['choices']))->toBe(AuditLogAdmin::ACTION_TYPES)
            // Nullable, so "all actions" stays reachable once one is chosen.
            ->and($action['allow_null'])->toBe(1);
    });

    it("stops the entry count at the renderer's ceiling", function () {
        expect(($this->settings)()['max_entries'])
            ->min->toBe(1)
            ->max->toBe(AuditHistoryRenderer::MAX_ENTRIES_CEILING);
    });
});

// ──────────────────────────────────────────────
//  Assets
// ──────────────────────────────────────────────
it('enqueues its own stylesheet', function () {
    // Hooked by the parent constructor onto acf/input/admin_enqueue_scripts.
    // Without it the action badges render as unstyled text.
    WpState::$enqueued = [];

    $this->field->input_admin_enqueue_scripts();

    expect(array_column(WpState::$enqueued, 'handle'))->toContain('scrutiny-audit-history');
});

// ──────────────────────────────────────────────
//  Rendering
// ──────────────────────────────────────────────
describe('rendering', function () {
    it('renders the history for the post being edited', function () {
        $GLOBALS['scrutiny_test_acf_form_data'] = ['post_id' => 42];

        expect(captureOutput(fn () => $this->field->render_field($this->field->defaults)))
            ->toContain('scrutiny-audit-history');
    });

    it('falls back to the current post when the form data is not a post id', function () {
        // ACF puts 'options', 'user_3' and the like in post_id for options
        // pages and user forms. Casting those to int would silently render
        // post 0's history — or worse, post 3's.
        $GLOBALS['scrutiny_test_acf_form_data'] = ['post_id' => 'options'];
        Functions\when('get_the_ID')->justReturn(99);

        expect(resolvedPostId($this->field))->toBe(99);
    });

    it('resolves to zero when there is no post at all', function () {
        $GLOBALS['scrutiny_test_acf_form_data'] = [];
        Functions\when('get_the_ID')->justReturn(false);

        expect(resolvedPostId($this->field))->toBe(0)
            // Zero is the renderer's "not saved yet" case, so the field
            // degrades to an explanation rather than an empty table.
            ->and(captureOutput(fn () => $this->field->render_field($this->field->defaults)))
            ->toContain('once this record has been saved');
    });
});
