<?php

declare(strict_types=1);

namespace Scrutiny\Cleanup;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Result of a MemberPruner::prune() run.
 *
 * Plain DTO. The pruner accumulates these entries as it walks the member
 * list and returns the populated object so callers (admin screens, WP-CLI,
 * tests) can report on what happened without having to re-read the audit
 * log. There is no logic here — assertion-friendly arrays only.
 */
final class PruneResult
{
    public const REASON_OFFICER_ROTATED      = 'officer_rotated';
    public const REASON_HOME_GROUP_INACTIVE  = 'home_group_inactive';

    public const SKIP_OFFICER_NOT_DUE              = 'officer_not_due';
    public const SKIP_OFFICER_EARLIER_PEER_EXISTS  = 'officer_earlier_peer_exists';
    public const SKIP_OFFICER_INVALID_ROTATION     = 'officer_invalid_rotation';
    public const SKIP_HOME_GROUP_RECENT            = 'home_group_recent';
    public const SKIP_HOME_GROUP_INVALID_UPDATED   = 'home_group_invalid_updated';
    public const SKIP_TRASH_FAILED                 = 'trash_failed';

    /** @var array<int, array{member_id:int, reason:string, detail:string}> */
    private array $trashed = [];

    /** @var array<int, array{member_id:int, reason:string, detail:string}> */
    private array $skipped = [];

    /** @var int Number of officer-pass candidates considered */
    private int $officersConsidered = 0;

    /** @var int Number of home-group-pass candidates considered */
    private int $homeGroupConsidered = 0;

    public function recordTrashed(int $memberId, string $reason, string $detail = ''): void
    {
        $this->trashed[] = [
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

    public function incrementOfficersConsidered(): void
    {
        $this->officersConsidered++;
    }

    public function incrementHomeGroupConsidered(): void
    {
        $this->homeGroupConsidered++;
    }

    /** @return array<int, array{member_id:int, reason:string, detail:string}> */
    public function getTrashed(): array
    {
        return $this->trashed;
    }

    /** @return array<int, array{member_id:int, reason:string, detail:string}> */
    public function getSkipped(): array
    {
        return $this->skipped;
    }

    public function getTrashedCount(): int
    {
        return count($this->trashed);
    }

    public function getSkippedCount(): int
    {
        return count($this->skipped);
    }

    public function getOfficersConsidered(): int
    {
        return $this->officersConsidered;
    }

    public function getHomeGroupConsidered(): int
    {
        return $this->homeGroupConsidered;
    }
}
