<?php

declare(strict_types=1);

namespace Scrutiny\Tests\Unit\Privacy;

use Scrutiny\Privacy\PersonalDataPolicy;

/*
 * Tests for PersonalDataPolicy's pure obscuring and masking helpers.
 *
 * The policy class has no WordPress side effects in its constructor, so it can
 * be instantiated directly — no reflection, no mocks. Capability checks
 * (currentUserCanView / currentUserCanEdit / tier) are covered elsewhere
 * against the bootstrap's capability store, since they call
 * current_user_can().
 */

beforeEach(function () {
    $this->policy = new PersonalDataPolicy();
});

// ─── Email Obscuring ─────────────────────────────────────────────
describe('obscureEmail', function () {
    it('obscures any non-empty email to the fixed placeholder', function () {
        expect($this->policy->obscureEmail('john@example.com'))->toBe(PersonalDataPolicy::FIXED_PLACEHOLDER);
    });

    it('leaks no characters from the original email', function () {
        // No first letter, no TLD, no length signal — the output must be
        // identical regardless of input content or length.
        $short = $this->policy->obscureEmail('a@b.co');
        $long  = $this->policy->obscureEmail('alice.wonderland@some-long-domain.example.co.uk');

        expect($short)->toBe($long)
            ->not->toContain('a')
            ->not->toContain('@')
            ->not->toContain('.')
            ->not->toContain('co')
            ->and($long)->not->toContain('uk');
    });

    it('returns empty for an empty email', function () {
        expect($this->policy->obscureEmail(''))->toBe('');
    });

    it('still obscures values that are not well-formed emails', function () {
        // A stored value that doesn't contain an "@" still counts as data the
        // viewer must not see — return the same fixed placeholder rather than
        // the raw value.
        expect($this->policy->obscureEmail('notanemail'))
            ->toBe(PersonalDataPolicy::FIXED_PLACEHOLDER)
            ->not->toContain('notanemail');
    });
});

// ─── Phone Obscuring ─────────────────────────────────────────────
describe('obscurePhone', function () {
    it('obscures any non-empty phone to the fixed placeholder', function () {
        expect($this->policy->obscurePhone('07700 900123'))->toBe(PersonalDataPolicy::FIXED_PLACEHOLDER);
    });

    it('leaks no digits or formatting from the original phone', function () {
        $short = $this->policy->obscurePhone('123');
        $long  = $this->policy->obscurePhone('+44 7700 900123');

        // Output is identical regardless of length — no digit count leak.
        expect($short)->toBe($long)
            // None of the last-N digits survive.
            ->not->toContain('123')
            ->and($long)->not->toContain('123');
    });

    it('returns empty for an empty phone', function () {
        expect($this->policy->obscurePhone(''))->toBe('');
    });

    it('obscures short phone numbers the same as long ones', function (string $phone) {
        // Previously, short numbers (≤3 digits) were returned unchanged — that
        // leak is fixed: any non-empty input yields the fixed placeholder.
        expect($this->policy->obscurePhone($phone))->toBe(PersonalDataPolicy::FIXED_PLACEHOLDER);
    })->with(['123', '12', '1']);
});

// ─── Contact-field Masking ───────────────────────────────────────
describe('maskContactField', function () {
    it('returns empty for empty input', function () {
        expect($this->policy->maskContactField(''))->toBe('');
    });

    it('returns the fixed placeholder for any non-empty value', function (string $value) {
        expect($this->policy->maskContactField($value))->toBe(PersonalDataPolicy::FIXED_PLACEHOLDER);
    })->with(['Jane Doe', 'jane.doe@example.com', '+44 7700 900123']);

    it('leaks nothing about the original value', function () {
        // Output must be identical regardless of input content or length — no
        // length signal, no first character, no TLD.
        $short = $this->policy->maskContactField('a');
        $long  = $this->policy->maskContactField('alice.wonderland@some-long-domain.example.co.uk');

        expect($short)->toBe($long)
            ->not->toContain('a')
            ->and($long)
            ->not->toContain('@')
            ->not->toContain('uk');
    });

    it('treats a single whitespace character as a real value', function () {
        // Only the empty string counts as "no value", by design — a field
        // containing a single space was entered by someone and shouldn't be
        // distinguishable from a normal entry.
        expect($this->policy->maskContactField(' '))->toBe(PersonalDataPolicy::FIXED_PLACEHOLDER);
    });
});
