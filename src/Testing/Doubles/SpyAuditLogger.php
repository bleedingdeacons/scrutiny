<?php

declare(strict_types=1);

namespace Scrutiny\Testing\Doubles;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Scrutiny\Audit\Interfaces\AuditLogger;

/**
 * A recording AuditLogger for tests.
 *
 * Scrutiny ships no concrete AuditLogger a test can drive, so every consuming
 * plugin wrote its own. There were four across the suite — Reach's
 * SpyAuditLogger, RecordingAuditLogger and NullAuditLogger, and Rabbit's
 * CapturingAuditLogger — differing only in which of the two calls they
 * bothered to record.
 *
 * Worse, two of those plugins could not have caught a change to this contract:
 * their bootstraps eval()'d a hand-written copy of the interface when Scrutiny
 * was not checked out, and Rabbit's copy declared just log() and logBatch()
 * with no constants at all. A double implementing a stale copy satisfies the
 * stale copy. Shipping this from Scrutiny is what fixes that: it implements
 * the contract two directories away, so a signature change fails Scrutiny's
 * own build first.
 *
 * Both views of the traffic are recorded, because the consumers wanted
 * different ones:
 *
 *   - {@see $entries} — one row per audited field, with logBatch() fanned out
 *     into individual rows. What most tests assert on.
 *   - {@see $batches} — one row per logBatch() call, unexpanded, for tests
 *     that care that a batch was written as a batch.
 *
 * A test that wants the old NullAuditLogger simply builds one of these and
 * asserts nothing.
 */
final class SpyAuditLogger implements AuditLogger
{
    /**
     * One row per audited field, logBatch() included.
     *
     * @var array<int, array{action: string, entityType: string, entityId: int, fieldName: string, detail: string}>
     */
    public array $entries = [];

    /**
     * One row per logBatch() call, left unexpanded.
     *
     * @var array<int, array{action: string, entityType: string, entityId: int, fieldNames: array<int, string>, detail: string}>
     */
    public array $batches = [];

    public function log(
        string $action,
        string $entityType,
        int $entityId,
        string $fieldName,
        string $detail = ''
    ): void {
        $this->entries[] = compact('action', 'entityType', 'entityId', 'fieldName', 'detail');
    }

    /**
     * @param array<int, string> $fieldNames
     */
    public function logBatch(
        string $action,
        string $entityType,
        int $entityId,
        array $fieldNames,
        string $detail = ''
    ): void {
        $this->batches[] = compact('action', 'entityType', 'entityId', 'fieldNames', 'detail');

        foreach ($fieldNames as $fieldName) {
            $this->log($action, $entityType, $entityId, $fieldName, $detail);
        }
    }

    /**
     * The actions recorded, in order — a common shorthand in assertions.
     *
     * @return array<int, string>
     */
    public function actions(): array
    {
        return array_column($this->entries, 'action');
    }
}
