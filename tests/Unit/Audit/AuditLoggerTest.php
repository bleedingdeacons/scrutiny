<?php

declare(strict_types=1);

namespace Scrutiny\Tests\Unit\Audit;

use BleedingDeacons\WpMocks\WpState;
use Brain\Monkey\Functions;
use Mockery;
use Scrutiny\Audit\GdprAuditLogger;
use Scrutiny\Audit\Interfaces\AuditLogger;
use Scrutiny\Audit\Interfaces\AuditRepository;
use Scrutiny\Privacy\PersonalDataFields;

/*
 * Tests for GdprAuditLogger.
 */

/**
 * Create a GdprAuditLogger without WP dependencies by using reflection.
 */
function loggerOver(AuditRepository $repository): GdprAuditLogger
{
    $reflection = new \ReflectionClass(GdprAuditLogger::class);
    $instance = $reflection->newInstanceWithoutConstructor();

    // No setAccessible() call: a no-op since PHP 8.1 — this plugin's floor —
    // and deprecated as of 8.5.
    $reflection->getProperty('repository')->setValue($instance, $repository);

    return $instance;
}

/**
 * A current-user stand-in with the given login.
 *
 * wp-mocks types wp_get_current_user() as returning WP_User, and Patchwork
 * keeps a function's original signature when Brain Monkey redefines it, so an
 * ad-hoc stdClass is a TypeError now — which is the more faithful behaviour
 * anyway: real WordPress always hands back a WP_User.
 */
function userWithLogin(string $login): \WP_User
{
    $user = new \WP_User();
    $user->user_login = $login;

    return $user;
}

it('calls log for each field in a batch', function () {
    // Previously this test could not call logBatch() at all — log() reaches
    // for wp_get_current_user() and get_current_user_id() — so it set a
    // times(3) expectation it never met and asserted something unrelated.
    // Both are available now, so it exercises the real delegation.
    Functions\when('wp_get_current_user')->justReturn(userWithLogin('auditor'));
    WpState::$currentUserId = 7;

    $fields = [
        PersonalDataFields::PERSONAL_EMAIL,
        PersonalDataFields::MOBILE_NUMBER,
    ];

    $inserted = [];
    $repository = Mockery::mock(AuditRepository::class);
    $repository->shouldReceive('insert')
        ->times(count($fields))
        ->andReturnUsing(function (array $row) use (&$inserted): int {
            $inserted[] = $row;
            return 1;
        });

    loggerOver($repository)->logBatch(
        AuditLogger::ACTION_VIEW,
        AuditLogger::ENTITY_MEMBER,
        42,
        $fields,
        'Bulk export'
    );

    expect(array_column($inserted, 'field_name'))->toBe($fields)
        ->and(array_column($inserted, 'entity_id'))->toBe([42, 42])
        ->and(array_column($inserted, 'user_login'))->toBe(['auditor', 'auditor']);
});

it('defines the personal data fields correctly', function () {
    // Hyphens, not underscores. These values are the audit log's field_name
    // column and the keys of PersonalDataFields::LABELS, and both have used
    // the hyphenated form throughout.
    expect(PersonalDataFields::PERSONAL_EMAIL)->toBe('personal-email')
        ->and(PersonalDataFields::MOBILE_NUMBER)->toBe('mobile-number')
        ->and(PersonalDataFields::LANDLINE_NUMBER)->toBe('landline-number');
});

it('lists every field in ALL_FIELDS', function () {
    expect(PersonalDataFields::ALL_FIELDS)
        ->toContain(PersonalDataFields::PERSONAL_EMAIL, PersonalDataFields::MOBILE_NUMBER, PersonalDataFields::LANDLINE_NUMBER)
        ->toHaveCount(3);
});

it('has a label for every field', function () {
    foreach (PersonalDataFields::ALL_FIELDS as $field) {
        expect(PersonalDataFields::LABELS)->toHaveKey($field)
            ->and(PersonalDataFields::LABELS[$field])->not->toBeEmpty();
    }
});
