<?php

declare(strict_types=1);

namespace Scrutiny\Tests\Unit\Privacy;

use PHPUnit\Framework\TestCase;
use Scrutiny\Privacy\PersonalDataObscurer;
use Scrutiny\Audit\Interfaces\AuditLogger;
use Mockery;

/**
 * Tests for PersonalDataObscurer masking logic
 *
 * These tests verify the obscuring algorithms independently of WordPress.
 */
class DataObscurerTest extends TestCase
{
    private PersonalDataObscurer $obscurer;

    protected function setUp(): void
    {
        parent::setUp();

        // PersonalDataObscurer constructor registers WP hooks, so we mock it
        // by testing the public masking methods directly via reflection
        // or by creating the object in a context where hooks are no-ops.
        // For pure logic tests we use a lightweight approach.
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Helper to get a PersonalDataObscurer with mocked dependencies
     * without triggering WordPress hook registration.
     */
    private function createObscurer(): PersonalDataObscurer
    {
        $logger = Mockery::mock(AuditLogger::class);

        // Use reflection to create without calling constructor (avoids WP hooks)
        $reflection = new \ReflectionClass(PersonalDataObscurer::class);
        $instance = $reflection->newInstanceWithoutConstructor();

        // Set the logger property
        $loggerProp = $reflection->getProperty('logger');
        $loggerProp->setAccessible(true);
        $loggerProp->setValue($instance, $logger);

        return $instance;
    }

    // ─── Email Obscuring ─────────────────────────────────────────────

    /** @test */
    public function it_obscures_any_non_empty_email_to_fixed_placeholder(): void
    {
        $obscurer = $this->createObscurer();

        $this->assertSame(
            PersonalDataObscurer::FIXED_PLACEHOLDER,
            $obscurer->obscureEmail('john@example.com')
        );
    }

    /** @test */
    public function it_leaks_no_characters_from_the_original_email(): void
    {
        $obscurer = $this->createObscurer();

        // No first letter, no TLD, no length signal — the output must be
        // identical regardless of input content or length.
        $short = $obscurer->obscureEmail('a@b.co');
        $long  = $obscurer->obscureEmail('alice.wonderland@some-long-domain.example.co.uk');

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
        $obscurer = $this->createObscurer();

        $this->assertSame('', $obscurer->obscureEmail(''));
    }

    /** @test */
    public function it_still_obscures_values_that_are_not_well_formed_emails(): void
    {
        $obscurer = $this->createObscurer();

        // A stored value that doesn't contain an "@" still counts as data
        // the viewer must not see — return the same fixed placeholder rather
        // than the raw value.
        $result = $obscurer->obscureEmail('notanemail');
        $this->assertSame(PersonalDataObscurer::FIXED_PLACEHOLDER, $result);
        $this->assertStringNotContainsString('notanemail', $result);
    }

    // ─── Phone Obscuring ─────────────────────────────────────────────

    /** @test */
    public function it_obscures_any_non_empty_phone_to_fixed_placeholder(): void
    {
        $obscurer = $this->createObscurer();

        $this->assertSame(
            PersonalDataObscurer::FIXED_PLACEHOLDER,
            $obscurer->obscurePhone('07700 900123')
        );
    }

    /** @test */
    public function it_leaks_no_digits_or_formatting_from_the_original_phone(): void
    {
        $obscurer = $this->createObscurer();

        $short = $obscurer->obscurePhone('123');
        $long  = $obscurer->obscurePhone('+44 7700 900123');

        // Output is identical regardless of length — no digit count leak.
        $this->assertSame($short, $long);

        // None of the last-N digits survive.
        $this->assertStringNotContainsString('123', $short);
        $this->assertStringNotContainsString('123', $long);
    }

    /** @test */
    public function it_returns_empty_for_empty_phone(): void
    {
        $obscurer = $this->createObscurer();

        $this->assertSame('', $obscurer->obscurePhone(''));
    }

    /** @test */
    public function it_obscures_short_phone_numbers_the_same_as_long_ones(): void
    {
        $obscurer = $this->createObscurer();

        // Previously, short numbers (≤3 digits) were returned unchanged —
        // that leak is fixed: any non-empty input yields the fixed placeholder.
        $this->assertSame(PersonalDataObscurer::FIXED_PLACEHOLDER, $obscurer->obscurePhone('123'));
        $this->assertSame(PersonalDataObscurer::FIXED_PLACEHOLDER, $obscurer->obscurePhone('12'));
        $this->assertSame(PersonalDataObscurer::FIXED_PLACEHOLDER, $obscurer->obscurePhone('1'));
    }
}