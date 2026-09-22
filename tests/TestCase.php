<?php

declare(strict_types=1);

namespace Scrutiny\Tests;

use BleedingDeacons\WpMocks\TestCase as WpMocksTestCase;

/**
 * Base TestCase for the Scrutiny tests that need WordPress stand-ins.
 *
 * Brain Monkey's lifecycle, Mockery integration and the hook assertions come
 * from bleedingdeacons/wp-mocks. Ten test files used to open and close WP_Mock
 * by hand in their own setUp()/tearDown(); this is the one place that happens
 * now.
 *
 * Tests that only touch the globals-backed stubs in tests/bootstrap.php — the
 * Cleanup and Rest suites — run on Pest's default, plain PHPUnit. They never
 * needed a mocking framework and do not need one now.
 *
 * The tests are closure-based Pest files with no class to extend this from;
 * tests/Pest.php binds the files that need it.
 *
 * Note the stubs in tests/bootstrap.php are *not* reset by WpState::reset():
 * they are backed by $GLOBALS, which each test clears for itself, exactly as
 * before.
 */
abstract class TestCase extends WpMocksTestCase
{
}
