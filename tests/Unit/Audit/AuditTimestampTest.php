<?php

declare(strict_types=1);

namespace Scrutiny\Tests\Unit\Audit;

use Brain\Monkey\Functions;
use Scrutiny\Audit\AuditTimestamp;

/*
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
 */

covers(AuditTimestamp::class);

beforeEach(function () {
    // get_option() is defined in tests/bootstrap.php itself, which was already
    // being included when Patchwork loaded, so Brain Monkey cannot redefine
    // it. Its globals-backed store is the way in.
    $GLOBALS['scrutiny_test_options'] = ['date_format' => 'H:i', 'time_format' => ''];
});

afterEach(function () {
    $GLOBALS['scrutiny_test_options'] = [];
});

it('reads the stored value as UTC', function () {
    // The column is UTC (GdprAuditLogger writes gmdate()). Parsing it in the
    // server's own timezone instead would shift every entry by the offset —
    // the kind of error nobody notices until an audit is questioned.
    $captured = null;

    Functions\when('wp_timezone')->justReturn(new \DateTimeZone('Europe/London'));
    Functions\when('wp_date')->alias(
        static function (string $format, ?int $ts = null) use (&$captured): string {
            $captured = $ts;
            return 'formatted';
        }
    );

    expect(AuditTimestamp::forDisplay('2026-03-01 09:30:00'))->toBe('formatted')
        ->and($captured)->toBe(strtotime('2026-03-01 09:30:00 UTC'));
});

it("formats with the site's own date and time settings", function () {
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

    expect($captured)->toBe('j M Y g:ia');
});

it('returns an unparseable value unchanged', function () {
    Functions\when('wp_timezone')->justReturn(new \DateTimeZone('UTC'));

    expect(AuditTimestamp::forDisplay('not a date at all'))->toBe('not a date at all');
});
