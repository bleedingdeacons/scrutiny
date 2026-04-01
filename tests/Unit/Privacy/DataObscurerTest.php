<?php

declare(strict_types=1);

namespace Scrutiny\Tests\Unit\Privacy;

use PHPUnit\Framework\TestCase;
use Scrutiny\Privacy\DataObscurer;
use Scrutiny\Audit\Interfaces\AuditLogger;
use Mockery;

/**
 * Tests for DataObscurer masking logic
 *
 * These tests verify the obscuring algorithms independently of WordPress.
 */
class DataObscurerTest extends TestCase
{
    private DataObscurer $obscurer;

    protected function setUp(): void
    {
        parent::setUp();

        // DataObscurer constructor registers WP hooks, so we mock it
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
     * Helper to get a DataObscurer with mocked dependencies
     * without triggering WordPress hook registration.
     */
    private function createObscurer(): DataObscurer
    {
        $logger = Mockery::mock(AuditLogger::class);

        // Use reflection to create without calling constructor (avoids WP hooks)
        $reflection = new \ReflectionClass(DataObscurer::class);
        $instance = $reflection->newInstanceWithoutConstructor();

        // Set the logger property
        $loggerProp = $reflection->getProperty('logger');
        $loggerProp->setAccessible(true);
        $loggerProp->setValue($instance, $logger);

        return $instance;
    }

    // ─── Name Obscuring ──────────────────────────────────────────────

    /** @test */
    public function it_obscures_a_first_name_and_initial(): void
    {
        $obscurer = $this->createObscurer();

        $result = $obscurer->obscureName('John S');
        $this->assertSame('J••• S', $result);
    }

    /** @test */
    public function it_obscures_a_single_word_name(): void
    {
        $obscurer = $this->createObscurer();

        $result = $obscurer->obscureName('Alice');
        $this->assertSame('A••••', $result);
    }

    /** @test */
    public function it_preserves_single_character_parts(): void
    {
        $obscurer = $this->createObscurer();

        $result = $obscurer->obscureName('J S');
        $this->assertSame('J S', $result);
    }

    /** @test */
    public function it_returns_empty_string_for_empty_name(): void
    {
        $obscurer = $this->createObscurer();

        $this->assertSame('', $obscurer->obscureName(''));
    }

    /** @test */
    public function it_obscures_multi_word_names(): void
    {
        $obscurer = $this->createObscurer();

        $result = $obscurer->obscureName('Mary Jane W');
        $this->assertSame('M••• J••• W', $result);
    }

    // ─── Email Obscuring ─────────────────────────────────────────────

    /** @test */
    public function it_obscures_a_standard_email_address(): void
    {
        $obscurer = $this->createObscurer();

        $result = $obscurer->obscureEmail('john@example.com');
        // j + 3 bullets @ e + 6 bullets . com
        $this->assertStringStartsWith('j', $result);
        $this->assertStringContainsString('@', $result);
        $this->assertStringEndsWith('.com', $result);
        $this->assertStringContainsString('•', $result);
        // Original email should not be fully visible
        $this->assertNotSame('john@example.com', $result);
    }

    /** @test */
    public function it_preserves_first_char_of_local_and_domain(): void
    {
        $obscurer = $this->createObscurer();

        $result = $obscurer->obscureEmail('alice@gmail.com');
        $this->assertStringStartsWith('a', $result);
        $parts = explode('@', $result);
        $this->assertStringStartsWith('g', $parts[1]);
    }

    /** @test */
    public function it_returns_empty_for_empty_email(): void
    {
        $obscurer = $this->createObscurer();

        $this->assertSame('', $obscurer->obscureEmail(''));
    }

    /** @test */
    public function it_handles_email_without_at_sign(): void
    {
        $obscurer = $this->createObscurer();

        $result = $obscurer->obscureEmail('notanemail');
        $this->assertStringNotContainsString('notanemail', $result);
    }

    // ─── Phone Obscuring ─────────────────────────────────────────────

    /** @test */
    public function it_obscures_a_phone_number_keeping_last_three_digits(): void
    {
        $obscurer = $this->createObscurer();

        $result = $obscurer->obscurePhone('07700 900123');
        $this->assertStringEndsWith('123', $result);
        $this->assertStringContainsString('•', $result);
    }

    /** @test */
    public function it_obscures_international_format_phone(): void
    {
        $obscurer = $this->createObscurer();

        $result = $obscurer->obscurePhone('+44 7700 900456');
        $this->assertStringEndsWith('456', $result);
    }

    /** @test */
    public function it_returns_empty_for_empty_phone(): void
    {
        $obscurer = $this->createObscurer();

        $this->assertSame('', $obscurer->obscurePhone(''));
    }

    /** @test */
    public function it_handles_short_phone_numbers(): void
    {
        $obscurer = $this->createObscurer();

        $result = $obscurer->obscurePhone('123');
        // With only 3 digits, all should be visible
        $this->assertSame('123', $result);
    }

    /** @test */
    public function it_handles_very_short_phone_numbers(): void
    {
        $obscurer = $this->createObscurer();

        $result = $obscurer->obscurePhone('12');
        // 2 digits: last 3 requested but only 2 exist, so all visible
        $this->assertStringEndsWith('12', $result);
    }
}
