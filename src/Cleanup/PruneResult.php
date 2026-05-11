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

    /**
     * Recorded when an "orphan" member is trashed — one with neither
     * an intergroup position nor a home group — for being inactive
     * past the configured threshold. Distinct from
     * REASON_HOME_GROUP_INACTIVE so an admin reviewing the result
     * can tell which category each trashed row belonged to.
     */
    public const REASON_ORPHAN_INACTIVE      = 'orphan_inactive';

    public const SKIP_OFFICER_NOT_DUE              = 'officer_not_due';
    public const SKIP_OFFICER_EARLIER_PEER_EXISTS  = 'officer_earlier_peer_exists';
    public const SKIP_OFFICER_INVALID_ROTATION     = 'officer_invalid_rotation';
    public const SKIP_HOME_GROUP_RECENT            = 'home_group_recent';
    public const SKIP_HOME_GROUP_INVALID_UPDATED   = 'home_group_invalid_updated';

    /**
     * Recorded when an orphan member's underlying post timestamp is
     * within the inactivity window and they're therefore kept.
     * Mirrors SKIP_HOME_GROUP_RECENT for the orphan pass so result
     * readers don't have to disambiguate from the row's reason
     * field which pass produced the skip.
     */
    public const SKIP_ORPHAN_RECENT                = 'orphan_recent';

    /**
     * Recorded when an orphan member has no parseable updated
     * timestamp. Mirrors SKIP_HOME_GROUP_INVALID_UPDATED for the
     * orphan pass.
     */
    public const SKIP_ORPHAN_INVALID_UPDATED       = 'orphan_invalid_updated';

    public const SKIP_TRASH_FAILED                 = 'trash_failed';

    /**
     * Recorded when a member is kept because they have a home group AND
     * are flagged as a twelfth stepper. Twelfth-steppers with a home
     * group are active service workers — they take 12th-step calls on
     * behalf of the fellowship — and trashing one would silently
     * remove them from the call-routing pool. The pruner therefore
     * skips them in any pass that would otherwise act on them
     * (officer rotation and home-group inactivity). The orphan pass
     * is unreachable for this category by construction, since orphans
     * have no home group.
     */
    public const SKIP_PROTECTED_TWELFTH_STEPPER     = 'protected_twelfth_stepper';

    /**
     * Recorded when the entire prune run is short-circuited because
     * the pruner is disabled in settings. Distinct from per-member
     * skip reasons so callers (admin pages, log readers) can spot
     * the toggle was off without scanning every entry.
     */
    public const SKIP_DISABLED                     = 'pruner_disabled';

    /** @var array<int, array{member_id:int, reason:string, detail:string}> */
    private array $trashed = [];

    /** @var array<int, array{member_id:int, reason:string, detail:string}> */
    private array $skipped = [];

    /** @var int Number of officer-pass candidates considered */
    private int $officersConsidered = 0;

    /** @var int Number of home-group-pass candidates considered */
    private int $homeGroupConsidered = 0;

    /** @var int Number of orphan-pass candidates considered */
    private int $orphansConsidered = 0;

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

    public function incrementOrphansConsidered(): void
    {
        $this->orphansConsidered++;
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

    public function getOrphansConsidered(): int
    {
        return $this->orphansConsidered;
    }
}
