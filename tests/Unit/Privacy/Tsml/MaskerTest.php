<?php

declare(strict_types=1);

namespace Scrutiny\Tests\Unit\Privacy\Tsml;

use PHPUnit\Framework\TestCase;
use Scrutiny\Privacy\PersonalDataObscurer;
use Scrutiny\Privacy\Contacts\Masker;

/**
 * Tests for the TSML Masker.
 *
 * Masker is a pure function wrapper with no WordPress dependencies, so
 * it can be instantiated and exercised directly — no mocks, no
 * reflection.
 */
class MaskerTest extends TestCase
{
    /** @test */
    public function it_returns_empty_for_empty_input(): void
    {
        $this->assertSame('', (new Masker())->mask(''));
    }

    /** @test */
    public function it_masks_any_non_empty_value_to_the_fixed_placeholder(): void
    {
        $masker = new Masker();

        $this->assertSame(
            PersonalDataObscurer::FIXED_PLACEHOLDER,
            $masker->mask('Jane Doe')
        );
        $this->assertSame(
            PersonalDataObscurer::FIXED_PLACEHOLDER,
            $masker->mask('jane.doe@example.com')
        );
        $this->assertSame(
            PersonalDataObscurer::FIXED_PLACEHOLDER,
            $masker->mask('+44 7700 900123')
        );
    }

    /** @test */
    public function it_leaks_nothing_about_the_original_value(): void
    {
        $masker = new Masker();

        // Output must be identical regardless of input content or
        // length — no length signal, no first character, no TLD.
        $short = $masker->mask('a');
        $long  = $masker->mask('alice.wonderland@some-long-domain.example.co.uk');

        $this->assertSame($short, $long);
        $this->assertStringNotContainsString('a', $short);
        $this->assertStringNotContainsString('@', $long);
        $this->assertStringNotContainsString('uk', $long);
    }

    /** @test */
    public function it_treats_a_single_whitespace_character_as_a_real_value(): void
    {
        // The class only treats the empty string as "no value", by
        // design — a field containing a single space was entered by
        // someone and shouldn't be distinguishable from a normal entry.
        $this->assertSame(
            PersonalDataObscurer::FIXED_PLACEHOLDER,
            (new Masker())->mask(' ')
        );
    }

    /** @test */
    public function masked_output_matches_the_personal_data_obscurer_placeholder(): void
    {
        // The TSML masker and the ACF-field obscurer must produce the
        // same visible placeholder so admins see one consistent mask
        // across the whole edit UI.
        $this->assertSame(
            PersonalDataObscurer::FIXED_PLACEHOLDER,
            (new Masker())->mask('anything')
        );
    }
}
