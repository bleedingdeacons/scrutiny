<?php

declare(strict_types=1);

namespace Scrutiny\Tests\Unit\Privacy;

use BleedingDeacons\WpMocks\WpState;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Scrutiny\Privacy\ResponderCertificationGuard;
use Unity\Core\Interfaces\Configuration;
use Unity\Members\Interfaces\Member;

/*
 * Tests for ResponderCertificationGuard.
 *
 * The guard keeps the member responder-certification field visible but
 * read-only for users without the scrutiny_edit_responder_certification
 * capability: prepare_field disables the input, and update_value preserves
 * the stored value on save. REST writes (Integrity) are let through because
 * they authenticate with their own permission system and have no current
 * user for current_user_can() to test.
 *
 * That REST case defines REST_REQUEST, which cannot be undone, so it runs in a
 * separate process — and Pest refuses process isolation, so it lives in
 * ResponderCertificationGuardRestRequestTest as a PHPUnit class.
 */

covers(ResponderCertificationGuard::class);

const CERT_FIELD = 'service-layout-group_responder-certification';
const CERT_KEY   = 'field_6a5a5d9e7dcec';
const CERT_POST_TYPE = 'member';

/**
 * A minimal ACF radio field array as prepare_field receives it — a shortened
 * choices set is enough to prove every value gets disabled.
 *
 * @return array<string, mixed>
 */
function certificationRadioField(): array
{
    return [
        'name'    => CERT_FIELD,
        'key'     => CERT_KEY,
        'type'    => 'radio',
        'choices' => [
            'None'        => 'None',
            'Applied'     => 'Applied',
            'In Training' => 'In Training',
            'Certified'   => 'Certified',
        ],
    ];
}

beforeEach(function () {
    // The bootstrap's in-memory stubs back current_user_can() and get_field();
    // reset them so capabilities and stored values do not leak between cases.
    $GLOBALS['scrutiny_test_capabilities'] = [];
    $GLOBALS['scrutiny_test_acf_fields'] = [];

    // add_action is a bootstrap recorder; register() also wires an action, so
    // reset the recorder between cases.
    $GLOBALS['scrutiny_test_actions'] = [];

    // $config overrides the standard fully-populated config when given.
    $this->makeGuard = function (?array $config = null): ResponderCertificationGuard {
        $configuration = $this->createMock(Configuration::class);
        $configuration->method('getConfig')
            ->with(Member::class)
            ->willReturn($config ?? [
                'FIELD_RESPONDER_CERTIFICATION' => CERT_FIELD,
                'KEY_RESPONDER_CERTIFICATION'   => CERT_KEY,
                'POST_TYPE'                     => CERT_POST_TYPE,
            ]);

        return new ResponderCertificationGuard($configuration);
    };
});

describe('disableForReadOnlyUser', function () {
    it('disables every radio choice for users without the capability', function () {
        // ACF radio reads $field['disabled'] as a list of choice values to
        // disable, not a boolean — so all choices must be listed for the whole
        // field to become read-only.
        $field = ($this->makeGuard)()->disableForReadOnlyUser(certificationRadioField());

        expect($field)->toBeArray()
            ->and($field['disabled'])->toBe(
                ['None', 'Applied', 'In Training', 'Certified'],
                'Every choice value must be disabled so no radio option can be changed.'
            )
            ->and($field['wrapper']['class'])->toContain('scrutiny-cert-readonly');
    });

    it('leaves the field editable for users with the capability', function () {
        $GLOBALS['scrutiny_test_capabilities'] = [
            ResponderCertificationGuard::EDIT_CAPABILITY => true,
        ];

        expect(($this->makeGuard)()->disableForReadOnlyUser(certificationRadioField()))
            ->toBeArray()
            ->not->toHaveKey('disabled');
    });

    it('passes a hidden field through untouched', function () {
        // ACF passes false when the field is already hidden (e.g. by
        // conditional logic); the guard must not try to disable it.
        expect(($this->makeGuard)()->disableForReadOnlyUser(false))->toBeFalse();
    });

    it('falls back to a boolean disabled for a non-radio field', function () {
        // A field that is neither radio nor checkbox has no per-choice disable
        // semantics, so the guard uses the boolean form.
        $field = ($this->makeGuard)()->disableForReadOnlyUser([
            'name' => CERT_FIELD,
            'key'  => CERT_KEY,
            'type' => 'text',
        ]);

        expect($field)->toBeArray()
            ->and($field['disabled'])->toBe(1)
            ->and($field['wrapper']['class'])->toContain('scrutiny-cert-readonly');
    });
});

describe('preserveCertification', function () {
    it('preserves the stored value when the user cannot edit', function () {
        // REST_REQUEST is intentionally not defined — admin form saves go
        // through admin-post.php, not REST.
        $GLOBALS['scrutiny_test_acf_fields'][23462][CERT_FIELD] = 'Certified';

        $result = ($this->makeGuard)()->preserveCertification('Pending', 23462, ['name' => CERT_FIELD, 'key' => CERT_KEY]);

        expect($result)->toBe(
            'Certified',
            'A tampered POST from a user without the capability must not change the stored stage.'
        );
    });

    it('lets the change through when the user can edit', function () {
        $GLOBALS['scrutiny_test_capabilities'] = [
            ResponderCertificationGuard::EDIT_CAPABILITY => true,
        ];
        $GLOBALS['scrutiny_test_acf_fields'][23462][CERT_FIELD] = 'Certified';

        expect(($this->makeGuard)()->preserveCertification('Pending', 23462, ['name' => CERT_FIELD, 'key' => CERT_KEY]))
            ->toBe('Pending');
    });

    it('lets the initial value through when nothing is stored', function () {
        // No stored value and no capability: a create-time assignment by the
        // process that spawned the member should still land.
        expect(($this->makeGuard)()->preserveCertification('Applied', 23462, ['name' => CERT_FIELD, 'key' => CERT_KEY]))
            ->toBe('Applied');
    });
});

describe('register', function () {
    it('wires the prepare, save and style hooks when the key is set', function () {
        $guard = ($this->makeGuard)();

        $guard->register();

        // prepare_field + update_value are filters, so Brain Monkey holds
        // them; the enqueue hook is an action, recorded by the bootstrap's own
        // add_action stub, which this file's tests read directly.
        expect(Filters\has('acf/prepare_field/key=' . CERT_KEY, [$guard, 'disableForReadOnlyUser']))->toBe(10)
            ->and(Filters\has('acf/update_value/key=' . CERT_KEY, [$guard, 'preserveCertification']))->toBe(10)
            ->and(array_column($GLOBALS['scrutiny_test_actions'], 'hook'))->toContain('acf/input/admin_enqueue_scripts');
    });

    it('does nothing when the certification key is absent', function () {
        // Without a configured field key there is nothing to hook: register()
        // returns before any add_filter/add_action call, so the action
        // recorder stays empty.
        ($this->makeGuard)(['POST_TYPE' => CERT_POST_TYPE])->register();

        expect($GLOBALS['scrutiny_test_actions'])->toBe([]);
    });
});

describe('enqueueReadOnlyStyle', function () {
    it('enqueues the read-only style on the member screen for locked users', function () {
        Functions\expect('get_current_screen')->andReturn((object) ['post_type' => CERT_POST_TYPE]);
        Functions\expect('wp_register_style')->once();
        Functions\expect('wp_enqueue_style')->once()->with('scrutiny-cert-readonly');
        Functions\expect('wp_add_inline_style')
            ->once()
            ->with('scrutiny-cert-readonly', \Mockery::pattern('/scrutiny-cert-readonly/'));

        ($this->makeGuard)()->enqueueReadOnlyStyle();
    });

    it('does not enqueue the style for users who can edit', function () {
        $GLOBALS['scrutiny_test_capabilities'][ResponderCertificationGuard::EDIT_CAPABILITY] = true;

        // Returns before touching the screen or the style functions.
        Functions\expect('wp_enqueue_style')->never();

        ($this->makeGuard)()->enqueueReadOnlyStyle();
    });

    it('does not enqueue the style off the member screen', function () {
        Functions\expect('wp_enqueue_style')->never();

        // No screen resolved…
        WpState::$screen = null;
        ($this->makeGuard)()->enqueueReadOnlyStyle();

        // …and a different post type.
        Functions\expect('get_current_screen')->andReturn((object) ['post_type' => 'post']);
        ($this->makeGuard)()->enqueueReadOnlyStyle();
    });
});
