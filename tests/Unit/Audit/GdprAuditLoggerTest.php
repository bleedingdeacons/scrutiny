<?php

declare(strict_types=1);

namespace Scrutiny\Tests\Unit\Audit;

use BleedingDeacons\WpMocks\WpState;
use Brain\Monkey\Functions;
use Mockery;
use Scrutiny\Audit\GdprAuditLogger;
use Scrutiny\Audit\Interfaces\AuditLogger;
use Scrutiny\Audit\Interfaces\AuditRepository;

/*
 * Tests for GdprAuditLogger — the entry assembly, current-user capture and IP
 * anonymisation performed before delegating to the repository.
 */

covers(GdprAuditLogger::class);

/**
 * A current-user stand-in with the given login.
 *
 * wp-mocks types wp_get_current_user() as returning WP_User, and Patchwork
 * keeps a function's original signature when Brain Monkey redefines it, so an
 * ad-hoc stdClass is a TypeError now — which is the more faithful behaviour
 * anyway: real WordPress always hands back a WP_User.
 */
function currentUserNamed(string $login): \WP_User
{
    $user = new \WP_User();
    $user->user_login = $login;

    return $user;
}

/**
 * A repository that expects exactly one insert and records the row into $row.
 */
function capturingRepository(mixed &$row): AuditRepository
{
    $repository = Mockery::mock(AuditRepository::class);
    $repository->shouldReceive('insert')->once()->andReturnUsing(
        function (array $entry) use (&$row) {
            $row = $entry;
            return 1;
        }
    );

    return $repository;
}

afterEach(function () {
    unset($_SERVER['REMOTE_ADDR']);
});

it('assembles an entry with the current user and an anonymised IPv4 address', function () {
    $_SERVER['REMOTE_ADDR'] = '203.0.113.42';

    Functions\when('wp_get_current_user')->justReturn(currentUserNamed('admin'));
    WpState::$currentUserId = 7;

    $captured = null;

    (new GdprAuditLogger(capturingRepository($captured)))->log(
        AuditLogger::ACTION_VIEW,
        AuditLogger::ENTITY_MEMBER,
        42,
        'personal-email',
        'accessed'
    );

    expect($captured)
        ->action->toBe('view')
        ->entity_type->toBe('member')
        ->entity_id->toBe(42)
        ->field_name->toBe('personal-email')
        ->detail->toBe('accessed')
        ->user_id->toBe(7)
        ->user_login->toBe('admin')
        // Last IPv4 octet zeroed for GDPR.
        ->ip_address->toBe('203.0.113.0')
        ->logged_at->toMatch('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/');
});

it('anonymises an IPv6 address', function () {
    $_SERVER['REMOTE_ADDR'] = '2001:db8:1234:5678:9abc:def0:1234:5678';

    Functions\when('wp_get_current_user')->justReturn(currentUserNamed('admin'));
    WpState::$currentUserId = 1;

    $captured = null;

    (new GdprAuditLogger(capturingRepository($captured)))->log('view', 'member', 1, 'personal-email');

    // The last 80 bits are zeroed, leaving the /48 network prefix.
    expect($captured['ip_address'])->toBe('2001:db8:1234::');
});

it('falls back for a missing or invalid IP', function () {
    $_SERVER['REMOTE_ADDR'] = 'not-an-ip';

    $noLogin = new \WP_User();
    // The 'system' fallback is reached through ?? , which uses isset()
    // semantics — so an unset typed property takes that branch without
    // erroring, exactly as a user object with no login would.
    unset($noLogin->user_login);
    Functions\when('wp_get_current_user')->justReturn($noLogin);
    WpState::$currentUserId = 0;

    $captured = null;

    (new GdprAuditLogger(capturingRepository($captured)))->log('view', 'member', 1, 'personal-email');

    expect($captured['ip_address'])->toBe('0.0.0.0')
        // No user_login property on the current user → 'system'.
        ->and($captured['user_login'])->toBe('system');
});

it('logs one entry per field in a batch', function () {
    Functions\when('wp_get_current_user')->justReturn(currentUserNamed('admin'));
    WpState::$currentUserId = 1;
    $_SERVER['REMOTE_ADDR'] = '198.51.100.5';

    $repository = Mockery::mock(AuditRepository::class);
    $repository->shouldReceive('insert')->times(3)->andReturn(1);

    (new GdprAuditLogger($repository))->logBatch(
        'delete',
        'member',
        9,
        ['personal-email', 'mobile-number', 'gdpr-accepted'],
        'Member deleted'
    );
});
