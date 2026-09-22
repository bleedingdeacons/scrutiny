<?php

declare(strict_types=1);

namespace Scrutiny\Tests\Unit\Privacy;

use Brain\Monkey\Filters;
use Scrutiny\Privacy\MemberFieldsObscurer;
use Scrutiny\Privacy\PersonalDataPolicy;
use Unity\Core\Interfaces\Configuration;
use Unity\Members\Interfaces\Member;

/*
 * Tests for MemberFieldsObscurer's read-side obscuring: the format_value
 * filters (frontend) and prepare_field filters (admin edit form), plus the
 * filter registration wiring.
 *
 * The REST-request cases live in MemberFieldsObscurerTest, a PHPUnit class,
 * because they define REST_REQUEST and so must run in a separate process.
 */

covers(MemberFieldsObscurer::class);

const EMAIL_FIELD    = 'about-layout-group_personal-email';
const MOBILE_FIELD   = 'about-layout-group_mobile-number';
const LANDLINE_FIELD = 'about-layout-group_landline-number';
const EMAIL_KEY      = 'field_aaa';
const MOBILE_KEY     = 'field_bbb';
const LANDLINE_KEY   = 'field_ccc';

function grantView(): void
{
    $GLOBALS['scrutiny_test_capabilities'][PersonalDataPolicy::VIEW_CAPABILITY] = true;
}

function grantEdit(): void
{
    $GLOBALS['scrutiny_test_capabilities'][PersonalDataPolicy::EDIT_CAPABILITY] = true;
}

beforeEach(function () {
    $GLOBALS['scrutiny_test_capabilities'] = [];
    $GLOBALS['scrutiny_test_actions'] = [];

    $this->makeObscurer = function (): MemberFieldsObscurer {
        $configuration = $this->createMock(Configuration::class);
        $configuration->method('getConfig')
            ->with(Member::class)
            ->willReturn([
                'FIELD_PERSONAL_EMAIL'  => EMAIL_FIELD,
                'FIELD_MOBILE_NUMBER'   => MOBILE_FIELD,
                'KEY_PERSONAL_EMAIL'    => EMAIL_KEY,
                'KEY_MOBILE_NUMBER'     => MOBILE_KEY,
                'FIELD_LANDLINE_NUMBER' => LANDLINE_FIELD,
                'KEY_LANDLINE_NUMBER'   => LANDLINE_KEY,
            ]);

        return new MemberFieldsObscurer($configuration, new PersonalDataPolicy());
    };
});

// ─── register ───────────────────────────────────────────────────
it('wires the format, prepare and update filters', function () {
    $obscurer = ($this->makeObscurer)();

    $obscurer->register();

    // Brain Monkey's Filters\has() answers with the registered priority when
    // given a callback, and false when the callback is not hooked, so one
    // assertion covers both "was it wired" and "at what priority".
    // Accepted-argument counts are not recorded by Brain Monkey, so the 3-arg
    // expectations the WP_Mock version carried are not reproduced.

    // format_value (frontend), priority 20.
    expect(Filters\has('acf/format_value/name=' . EMAIL_FIELD, [$obscurer, 'obscureAcfPersonalEmail']))->toBe(20)
        ->and(Filters\has('acf/format_value/name=' . MOBILE_FIELD, [$obscurer, 'obscureAcfMobileNumber']))->toBe(20)
        ->and(Filters\has('acf/format_value/name=' . LANDLINE_FIELD, [$obscurer, 'obscureAcfLandlineNumber']))->toBe(20);

    // prepare_field (admin) on the short sub-field name, default priority.
    expect(Filters\has('acf/prepare_field/name=personal-email', [$obscurer, 'prepareAcfPersonalEmail']))->toBe(10)
        ->and(Filters\has('acf/prepare_field/name=mobile-number', [$obscurer, 'prepareAcfMobileNumber']))->toBe(10)
        ->and(Filters\has('acf/prepare_field/name=landline-number', [$obscurer, 'prepareAcfLandlineNumber']))->toBe(10);

    // …and again on the full name because the short name differs.
    expect(Filters\has('acf/prepare_field/name=' . EMAIL_FIELD, [$obscurer, 'prepareAcfPersonalEmail']))->toBe(10)
        ->and(Filters\has('acf/prepare_field/name=' . MOBILE_FIELD, [$obscurer, 'prepareAcfMobileNumber']))->toBe(10)
        ->and(Filters\has('acf/prepare_field/name=' . LANDLINE_FIELD, [$obscurer, 'prepareAcfLandlineNumber']))->toBe(10);

    // update_value guards keyed by ACF field key, priority 10.
    expect(Filters\has('acf/update_value/key=' . EMAIL_KEY, [$obscurer, 'preservePersonalEmail']))->toBe(10)
        ->and(Filters\has('acf/update_value/key=' . MOBILE_KEY, [$obscurer, 'preserveMobileNumber']))->toBe(10)
        ->and(Filters\has('acf/update_value/key=' . LANDLINE_KEY, [$obscurer, 'preserveLandlineNumber']))->toBe(10);
});

// ─── format_value (frontend) ────────────────────────────────────
describe('format_value', function () {
    it('obscures the email for users without view', function () {
        expect(($this->makeObscurer)()->obscureAcfPersonalEmail('a@example.com', 1, []))
            ->toBe(PersonalDataPolicy::FIXED_PLACEHOLDER);
    });

    it('returns the real email for viewers', function () {
        grantView();

        expect(($this->makeObscurer)()->obscureAcfPersonalEmail('a@example.com', 1, []))->toBe('a@example.com');
    });

    it('leaves empty and non-string values untouched', function () {
        $obscurer = ($this->makeObscurer)();

        expect($obscurer->obscureAcfPersonalEmail('', 1, []))->toBe('')
            ->and($obscurer->obscureAcfMobileNumber(null, 1, []))->toBeNull()
            ->and($obscurer->obscureAcfMobileNumber(42, 1, []))->toBe(42);
    });

    it('obscures the mobile for users without view', function () {
        expect(($this->makeObscurer)()->obscureAcfMobileNumber('07700 900000', 1, []))
            ->toBe(PersonalDataPolicy::FIXED_PLACEHOLDER);
    });

    // A landline is obscured exactly as a mobile is — same placeholder, same
    // capability gate. It is a number that reaches a named individual at
    // home, so if anything the case is stronger.
    it('obscures the landline for users without view', function () {
        expect(($this->makeObscurer)()->obscureAcfLandlineNumber('0117 496 0000', 1, []))
            ->toBe(PersonalDataPolicy::FIXED_PLACEHOLDER);
    });

    it('returns the landline unchanged for viewers', function () {
        grantView();

        expect(($this->makeObscurer)()->obscureAcfLandlineNumber('0117 496 0000', 1, []))->toBe('0117 496 0000');
    });

    it('leaves an empty landline untouched', function () {
        $obscurer = ($this->makeObscurer)();

        expect($obscurer->obscureAcfLandlineNumber('', 1, []))->toBe('')
            ->and($obscurer->obscureAcfLandlineNumber(null, 1, []))->toBeNull();
    });
});

// ─── prepare_field (admin) ──────────────────────────────────────
describe('prepare_field', function () {
    it('masks the value as a placeholder for non-viewers', function () {
        $result = ($this->makeObscurer)()->prepareAcfPersonalEmail(['value' => 'a@example.com', 'name' => 'personal-email']);

        expect($result['value'])->toBe('')
            ->and($result['placeholder'])->toBe(PersonalDataPolicy::FIXED_PLACEHOLDER);
    });

    it('disables the input for viewers who cannot edit', function () {
        grantView();

        $result = ($this->makeObscurer)()->prepareAcfMobileNumber(['value' => '07700 900000']);

        // Real value retained, but the input is disabled.
        expect($result['value'])->toBe('07700 900000')
            ->and($result['disabled'])->toBe(1);
    });

    it('leaves the input editable for editors', function () {
        grantView();
        grantEdit();

        $result = ($this->makeObscurer)()->prepareAcfPersonalEmail(['value' => 'a@example.com']);

        expect($result['value'])->toBe('a@example.com')
            ->and($result)->not->toHaveKey('disabled');
    });

    it('passes false and empty values through', function () {
        $obscurer = ($this->makeObscurer)();

        expect($obscurer->prepareAcfPersonalEmail(false))->toBeFalse()
            ->and($obscurer->prepareAcfMobileNumber(['value' => '']))->toBe(['value' => '']);
    });

    it('masks the mobile value for non-viewers', function () {
        $result = ($this->makeObscurer)()->prepareAcfMobileNumber(['value' => '07700 900000', 'name' => 'mobile-number']);

        expect($result['value'])->toBe('')
            ->and($result['placeholder'])->toBe(PersonalDataPolicy::FIXED_PLACEHOLDER);
    });

    it('masks the landline value for non-viewers', function () {
        $result = ($this->makeObscurer)()->prepareAcfLandlineNumber(['value' => '0117 496 0000', 'name' => 'landline-number']);

        expect($result['value'])->toBe('')
            ->and($result['placeholder'])->toBe(PersonalDataPolicy::FIXED_PLACEHOLDER);
    });

    it('disables the landline for viewers who cannot edit', function () {
        grantView();

        $result = ($this->makeObscurer)()->prepareAcfLandlineNumber(['value' => '0117 496 0000']);

        expect($result['value'])->toBe('0117 496 0000')
            ->and($result['disabled'])->toBe(1);
    });

    it('passes a false or empty landline through', function () {
        $obscurer = ($this->makeObscurer)();

        expect($obscurer->prepareAcfLandlineNumber(false))->toBeFalse()
            ->and($obscurer->prepareAcfLandlineNumber(['value' => '']))->toBe(['value' => '']);
    });
});

// ─── update_value: the clear sentinel ───────────────────────────
describe('update_value', function () {
    it('converts the clear sentinel to an empty string', function () {
        grantEdit();

        expect(($this->makeObscurer)()->preservePersonalEmail(
            PersonalDataPolicy::CLEAR_SENTINEL,
            23462,
            ['name' => EMAIL_FIELD, 'key' => EMAIL_KEY]
        ))->toBe('');
    });

    it('preserves the existing value when a non-viewer editor submits blank', function () {
        // Editor who cannot view sees a placeholder; submitting blank must
        // keep the stored value rather than wiping it.
        grantEdit();
        $GLOBALS['scrutiny_test_acf_fields'][23462][EMAIL_FIELD] = 'keep@example.com';

        expect(($this->makeObscurer)()->preservePersonalEmail('', 23462, ['name' => EMAIL_FIELD, 'key' => EMAIL_KEY]))
            ->toBe('keep@example.com');
    });

    it('rejects a mobile change from a user who cannot edit', function () {
        // No edit capability: the stored mobile number must be preserved
        // against the attacker-supplied value.
        $GLOBALS['scrutiny_test_acf_fields'][23462][MOBILE_FIELD] = '07700 900000';

        expect(($this->makeObscurer)()->preserveMobileNumber('07999 999999', 23462, ['name' => MOBILE_FIELD, 'key' => MOBILE_KEY]))
            ->toBe('07700 900000');
    });

    it('rejects a landline change from a user who cannot edit', function () {
        $GLOBALS['scrutiny_test_acf_fields'][23462][LANDLINE_FIELD] = '0117 496 0000';

        expect(($this->makeObscurer)()->preserveLandlineNumber('0117 496 9999', 23462, ['name' => LANDLINE_FIELD, 'key' => LANDLINE_KEY]))
            ->toBe('0117 496 0000');
    });

    it('preserves a landline when a non-viewer editor submits blank', function () {
        grantEdit();
        $GLOBALS['scrutiny_test_acf_fields'][23462][LANDLINE_FIELD] = '0117 496 0000';

        expect(($this->makeObscurer)()->preserveLandlineNumber('', 23462, ['name' => LANDLINE_FIELD, 'key' => LANDLINE_KEY]))
            ->toBe('0117 496 0000');
    });

    // The Clear button submits a sentinel rather than an empty string, so
    // that an intentional clear is distinguishable from an untouched field —
    // which for a landline also drops the member's preference back to Mobile
    // downstream.
    it('clears a landline on the sentinel', function () {
        grantEdit();
        $GLOBALS['scrutiny_test_acf_fields'][23462][LANDLINE_FIELD] = '0117 496 0000';

        expect(($this->makeObscurer)()->preserveLandlineNumber(
            PersonalDataPolicy::CLEAR_SENTINEL,
            23462,
            ['name' => LANDLINE_FIELD, 'key' => LANDLINE_KEY]
        ))->toBe('');
    });
});
