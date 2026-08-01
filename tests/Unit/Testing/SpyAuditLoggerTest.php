<?php

declare(strict_types=1);

namespace Scrutiny\Tests\Unit\Testing;

use PHPUnit\Framework\TestCase;
use Scrutiny\Audit\Interfaces\AuditLogger;
use Scrutiny\Testing\Doubles\SpyAuditLogger;

/**
 * The audit spy Scrutiny ships for the rest of the suite.
 *
 * PHP enforces the contract at class-load time — a method added to AuditLogger
 * fails this file before it fails any consumer, which is the reason the double
 * lives here rather than in each consuming plugin. What is asserted below is
 * the behaviour those consumers lean on and a signature check would not catch:
 * that logBatch() is recorded both raw and fanned out, so the four doubles this
 * replaces are all satisfied by one class.
 *
 * @covers \Scrutiny\Testing\Doubles\SpyAuditLogger
 */
final class SpyAuditLoggerTest extends TestCase
{
    public function testItSatisfiesTheContractAndStartsEmpty(): void
    {
        $spy = new SpyAuditLogger();

        self::assertInstanceOf(AuditLogger::class, $spy);
        self::assertSame([], $spy->entries);
        self::assertSame([], $spy->batches);
    }

    public function testItRecordsEachLogCall(): void
    {
        $spy = new SpyAuditLogger();

        $spy->log(AuditLogger::ACTION_VIEW, AuditLogger::ENTITY_MEMBER, 7, 'personal_email', 'why');

        self::assertCount(1, $spy->entries);
        self::assertSame([
            'action' => 'view',
            'entityType' => 'member',
            'entityId' => 7,
            'fieldName' => 'personal_email',
            'detail' => 'why',
        ], $spy->entries[0]);
        self::assertSame([], $spy->batches, 'a plain log() is not a batch');
    }

    public function testLogBatchIsRecordedRawAndFannedOut(): void
    {
        // The two views the replaced doubles each wanted: Reach's
        // SpyAuditLogger asserted on the unexpanded batch, its
        // RecordingAuditLogger and Rabbit's CapturingAuditLogger on the
        // per-field rows. Both are populated, so one class serves both.
        $spy = new SpyAuditLogger();

        $spy->logBatch(AuditLogger::ACTION_CALL, AuditLogger::ENTITY_MEMBER, 7, ['a', 'b'], 'detail');

        self::assertCount(1, $spy->batches);
        self::assertSame(['a', 'b'], $spy->batches[0]['fieldNames']);

        self::assertCount(2, $spy->entries);
        self::assertSame(['a', 'b'], array_column($spy->entries, 'fieldName'));
        self::assertSame(['call', 'call'], $spy->actions());
    }

    public function testDetailAndActionSurviveBothPaths(): void
    {
        $spy = new SpyAuditLogger();

        $spy->log(AuditLogger::ACTION_MESSAGE, AuditLogger::ENTITY_MEMBER, 1, 'mobile_number');
        $spy->logBatch(AuditLogger::ACTION_EXPORT, AuditLogger::ENTITY_GROUP, 2, ['x'], 'sent');

        self::assertSame(['message', 'export'], $spy->actions());
        self::assertSame('', $spy->entries[0]['detail'], 'detail defaults to empty');
        self::assertSame('sent', $spy->entries[1]['detail']);
    }
}
