<?php

declare(strict_types=1);

namespace Scrutiny\Tests\Unit\Audit;

use Brain\Monkey\Functions;
use Scrutiny\Audit\AuditTimestamp;
use Scrutiny\Tests\TestCase;

/**
 * Tests for AuditTimestamp, the UTC → site-timezone conversion shared by the
 * Audit Log admin page and the GdprAuditHistory field.
 *
 * The formatting itself belongs to wp_date(); what matters here is that the
 * stored value is interpreted as UTC, and that an unparseable one survives
 * rather than blanking the cell.
 *
 * The remaining branch — wp_date() returning false — is not covered. Patchwork
 * keeps the redefined function's signature, and wp-mocks declares
 * `wp_date(...): string`, so a stub cannot return the false the WordPress
 * function is documented to return. The guard stays because PHPStan types it
 * string|false; only the test for it is missing.
 *
 * @covers \Scrutiny\Audit\AuditTimestamp
 */
class AuditTimestampTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // get_option() is defined in tests/bootstrap.php itself, which was
        // already being included when Patchwork loaded, so Brain Monkey cannot
        // redefine it. Its globals-backed store is the way in.
        $GLOBALS['scrutiny_test_options'] = ['date_format' => 'H:i', 'time_format' => ''];
    }

    protected function tearDown(): void
    {
        $GLOBALS['scrutiny_test_options'] = [];
        parent::tearDown();
    }

    /** @test */
    public function it_reads_the_stored_value_as_utc(): void
    {
        // The column is UTC (GdprAuditLogger writes gmdate()). Parsing it in
        // the server's own timezone instead would shift every entry by the
        // offset — the kind of error nobody notices until an audit is
        // questioned.
        $captured = null;

        Functions\when('wp_timezone')->justReturn(new \DateTimeZone('Europe/London'));
        Functions\when('wp_date')->alias(
            static function (string $format, ?int $ts = null) use (&$captured): string {
                $captured = $ts;
                return 'formatted';
            }
        );

        $this->assertSame('formatted', AuditTimestamp::forDisplay('2026-03-01 09:30:00'));
        $this->assertSame(strtotime('2026-03-01 09:30:00 UTC'), $captured);
    }

    /** @test */
    public function it_formats_with_the_sites_own_date_and_time_settings(): void
    {
        // Two separate options, joined with a space — not a hardcoded format.
        $captured = null;

        $GLOBALS['scrutiny_test_options'] = ['date_format' => 'j M Y', 'time_format' => 'g:ia'];

        Functions\when('wp_timezone')->justReturn(new \DateTimeZone('UTC'));
        Functions\when('wp_date')->alias(
            static function (string $format) use (&$captured): string {
                $captured = $format;
                return 'formatted';
            }
        );

        AuditTimestamp::forDisplay('2026-03-01 09:30:00');

        $this->assertSame('j M Y g:ia', $captured);
    }

    /** @test */
    public function it_returns_an_unparseable_value_unchanged(): void
    {
        Functions\when('wp_timezone')->justReturn(new \DateTimeZone('UTC'));

        $this->assertSame('not a date at all', AuditTimestamp::forDisplay('not a date at all'));
    }
}
