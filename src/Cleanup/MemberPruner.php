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
 * longer current. Three independent passes:
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
 *   3. Orphan pass — members with neither an intergroup position nor a
 *      home group, who haven't been touched for the same inactivity
 *      period as pass 2. Catches stale registrations that were never
 *      tied to either an officer role or a home group.
 *
 * A member who is prunable under an earlier rule is *not* re-evaluated
 * under a later one, so a single member is acted on at most once per
 * run.
 *
 * Cross-cutting exception: a member who has a home group AND is flagged
 * as a twelfth stepper is never trashed by any pass. Twelfth-steppers
 * with a home group are active service workers (they take 12th-step
 * calls), and silently removing them would break the call-routing
 * pool. The check applies to the officer and home-group passes; the
 * orphan pass can't reach this category because orphans have no
 * home group by definition.
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

        // Trashed IDs are accumulated across passes so each later
        // pass can skip members already acted on. Built as an
        // associative array (id => true) for O(1) isset() lookups
        // when the member list is large.
        $trashedIds = $this->pruneOfficers($allMembers, $rotationCutoff, $result);
        $trashedIds = $this->pruneHomeGroupNonGsrs($allMembers, $inactivityCutoff, $trashedIds, $result);
        $this->pruneOrphans($allMembers, $inactivityCutoff, $trashedIds, $result);

        $this->logResult($result, $rotationGraceMonths, $inactivityMonths);

        return $result;
    }

    /**
     * Write the result of a prune run to the wp_log channel.
     *
     * Tiered so the volume scales with how interesting each entry is:
     *
     *   - Each trashed member is logged at INFO with the member ID,
     *     the reason it was trashed (officer-rotated / home-group-
     *     inactive / orphan-inactive), and the detail string the
     *     pruner already records on the result. Trashing is
     *     destructive (even if recoverable), so every action gets
     *     its own log entry that can be correlated with the
     *     unity/member_deleted event Scrutiny's AuditTracker
     *     records separately.
     *
     *   - Each SKIP_TRASH_FAILED skip is logged at WARNING — these
     *     mean wp_trash_post returned falsy when we expected
     *     success, which is genuinely abnormal and worth
     *     surfacing in monitoring filters that key on log level.
     *
     *   - Routine "skipped because not yet due / still recent /
     *     invalid date" entries are NOT logged individually. They
     *     can run into the thousands on a healthy install and
     *     would drown out the destructive entries above. They
     *     remain available on the returned PruneResult for callers
     *     that need them.
     *
     *   - A single summary entry at INFO closes the run with
     *     counters, including how many of each skip category
     *     occurred so an admin can spot drift (e.g. a sudden
     *     spike in invalid-date skips) without scanning every row.
     */
    private function logResult(PruneResult $result, int $rotationGraceMonths, int $inactivityMonths): void
    {
        // Per-member INFO entries for every trashed row. Reason +
        // detail are split into separate context keys so log readers
        // can filter on category without parsing free text.
        foreach ($result->getTrashed() as $entry) {
            self::logInfo('Member trashed by pruner', [
                'member_id' => $entry['member_id'],
                'reason'    => $entry['reason'],
                'detail'    => $entry['detail'],
            ]);
        }

        // Per-member WARNING entries for the abnormal skip category.
        // We deliberately tally other skip categories in the summary
        // rather than logging them here — see the docblock above.
        $skipCategoryCounts = [];
        foreach ($result->getSkipped() as $entry) {
            $reason = $entry['reason'];

            if ($reason === PruneResult::SKIP_TRASH_FAILED) {
                self::logWarning('Pruner failed to trash a member', [
                    'member_id' => $entry['member_id'],
                    'reason'    => $reason,
                    'detail'    => $entry['detail'],
                ]);
                continue;
            }

            $skipCategoryCounts[$reason] = ($skipCategoryCounts[$reason] ?? 0) + 1;
        }

        self::logInfo('Member prune complete', [
            'trashed'               => $result->getTrashedCount(),
            'skipped'               => $result->getSkippedCount(),
            'officers_considered'   => $result->getOfficersConsidered(),
            'home_group_considered' => $result->getHomeGroupConsidered(),
            'orphans_considered'    => $result->getOrphansConsidered(),
            'rotation_grace_months' => $rotationGraceMonths,
            'inactivity_months'     => $inactivityMonths,
            // skip_categories breaks down the routine skips (not
            // due, recent, invalid date, etc.) into counts so an
            // admin can spot anomalies without per-row entries.
            'skip_categories'       => $skipCategoryCounts,
        ]);
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

                // Cross-cutting protection: a former officer who is
                // also a twelfth stepper with a home group is an
                // active service worker. Don't trash them just
                // because someone else now holds their old role —
                // they're still doing 12th-step calls.
                if ($this->isProtectedTwelfthStepper($member)) {
                    $result->recordSkipped(
                        $member->getId(),
                        PruneResult::SKIP_PROTECTED_TWELFTH_STEPPER,
                        'home_group=' . $member->getHomeGroup() . ' position=' . $positionId
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
     * @return array<int, true>  Updated set including IDs trashed by this pass
     */
    private function pruneHomeGroupNonGsrs(
        array $allMembers,
        DateTimeInterface $inactivityCutoff,
        array $alreadyTrashedIds,
        PruneResult $result
    ): array {
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

            // Cross-cutting protection: a home-group member who is
            // also flagged as a twelfth stepper is an active
            // service worker for the fellowship. They are excluded
            // before the inactivity comparison runs, so a long-
            // dormant post timestamp can't trash someone who is
            // still on the 12th-step call list.
            if ($this->isProtectedTwelfthStepper($member)) {
                $result->recordSkipped(
                    $member->getId(),
                    PruneResult::SKIP_PROTECTED_TWELFTH_STEPPER,
                    'home_group=' . $member->getHomeGroup()
                );
                continue;
            }

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
                $alreadyTrashedIds[$member->getId()] = true;
            } else {
                $result->recordSkipped(
                    $member->getId(),
                    PruneResult::SKIP_TRASH_FAILED,
                    'wp_trash_post returned falsy'
                );
            }
        }

        return $alreadyTrashedIds;
    }

    // ──────────────────────────────────────────────
    //  Orphan pass
    // ──────────────────────────────────────────────

    /**
     * @param array<Member>      $allMembers
     * @param DateTimeInterface  $inactivityCutoff
     * @param array<int, true>   $alreadyTrashedIds  Members trashed by passes 1–2
     * @param PruneResult        $result
     */
    private function pruneOrphans(
        array $allMembers,
        DateTimeInterface $inactivityCutoff,
        array $alreadyTrashedIds,
        PruneResult $result
    ): void {
        foreach ($allMembers as $member) {
            // Orphan = no intergroup position AND no home group. The
            // earlier two passes own everyone with either of those
            // attachments; pass 3 cleans up the residue. Officers
            // who survived pass 1 are the current incumbents (with
            // a position by definition), so the position check is
            // sufficient to exclude them. Likewise the home-group
            // check excludes everyone pass 2 considered.
            if ($member->getIntergroupPosition() > 0) {
                continue;
            }
            if ($member->getHomeGroup() > 0) {
                continue;
            }
            // Defence in depth: a member trashed in an earlier pass
            // shouldn't reach here anyway (passes 1 and 2 require
            // position or home group, which orphans don't have),
            // but the guard keeps a future refactor of the filters
            // from accidentally re-trashing.
            if (isset($alreadyTrashedIds[$member->getId()])) {
                continue;
            }

            $result->incrementOrphansConsidered();

            $updated = $this->parseUpdated($member->getUpdated());

            if ($updated === null) {
                $result->recordSkipped(
                    $member->getId(),
                    PruneResult::SKIP_ORPHAN_INVALID_UPDATED,
                    'updated="' . $member->getUpdated() . '"'
                );
                continue;
            }

            if ($updated >= $inactivityCutoff) {
                $result->recordSkipped(
                    $member->getId(),
                    PruneResult::SKIP_ORPHAN_RECENT,
                    'updated=' . $updated->format('Y-m-d H:i:s')
                );
                continue;
            }

            if ($this->trashMember($member->getId())) {
                $result->recordTrashed(
                    $member->getId(),
                    PruneResult::REASON_ORPHAN_INACTIVE,
                    'updated=' . $updated->format('Y-m-d H:i:s')
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
     * Whether a member is shielded from pruning under the
     * "home-group twelfth-stepper" protection rule.
     *
     * Both conditions must hold:
     *   - The member has a home group (getHomeGroup() > 0).
     *   - The member is flagged as a twelfth stepper.
     *
     * A twelfth stepper with no home group is NOT protected — without
     * a home group they're an orphan, and the orphan pass's
     * inactivity rule still applies. Likewise a home-group member
     * who isn't a twelfth stepper falls through to the normal
     * inactivity rule. The conjunction is deliberate.
     */
    private function isProtectedTwelfthStepper(Member $member): bool
    {
        return $member->getHomeGroup() > 0 && $member->isTwelfthStepper();
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
