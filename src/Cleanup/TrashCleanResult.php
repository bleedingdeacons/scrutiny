<?php

declare(strict_types=1);

namespace Scrutiny\Cleanup;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Result of a MemberTrashCleaner::clean() run.
 *
 * Plain DTO. The cleaner accumulates these entries as it walks the
 * trashed-members list and returns the populated object so callers
 * (cron handler, future admin button, WP-CLI command, tests) can
 * report on what happened. Mirrors the shape of PruneResult so the
 * two results can be reasoned about uniformly.
 */
final class TrashCleanResult
{
    public const REASON_RETENTION_EXPIRED = 'retention_expired';

    public const SKIP_RETENTION_NOT_REACHED = 'retention_not_reached';
    public const SKIP_MISSING_TRASH_TIME    = 'missing_trash_time';
    public const SKIP_DELETE_FAILED         = 'delete_failed';

    /** @var array<int, array{member_id:int, reason:string, detail:string}> */
    private array $deleted = [];

    /** @var array<int, array{member_id:int, reason:string, detail:string}> */
    private array $skipped = [];

    /** @var int Total number of trashed members the cleaner inspected */
    private int $considered = 0;

    public function recordDeleted(int $memberId, string $reason, string $detail = ''): void
    {
        $this->deleted[] = [
            'member_id' => $memberId,
            'reason'    => $reason,
            'detail'    => $detail,
        ];
    }

    public function recordSkipped(int $memberId, string $reason, string $detail = ''): void
    {
        $this->skipped[] = [
            'member_id' => $memberId,
            'reason'    => $reason,
            'detail'    => $detail,
        ];
    }

    public function incrementConsidered(): void
    {
        $this->considered++;
    }

    /** @return array<int, array{member_id:int, reason:string, detail:string}> */
    public function getDeleted(): array
    {
        return $this->deleted;
    }

    /** @return array<int, array{member_id:int, reason:string, detail:string}> */
    public function getSkipped(): array
    {
        return $this->skipped;
    }

    public function getDeletedCount(): int
    {
        return count($this->deleted);
    }

    public function getSkippedCount(): int
    {
        return count($this->skipped);
    }

    public function getConsidered(): int
    {
        return $this->considered;
    }
}
