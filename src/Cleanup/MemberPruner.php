<?php

declare(strict_types=1);

namespace Scrutiny\Cleanup;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use DateTimeImmutable;
use DateTimeInterface;
use Throwable;
use Unity\Members\Interfaces\Member;
use Unity\Members\Interfaces\MemberRepository;
use function function_exists;
use function wp_trash_post;

/**
 * Member Pruner
 *
 * Trashes (recoverable, via wp_trash_post) intergroup members who are no
 * longer current. Two independent passes:
 *
 *   1. Officer pass — members with an intergroup position whose rotation
 *      date is older than the configured grace period. If two members
 *      hold the same position, only the one with the *later* rotation
 *      date is treated as the current incumbent; the earlier one is the
 *      candidate for trashing.
 *
 *   2. Home-group non-GSR pass — members with a home group set, who are
 *      not the GSR for that group, and who have not been touched at
 *      intergroup (i.e. their underlying post hasn't been updated) for
 *      the configured inactivity period.
 *
 * A member who is prunable under the officer rule is *not* re-evaluated
 * under the home-group rule, so a single member is acted on at most once
 * per run.
 *
 * The pruner only calls wp_trash_post (a soft delete). Permanent
 * deletion remains a manual administrator action via the WordPress UI.
 * The Unity MemberChangeTracker fires unity/member_deleted on trash, so
 * Scrutiny's existing AuditTracker still records the event.
 *
 * Construction is hook-free. The pruner is invoked deliberately by an
 * admin action, WP-CLI command, or scheduled job — never on every page
 * load.
 */
class MemberPruner
{
    use \Scrutiny\Logger\HasLogger;

    protected static function logChannel(): string
    {
        return 'scrutiny';
    }

    private MemberRepository $members;
    private DateTimeImmutable $now;
    private ?PrunerSettings $settings;

    /**
     * Trashing a member fires wp_trash_post, which Unity's
     * MemberChangeTracker observes and re-fires as
     * unity/member_deleted. Scrutiny's existing AuditTracker is
     * already wired to that event, so trash actions performed by this
     * service are recorded in the audit log automatically — no
     * explicit AuditLogger call is needed here, and adding one would
     * double-log every action.
     *
     * @param MemberRepository       $members   Unity member repository
     * @param DateTimeImmutable|null $now       Override "now" for testing.
     * @param PrunerSettings|null    $settings  When provided, prune()
     *                                          consults isEnabled() and
     *                                          short-circuits if the
     *                                          pruner is disabled. Pass
     *                                          null in tests that want
     *                                          to exercise the pruning
     *                                          logic without the toggle.
     */
    public function __construct(
        MemberRepository $members,
        ?DateTimeImmutable $now = null,
        ?PrunerSettings $settings = null
    ) {
        $this->members  = $members;
        $this->now      = $now ?? new DateTimeImmutable('now');
        $this->settings = $settings;
    }

    /**
     * Run both pruning passes.
     *
     * If a PrunerSettings instance was supplied at construction and
     * the disabled flag is set, this method returns an empty result
     * immediately without loading any members. The result records a
     * single SKIP_DISABLED entry so callers can distinguish "ran but
     * found nothing to do" from "didn't run because the toggle is
     * off" — important for an admin "Run pruner now" button that
     * needs to surface why nothing happened.
     *
     * Both grace values are integers in months. A value of 0 is allowed
     * (anything past the threshold qualifies); negative values are
     * clamped to 0 so a misconfigured caller can never *expand* the
     * window into the future and trash currently-valid members.
     *
     * @param int $rotationGraceMonths   Months after rotation date before
     *                                   an officer becomes prunable.
     * @param int $inactivityMonths      Months without an update before a
     *                                   home-group non-GSR becomes prunable.
     * @return PruneResult
     */
    public function prune(int $rotationGraceMonths, int $inactivityMonths): PruneResult
    {
        // Short-circuit before anything destructive: if the toggle
        // is off, no member list is loaded, no comparisons are run,
        // no wp_trash_post calls are made. This is the safety
        // boundary the user opted into by leaving the pruner
        // disabled (which is the default on a fresh install).
        if ($this->settings !== null && !$this->settings->isEnabled()) {
            $result = new PruneResult();
            $result->recordSkipped(0, PruneResult::SKIP_DISABLED, 'pruner disabled in settings');

            self::logInfo('Member prune skipped: pruner is disabled in settings');

            return $result;
        }

        $rotationGraceMonths = max(0, $rotationGraceMonths);
        $inactivityMonths    = max(0, $inactivityMonths);

        $result = new PruneResult();

        // Load every published member once. Both passes operate on the
        // same in-memory snapshot so the "earlier peer exists" check in
        // the officer pass can be answered without a second query, and
        // so a member trashed in pass 1 is naturally absent from pass 2.
        $allMembers = $this->members->findAll();

        $rotationCutoff   = $this->now->modify('-' . $rotationGraceMonths . ' months');
        $inactivityCutoff = $this->now->modify('-' . $inactivityMonths . ' months');

        $trashedIds = $this->pruneOfficers($allMembers, $rotationCutoff, $result);
        $this->pruneHomeGroupNonGsrs($allMembers, $inactivityCutoff, $trashedIds, $result);

        self::logInfo('Member prune complete', [
            'trashed'                => $result->getTrashedCount(),
            'skipped'                => $result->getSkippedCount(),
            'officers_considered'    => $result->getOfficersConsidered(),
            'home_group_considered'  => $result->getHomeGroupConsidered(),
            'rotation_grace_months'  => $rotationGraceMonths,
            'inactivity_months'      => $inactivityMonths,
        ]);

        return $result;
    }

    // ──────────────────────────────────────────────
    //  Officer pass
    // ──────────────────────────────────────────────

    /**
     * @param array<Member>      $allMembers
     * @param DateTimeInterface  $rotationCutoff
     * @param PruneResult        $result
     * @return array<int, true>  Set of member IDs trashed by this pass.
     */
    private function pruneOfficers(array $allMembers, DateTimeInterface $rotationCutoff, PruneResult $result): array
    {
        // Group members by position so we can identify the current
        // incumbent (latest rotation date) per position. Members
        // without a valid rotation date are kept so we can still
        // record them in the result with an explicit reason.
        /** @var array<int, array<int, array{member: Member, rotation: ?DateTimeImmutable}>> $byPosition */
        $byPosition = [];

        foreach ($allMembers as $member) {
            $positionId = $member->getIntergroupPosition();
            if ($positionId <= 0) {
                continue;
            }
            $result->incrementOfficersConsidered();

            $byPosition[$positionId][] = [
                'member'   => $member,
                'rotation' => $this->parseRotation($member->getIntergroupPositionRotation()),
            ];
        }

        $trashedIds = [];

        foreach ($byPosition as $positionId => $entries) {
            // The current incumbent is the entry with the *latest*
            // rotation date. Entries without a parseable rotation are
            // never treated as the incumbent (they're flagged
            // separately) so a malformed value can't shield other
            // peers from pruning.
            $incumbent = $this->findIncumbent($entries);

            foreach ($entries as $entry) {
                $member   = $entry['member'];
                $rotation = $entry['rotation'];

                if ($rotation === null) {
                    $result->recordSkipped(
                        $member->getId(),
                        PruneResult::SKIP_OFFICER_INVALID_ROTATION,
                        'rotation="' . $member->getIntergroupPositionRotation() . '"'
                    );
                    continue;
                }

                // Still within the grace window — leave alone.
                if ($rotation >= $rotationCutoff) {
                    $result->recordSkipped(
                        $member->getId(),
                        PruneResult::SKIP_OFFICER_NOT_DUE,
                        'rotation=' . $rotation->format('Y-m-d')
                    );
                    continue;
                }

                // Past the grace window, but is this the current
                // incumbent? If so, keep them — there may simply be
                // no successor recorded yet.
                if ($incumbent !== null && $incumbent === $member) {
                    $result->recordSkipped(
                        $member->getId(),
                        PruneResult::SKIP_OFFICER_EARLIER_PEER_EXISTS,
                        'incumbent for position ' . $positionId
                    );
                    continue;
                }

                // Past the grace window AND a peer with a later
                // rotation exists for the same position → trash.
                // The trash itself fires wp_trash_post, which Unity's
                // MemberChangeTracker turns into a unity/member_deleted
                // event that Scrutiny's AuditTracker already records.
                if ($this->trashMember($member->getId())) {
                    $result->recordTrashed(
                        $member->getId(),
                        PruneResult::REASON_OFFICER_ROTATED,
                        'position=' . $positionId . ' rotation=' . $rotation->format('Y-m-d')
                    );
                    $trashedIds[$member->getId()] = true;
                } else {
                    $result->recordSkipped(
                        $member->getId(),
                        PruneResult::SKIP_TRASH_FAILED,
                        'wp_trash_post returned falsy'
                    );
                }
            }
        }

        return $trashedIds;
    }

    /**
     * Pick the entry with the latest rotation date from a position bucket.
     *
     * Entries with a null rotation are excluded from the comparison.
     * Returns null if no entry has a parseable rotation.
     *
     * @param array<int, array{member: Member, rotation: ?DateTimeImmutable}> $entries
     */
    private function findIncumbent(array $entries): ?Member
    {
        $latest        = null;
        $latestMember  = null;

        foreach ($entries as $entry) {
            if ($entry['rotation'] === null) {
                continue;
            }
            if ($latest === null || $entry['rotation'] > $latest) {
                $latest       = $entry['rotation'];
                $latestMember = $entry['member'];
            }
        }

        return $latestMember;
    }

    // ──────────────────────────────────────────────
    //  Home-group non-GSR pass
    // ──────────────────────────────────────────────

    /**
     * @param array<Member>      $allMembers
     * @param DateTimeInterface  $inactivityCutoff
     * @param array<int, true>   $alreadyTrashedIds  Members trashed by pass 1
     * @param PruneResult        $result
     */
    private function pruneHomeGroupNonGsrs(
        array $allMembers,
        DateTimeInterface $inactivityCutoff,
        array $alreadyTrashedIds,
        PruneResult $result
    ): void {
        foreach ($allMembers as $member) {
            // Only consider members whose status is "has a home group
            // but isn't the GSR" — and skip officers entirely (the
            // officer pass owns them). Officers who survived pass 1
            // are current incumbents and should not be re-evaluated
            // under an inactivity rule.
            if ($member->getHomeGroup() <= 0) {
                continue;
            }
            if ($member->isGSR()) {
                continue;
            }
            if ($member->getIntergroupPosition() > 0) {
                continue;
            }
            if (isset($alreadyTrashedIds[$member->getId()])) {
                continue;
            }

            $result->incrementHomeGroupConsidered();

            $updated = $this->parseUpdated($member->getUpdated());

            if ($updated === null) {
                $result->recordSkipped(
                    $member->getId(),
                    PruneResult::SKIP_HOME_GROUP_INVALID_UPDATED,
                    'updated="' . $member->getUpdated() . '"'
                );
                continue;
            }

            if ($updated >= $inactivityCutoff) {
                $result->recordSkipped(
                    $member->getId(),
                    PruneResult::SKIP_HOME_GROUP_RECENT,
                    'updated=' . $updated->format('Y-m-d H:i:s')
                );
                continue;
            }

            if ($this->trashMember($member->getId())) {
                $result->recordTrashed(
                    $member->getId(),
                    PruneResult::REASON_HOME_GROUP_INACTIVE,
                    'home_group=' . $member->getHomeGroup() . ' updated=' . $updated->format('Y-m-d H:i:s')
                );
            } else {
                $result->recordSkipped(
                    $member->getId(),
                    PruneResult::SKIP_TRASH_FAILED,
                    'wp_trash_post returned falsy'
                );
            }
        }
    }

    // ──────────────────────────────────────────────
    //  Helpers
    // ──────────────────────────────────────────────

    /**
     * Parse a rotation date string into an immutable DateTime, or null
     * if it can't be parsed.
     *
     * Unity normalises rotation values to Y-m-d at the factory boundary
     * (TsmlMemberFactory), so that's the canonical format. We accept
     * d/m/Y too as a defensive fallback for data written by older
     * code paths or imports that bypass the factory.
     */
    private function parseRotation(string $raw): ?DateTimeImmutable
    {
        if ($raw === '') {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
        if ($parsed instanceof DateTimeImmutable) {
            return $parsed;
        }

        $parsed = DateTimeImmutable::createFromFormat('!d/m/Y', $raw);
        if ($parsed instanceof DateTimeImmutable) {
            return $parsed;
        }

        return null;
    }

    /**
     * Parse the post_modified_gmt-style timestamp the Member factory
     * exposes via getUpdated(). The format is Y-m-d H:i:s in UTC.
     */
    private function parseUpdated(string $raw): ?DateTimeImmutable
    {
        if ($raw === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($raw);
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Wrap wp_trash_post so the call site can be mocked / overridden
     * in tests. Returns true on success.
     */
    protected function trashMember(int $memberId): bool
    {
        if (!function_exists('wp_trash_post')) {
            return false;
        }

        $result = wp_trash_post($memberId);

        // wp_trash_post returns the WP_Post object on success, false
        // on failure, and null when the post is already in the trash.
        // For our purposes "already trashed" is a no-op success.
        return $result !== false;
    }
}
