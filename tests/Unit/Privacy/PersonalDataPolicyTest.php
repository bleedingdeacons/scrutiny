<?php

declare(strict_types=1);

namespace Scrutiny\Tests\Unit\Privacy;

use PHPUnit\Framework\TestCase;
use Scrutiny\Privacy\PersonalDataPolicy;

/**
 * Tests for PersonalDataPolicy's pure obscuring and masking helpers.
 *
 * The policy class has no WordPress side effects in its constructor, so
 * it can be instantiated directly — no reflection, no mocks. Capability
 * checks (currentUserCanView / currentUserCanEdit / tier) are covered
 * elsewhere against real WP_Mock since they call current_user_can().
 */
class PersonalDataPolicyTest extends TestCase
{
    private PersonalDataPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new PersonalDataPolicy();
    }

    // ─── Email Obscuring ─────────────────────────────────────────────

    /** @test */
    public function it_obscures_any_non_empty_email_to_fixed_placeholder(): void
    {
        $this->assertSame(
            PersonalDataPolicy::FIXED_PLACEHOLDER,
            $this->policy->obscureEmail('john@example.com')
        );
    }

    /** @test */
    public function it_leaks_no_characters_from_the_original_email(): void
    {
        // No first letter, no TLD, no length signal — the output must be
        // identical regardless of input content or length.
        $short = $this->policy->obscureEmail('a@b.co');
        $long  = $this->policy->obscureEmail('alice.wonderland@some-long-domain.example.co.uk');

        $this->assertSame($short, $long);
        $this->assertStringNotContainsString('a', $short);
        $this->assertStringNotContainsString('@', $short);
        $this->assertStringNotContainsString('.', $short);
        $this->assertStringNotContainsString('co', $short);
        $this->assertStringNotContainsString('uk', $long);
    }

    /** @test */
    public function it_returns_empty_for_empty_email(): void
    {
        $this->assertSame('', $this->policy->obscureEmail(''));
    }

    /** @test */
    public function it_still_obscures_values_that_are_not_well_formed_emails(): void
    {
        // A stored value that doesn't contain an "@" still counts as data
        // the viewer must not see — return the same fixed placeholder rather
        // than the raw value.
        $result = $this->policy->obscureEmail('notanemail');
        $this->assertSame(PersonalDataPolicy::FIXED_PLACEHOLDER, $result);
        $this->assertStringNotContainsString('notanemail', $result);
    }

    // ─── Phone Obscuring ─────────────────────────────────────────────

    /** @test */
    public function it_obscures_any_non_empty_phone_to_fixed_placeholder(): void
    {
        $this->assertSame(
            PersonalDataPolicy::FIXED_PLACEHOLDER,
            $this->policy->obscurePhone('07700 900123')
        );
    }

    /** @test */
    public function it_leaks_no_digits_or_formatting_from_the_original_phone(): void
    {
        $short = $this->policy->obscurePhone('123');
        $long  = $this->policy->obscurePhone('+44 7700 900123');

        // Output is identical regardless of length — no digit count leak.
        $this->assertSame($short, $long);

        // None of the last-N digits survive.
        $this->assertStringNotContainsString('123', $short);
        $this->assertStringNotContainsString('123', $long);
    }

    /** @test */
    public function it_returns_empty_for_empty_phone(): void
    {
        $this->assertSame('', $this->policy->obscurePhone(''));
    }

    /** @test */
    public function it_obscures_short_phone_numbers_the_same_as_long_ones(): void
    {
        // Previously, short numbers (≤3 digits) were returned unchanged —
        // that leak is fixed: any non-empty input yields the fixed placeholder.
        $this->assertSame(PersonalDataPolicy::FIXED_PLACEHOLDER, $this->policy->obscurePhone('123'));
        $this->assertSame(PersonalDataPolicy::FIXED_PLACEHOLDER, $this->policy->obscurePhone('12'));
        $this->assertSame(PersonalDataPolicy::FIXED_PLACEHOLDER, $this->policy->obscurePhone('1'));
    }

    // ─── Contact-field Masking ───────────────────────────────────────

    /** @test */
    public function mask_contact_field_returns_empty_for_empty_input(): void
    {
        $this->assertSame('', $this->policy->maskContactField(''));
    }

    /** @test */
    public function mask_contact_field_returns_fixed_placeholder_for_any_non_empty_value(): void
    {
        $this->assertSame(
            PersonalDataPolicy::FIXED_PLACEHOLDER,
            $this->policy->maskContactField('Jane Doe')
        );
        $this->assertSame(
            PersonalDataPolicy::FIXED_PLACEHOLDER,
            $this->policy->maskContactField('jane.doe@example.com')
        );
        $this->assertSame(
            PersonalDataPolicy::FIXED_PLACEHOLDER,
            $this->policy->maskContactField('+44 7700 900123')
        );
    }

    /** @test */
    public function mask_contact_field_leaks_nothing_about_the_original_value(): void
    {
        // Output must be identical regardless of input content or length —
        // no length signal, no first character, no TLD.
        $short = $this->policy->maskContactField('a');
        $long  = $this->policy->maskContactField('alice.wonderland@some-long-domain.example.co.uk');

        $this->assertSame($short, $long);
        $this->assertStringNotContainsString('a', $short);
        $this->assertStringNotContainsString('@', $long);
        $this->assertStringNotContainsString('uk', $long);
    }

    /** @test */
    public function mask_contact_field_treats_a_single_whitespace_character_as_a_real_value(): void
    {
        // Only the empty string counts as "no value", by design — a field
        // containing a single space was entered by someone and shouldn't
        // be distinguishable from a normal entry.
        $this->assertSame(
            PersonalDataPolicy::FIXED_PLACEHOLDER,
            $this->policy->maskContactField(' ')
        );
    }
}
