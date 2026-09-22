<?php

declare(strict_types=1);

namespace Scrutiny\Tests\Unit\Testing;

use Scrutiny\Audit\Interfaces\AuditLogger;
use Scrutiny\Testing\Doubles\SpyAuditLogger;

/*
 * The audit spy Scrutiny ships for the rest of the suite.
 *
 * PHP enforces the contract at class-load time — a method added to AuditLogger
 * fails this file before it fails any consumer, which is the reason the double
 * lives here rather than in each consuming plugin. What is asserted below is
 * the behaviour those consumers lean on and a signature check would not catch:
 * that logBatch() is recorded both raw and fanned out, so the four doubles this
 * replaces are all satisfied by one class.
 */

// No covers(): src/Testing is excluded from coverage in phpunit.xml, so naming
// SpyAuditLogger as a covered target attributes nothing and PHPUnit 13 rejects
// it outright. Pest has no coversNothing(), and nothing this file runs is in
// the coverage source set anyway (AuditLogger lives in the equally excluded
// src/Audit/Interfaces).

it('satisfies the contract and starts empty', function () {
    $spy = new SpyAuditLogger();

    expect($spy)->toBeInstanceOf(AuditLogger::class)
        ->and($spy->entries)->toBe([])
        ->and($spy->batches)->toBe([]);
});

it('records each log call', function () {
    $spy = new SpyAuditLogger();

    $spy->log(AuditLogger::ACTION_VIEW, AuditLogger::ENTITY_MEMBER, 7, 'personal_email', 'why');

    expect($spy->entries)->toHaveCount(1)
        ->and($spy->entries[0])->toBe([
            'action' => 'view',
            'entityType' => 'member',
            'entityId' => 7,
            'fieldName' => 'personal_email',
            'detail' => 'why',
        ])
        ->and($spy->batches)->toBe([], 'a plain log() is not a batch');
});

it('records logBatch both raw and fanned out', function () {
    // The two views the replaced doubles each wanted: Reach's SpyAuditLogger
    // asserted on the unexpanded batch, its RecordingAuditLogger and Rabbit's
    // CapturingAuditLogger on the per-field rows. Both are populated, so one
    // class serves both.
    $spy = new SpyAuditLogger();

    $spy->logBatch(AuditLogger::ACTION_CALL, AuditLogger::ENTITY_MEMBER, 7, ['a', 'b'], 'detail');

    expect($spy->batches)->toHaveCount(1)
        ->and($spy->batches[0]['fieldNames'])->toBe(['a', 'b'])
        ->and($spy->entries)->toHaveCount(2)
        ->and(array_column($spy->entries, 'fieldName'))->toBe(['a', 'b'])
        ->and($spy->actions())->toBe(['call', 'call']);
});

it('keeps the detail and action on both paths', function () {
    $spy = new SpyAuditLogger();

    $spy->log(AuditLogger::ACTION_MESSAGE, AuditLogger::ENTITY_MEMBER, 1, 'mobile_number');
    $spy->logBatch(AuditLogger::ACTION_EXPORT, AuditLogger::ENTITY_GROUP, 2, ['x'], 'sent');

    expect($spy->actions())->toBe(['message', 'export'])
        ->and($spy->entries[0]['detail'])->toBe('', 'detail defaults to empty')
        ->and($spy->entries[1]['detail'])->toBe('sent');
});
