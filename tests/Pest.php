<?php

declare(strict_types=1);

// Pest configuration.
//
// The split tests/TestCase.php describes still holds. Tests that need the
// WordPress stand-ins — Brain Monkey's lifecycle, Mockery integration and the
// hook assertions — run on Scrutiny\Tests\TestCase, which wraps wp-mocks'. The
// Cleanup, Rest, Shortcodes and Testing suites, and the pure policy and
// tracker tests, only touch the globals-backed stubs in tests/bootstrap.php and
// stay on Pest's default, plain PHPUnit.
//
// So this list is load-bearing: a test that reaches Brain Monkey from a file
// not named here finds none of its functions defined.
//
// Two files stay PHPUnit classes rather than Pest closures: the tests that
// define REST_REQUEST, which cannot be undone once defined, so they must run in
// a separate process — and Pest refuses process isolation outright. See
// Privacy/MemberFieldsObscurerTest.php and
// Privacy/ResponderCertificationGuardRestRequestTest.php.

use Scrutiny\Tests\TestCase;

pest()->extend(TestCase::class)->in(
    'Unit/Admin',
    'Unit/Fields',
    'Unit/PluginWiringTest.php',
    'Unit/Audit/AuditDetailTest.php',
    'Unit/Audit/AuditLoggerTest.php',
    'Unit/Audit/AuditTimestampTest.php',
    'Unit/Audit/AuditTrackerConstructionTest.php',
    'Unit/Audit/AuditTrackerCoverageTest.php',
    'Unit/Audit/GdprAuditLoggerTest.php',
    'Unit/Audit/GdprAuditRepositoryTest.php',
    'Unit/Privacy/GroupFieldsObscurerTest.php',
    'Unit/Privacy/MemberFieldsObscurerObscuringTest.php',
    'Unit/Privacy/PersonalDataFieldsTest.php',
    'Unit/Privacy/ResponderCertificationGuardTest.php',
);

/**
 * Runs $render inside an output buffer and returns what it printed, with
 * Windows line endings normalised so assertions on multi-line markup hold on
 * any checkout.
 *
 * The buffer is closed in a finally, so a render that throws — wp_die() is a
 * WpDieException under the shared stubs — cannot leave it open and have
 * PHPUnit flag the test as risky.
 */
function captureOutput(callable $render): string
{
    ob_start();

    try {
        $render();
    } finally {
        $html = (string) ob_get_clean();
    }

    return str_replace("\r\n", "\n", $html);
}
