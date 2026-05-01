<?php

declare(strict_types=1);

namespace Scrutiny\Tests\Unit\Cleanup;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Scrutiny\Cleanup\MemberPruner;
use Scrutiny\Cleanup\PruneResult;
use Scrutiny\Cleanup\PrunerSettings;
use Unity\Members\Interfaces\Member;
use Unity\Members\Interfaces\MemberRepository;

/**
 * Tests for MemberPruner.
 *
 * The pruner is exercised end-to-end through prune(), with a fake
 * repository feeding the in-memory member list and a test subclass
 * overriding trashMember() so wp_trash_post is never called. "Now"
 * is fixed via the constructor so date-based assertions are stable.
 */
class MemberPrunerTest extends TestCase
{
    /**
     * Fixed reference time for all tests. Picked deliberately mid-month
     * so a "-X months" arithmetic doesn't snap onto a month boundary
     * and accidentally line up with a test fixture.
     */
    private const NOW = '2025-07-15 12:00:00';

    // ──────────────────────────────────────────────
    //  Officer pass
    // ──────────────────────────────────────────────

    /** @test */
    public function it_trashes_an_officer_whose_rotation_is_past_when_a_successor_with_later_rotation_exists(): void
    {
        $rotated   = $this->makeMember(id: 1, position: 100, rotation: '2024-01-01');
        $successor = $this->makeMember(id: 2, position: 100, rotation: '2025-01-01');

        $pruner = $this->makePruner([$rotated, $successor]);
        $result = $pruner->prune(rotationGraceMonths: 3, inactivityMonths: 12);

        $this->assertTrashedIds($result, [1]);
        $this->assertSame([1], $pruner->getTrashedIds());
        $this->assertSame(2, $result->getOfficersConsidered());
    }

    /** @test */
    public function it_keeps_the_current_incumbent_even_when_their_rotation_is_past(): void
    {
        // Both rotated long ago. The later one is still the incumbent
        // because there is no successor with an even later date —
        // pruning them would leave the position empty, which is the
        // exact scenario the rule is designed to prevent.
        $earlier = $this->makeMember(id: 1, position: 100, rotation: '2023-01-01');
        $later   = $this->makeMember(id: 2, position: 100, rotation: '2024-01-01');

        $pruner = $this->makePruner([$earlier, $later]);
        $result = $pruner->prune(rotationGraceMonths: 3, inactivityMonths: 12);

        // Earlier one is trashed, later one is kept as the incumbent.
        $this->assertTrashedIds($result, [1]);

        // The reason the incumbent was skipped is recorded.
        $skipReasons = array_column($result->getSkipped(), 'reason', 'member_id');
        $this->assertSame(PruneResult::SKIP_OFFICER_EARLIER_PEER_EXISTS, $skipReasons[2] ?? null);
    }

    /** @test */
    public function it_does_not_trash_a_lone_officer_with_a_past_rotation(): void
    {
        // Single officer for the position. They are the incumbent by
        // definition — there is no successor to take over — so the
        // pruner leaves them in place even though their rotation is
        // years past.
        $lone = $this->makeMember(id: 1, position: 100, rotation: '2020-01-01');

        $pruner = $this->makePruner([$lone]);
        $result = $pruner->prune(rotationGraceMonths: 3, inactivityMonths: 12);

        $this->assertSame([], $pruner->getTrashedIds());
        $this->assertSame(0, $result->getTrashedCount());
    }

    /** @test */
    public function it_does_not_trash_an_officer_still_within_the_grace_period(): void
    {
        // Rotation was 2 months ago, grace period is 3 months — the
        // officer is still within their post-rotation grace window
        // and should not be touched, even though a successor exists.
        $within   = $this->makeMember(id: 1, position: 100, rotation: '2025-05-01');
        $newer    = $this->makeMember(id: 2, position: 100, rotation: '2025-07-01');

        $pruner = $this->makePruner([$within, $newer]);
        $result = $pruner->prune(rotationGraceMonths: 3, inactivityMonths: 12);

        $this->assertSame([], $pruner->getTrashedIds());

        $skipReasons = array_column($result->getSkipped(), 'reason', 'member_id');
        $this->assertSame(PruneResult::SKIP_OFFICER_NOT_DUE, $skipReasons[1] ?? null);
    }

    /** @test */
    public function it_records_a_skip_when_an_officer_has_an_unparseable_rotation_date(): void
    {
        // Garbage rotation value can't be compared to the cutoff, so
        // the pruner refuses to act on it and surfaces the situation
        // in the result rather than swallowing it.
        $bad = $this->makeMember(id: 1, position: 100, rotation: 'not-a-date');

        $pruner = $this->makePruner([$bad]);
        $result = $pruner->prune(rotationGraceMonths: 3, inactivityMonths: 12);

        $this->assertSame([], $pruner->getTrashedIds());

        $skipReasons = array_column($result->getSkipped(), 'reason', 'member_id');
        $this->assertSame(PruneResult::SKIP_OFFICER_INVALID_ROTATION, $skipReasons[1] ?? null);
    }

    /** @test */
    public function it_accepts_d_m_y_rotation_format_as_a_fallback(): void
    {
        // Imports and older code paths may bypass the factory's
        // normaliser and write d/m/Y directly. The pruner accepts
        // that format too so historical data isn't silently skipped.
        $rotated   = $this->makeMember(id: 1, position: 100, rotation: '01/01/2024');
        $successor = $this->makeMember(id: 2, position: 100, rotation: '2025-01-01');

        $pruner = $this->makePruner([$rotated, $successor]);
        $pruner->prune(rotationGraceMonths: 3, inactivityMonths: 12);

        $this->assertSame([1], $pruner->getTrashedIds());
    }

    /** @test */
    public function it_does_not_treat_a_member_with_invalid_rotation_as_the_incumbent(): void
    {
        // The pruner picks the incumbent by latest *parseable* rotation
        // date. A peer with a malformed rotation must not be allowed to
        // become the de-facto incumbent and shield a real, rotated
        // member from pruning — that would let bad data hide stale
        // officers indefinitely.
        $rotated = $this->makeMember(id: 1, position: 100, rotation: '2024-01-01');
        $broken  = $this->makeMember(id: 2, position: 100, rotation: 'garbage');
        $newer   = $this->makeMember(id: 3, position: 100, rotation: '2025-01-01');

        $pruner = $this->makePruner([$rotated, $broken, $newer]);
        $pruner->prune(rotationGraceMonths: 3, inactivityMonths: 12);

        // 1 trashed (rotated), 2 skipped as invalid, 3 is the incumbent.
        $this->assertSame([1], $pruner->getTrashedIds());
    }

    /** @test */
    public function officers_are_grouped_by_position_so_other_positions_do_not_interfere(): void
    {
        // Position 100 has a successor; position 200 does not. The
        // member with the past rotation under position 100 should be
        // trashed, but the lone member under position 200 should be
        // left alone — an unrelated position must not provide cover
        // and must not provide grounds either.
        $a = $this->makeMember(id: 1, position: 100, rotation: '2024-01-01');
        $b = $this->makeMember(id: 2, position: 100, rotation: '2025-01-01');
        $c = $this->makeMember(id: 3, position: 200, rotation: '2020-01-01');

        $pruner = $this->makePruner([$a, $b, $c]);
        $pruner->prune(rotationGraceMonths: 3, inactivityMonths: 12);

        $this->assertSame([1], $pruner->getTrashedIds());
    }

    // ──────────────────────────────────────────────
    //  Home-group non-GSR pass
    // ──────────────────────────────────────────────

    /** @test */
    public function it_trashes_a_home_group_non_gsr_member_inactive_beyond_threshold(): void
    {
        // No position, has a home group, isn't the GSR, last
        // updated 18 months ago — well past a 12-month inactivity
        // threshold, so they should be pruned.
        $stale = $this->makeMember(
            id: 5,
            homeGroup: 50,
            isGSR: false,
            updated: '2024-01-15 10:00:00'
        );

        $pruner = $this->makePruner([$stale]);
        $result = $pruner->prune(rotationGraceMonths: 3, inactivityMonths: 12);

        $this->assertSame([5], $pruner->getTrashedIds());
        $this->assertSame(1, $result->getHomeGroupConsidered());
    }

    /** @test */
    public function it_does_not_trash_a_home_group_member_who_is_a_gsr(): void
    {
        // GSRs are explicitly exempt — they're the formal intergroup
        // representative for their home group, so the pruner mustn't
        // act on them under the inactivity rule no matter how stale.
        $gsr = $this->makeMember(
            id: 6,
            homeGroup: 50,
            isGSR: true,
            updated: '2020-01-01 00:00:00'
        );

        $pruner = $this->makePruner([$gsr]);
        $pruner->prune(rotationGraceMonths: 3, inactivityMonths: 12);

        $this->assertSame([], $pruner->getTrashedIds());
    }

    /** @test */
    public function it_does_not_trash_a_home_group_member_within_the_inactivity_window(): void
    {
        // Last updated 6 months ago against a 12-month threshold —
        // still active enough to keep, even though they have a home
        // group and aren't the GSR.
        $recent = $this->makeMember(
            id: 7,
            homeGroup: 50,
            isGSR: false,
            updated: '2025-01-15 10:00:00'
        );

        $pruner = $this->makePruner([$recent]);
        $result = $pruner->prune(rotationGraceMonths: 3, inactivityMonths: 12);

        $this->assertSame([], $pruner->getTrashedIds());

        $skipReasons = array_column($result->getSkipped(), 'reason', 'member_id');
        $this->assertSame(PruneResult::SKIP_HOME_GROUP_RECENT, $skipReasons[7] ?? null);
    }

    /** @test */
    public function it_skips_home_group_members_with_no_updated_timestamp(): void
    {
        // Empty getUpdated() values can occur for posts loaded before
        // post_modified_gmt was populated, or in fixtures. The pruner
        // refuses to guess and records the situation so an admin can
        // investigate rather than silently trashing the row.
        $undated = $this->makeMember(
            id: 8,
            homeGroup: 50,
            isGSR: false,
            updated: ''
        );

        $pruner = $this->makePruner([$undated]);
        $result = $pruner->prune(rotationGraceMonths: 3, inactivityMonths: 12);

        $this->assertSame([], $pruner->getTrashedIds());

        $skipReasons = array_column($result->getSkipped(), 'reason', 'member_id');
        $this->assertSame(PruneResult::SKIP_HOME_GROUP_INVALID_UPDATED, $skipReasons[8] ?? null);
    }

    /** @test */
    public function members_without_a_home_group_do_not_enter_the_home_group_pass(): void
    {
        // The home-group pass requires homeGroup > 0 — orphans (no
        // home group, no position) are owned by pass 3, not pass 2.
        // The updated timestamp here is recent so the orphan pass
        // also doesn't trash; this test is purely about the
        // home-group-pass filter, not about pass 3.
        $orphan = $this->makeMember(id: 9, homeGroup: 0, updated: '2025-07-10 00:00:00');

        $pruner = $this->makePruner([$orphan]);
        $result = $pruner->prune(rotationGraceMonths: 3, inactivityMonths: 12);

        $this->assertSame([], $pruner->getTrashedIds());
        $this->assertSame(0, $result->getHomeGroupConsidered());
    }

    // ──────────────────────────────────────────────
    //  Orphan pass
    //
    //  Orphans are members with no intergroup position AND no home
    //  group — registrations that were never tied to either an
    //  officer role or a group. Pass 3 catches them under the same
    //  inactivity threshold the home-group pass uses, deliberately:
    //  one knob configures both kinds of "stale" cleanup.
    // ──────────────────────────────────────────────

    /** @test */
    public function it_trashes_an_orphan_inactive_beyond_threshold(): void
    {
        // No position, no home group, last updated 18 months ago
        // against a 12-month threshold. Should be trashed under the
        // orphan rule and recorded with REASON_ORPHAN_INACTIVE so an
        // admin can tell the row apart from home-group inactives.
        $orphan = $this->makeMember(
            id: 40,
            position: 0,
            homeGroup: 0,
            updated: '2024-01-15 10:00:00'
        );

        $pruner = $this->makePruner([$orphan]);
        $result = $pruner->prune(rotationGraceMonths: 3, inactivityMonths: 12);

        $this->assertSame([40], $pruner->getTrashedIds());
        $this->assertSame(1, $result->getOrphansConsidered());

        $reasons = array_column($result->getTrashed(), 'reason', 'member_id');
        $this->assertSame(PruneResult::REASON_ORPHAN_INACTIVE, $reasons[40] ?? null);
    }

    /** @test */
    public function it_does_not_trash_a_recent_orphan(): void
    {
        // Updated 6 months ago against a 12-month threshold — within
        // the inactivity window, so kept.
        $recent = $this->makeMember(
            id: 41,
            position: 0,
            homeGroup: 0,
            updated: '2025-01-15 10:00:00'
        );

        $pruner = $this->makePruner([$recent]);
        $result = $pruner->prune(rotationGraceMonths: 3, inactivityMonths: 12);

        $this->assertSame([], $pruner->getTrashedIds());

        $skipReasons = array_column($result->getSkipped(), 'reason', 'member_id');
        $this->assertSame(PruneResult::SKIP_ORPHAN_RECENT, $skipReasons[41] ?? null);
    }

    /** @test */
    public function it_skips_orphans_with_no_updated_timestamp(): void
    {
        // Same defensive treatment as the home-group pass: an empty
        // updated value can't be compared, so the pruner refuses to
        // act and surfaces the situation rather than silently
        // trashing.
        $undated = $this->makeMember(
            id: 42,
            position: 0,
            homeGroup: 0,
            updated: ''
        );

        $pruner = $this->makePruner([$undated]);
        $result = $pruner->prune(rotationGraceMonths: 3, inactivityMonths: 12);

        $this->assertSame([], $pruner->getTrashedIds());

        $skipReasons = array_column($result->getSkipped(), 'reason', 'member_id');
        $this->assertSame(PruneResult::SKIP_ORPHAN_INVALID_UPDATED, $skipReasons[42] ?? null);
    }

    /** @test */
    public function members_with_a_home_group_do_not_enter_the_orphan_pass(): void
    {
        // A home-group non-GSR is owned by pass 2 even when stale.
        // The orphan pass must skip them so the result records the
        // trash under REASON_HOME_GROUP_INACTIVE, not REASON_ORPHAN_INACTIVE.
        $homeGroupMember = $this->makeMember(
            id: 43,
            homeGroup: 50,
            isGSR: false,
            updated: '2024-01-15 10:00:00'
        );

        $pruner = $this->makePruner([$homeGroupMember]);
        $result = $pruner->prune(rotationGraceMonths: 3, inactivityMonths: 12);

        $this->assertSame([43], $pruner->getTrashedIds());
        $this->assertSame(0, $result->getOrphansConsidered());

        $reasons = array_column($result->getTrashed(), 'reason', 'member_id');
        $this->assertSame(PruneResult::REASON_HOME_GROUP_INACTIVE, $reasons[43] ?? null);
    }

    /** @test */
    public function members_with_an_intergroup_position_do_not_enter_the_orphan_pass(): void
    {
        // A lone officer with a stale rotation date is kept by the
        // officer pass (no successor → can't be replaced) and must
        // not then fall through to the orphan pass and be trashed
        // there for inactivity. The position guard in pass 3 stops
        // that.
        $loneOfficer = $this->makeMember(
            id: 44,
            position: 100,
            rotation: '2020-01-01',
            homeGroup: 0,
            updated: '2020-01-01 00:00:00'
        );

        $pruner = $this->makePruner([$loneOfficer]);
        $result = $pruner->prune(rotationGraceMonths: 3, inactivityMonths: 12);

        $this->assertSame([], $pruner->getTrashedIds());
        $this->assertSame(0, $result->getOrphansConsidered());
    }

    /** @test */
    public function the_orphan_pass_uses_the_same_inactivity_threshold_as_the_home_group_pass(): void
    {
        // Two members with identical updated timestamps — one orphan,
        // one home-group non-GSR — must be treated identically. This
        // pins down the design decision that one knob controls both.
        $orphan = $this->makeMember(
            id: 50,
            position: 0,
            homeGroup: 0,
            updated: '2024-01-15 10:00:00'
        );
        $homeGroup = $this->makeMember(
            id: 51,
            homeGroup: 50,
            isGSR: false,
            updated: '2024-01-15 10:00:00'
        );

        $pruner = $this->makePruner([$orphan, $homeGroup]);
        $pruner->prune(rotationGraceMonths: 3, inactivityMonths: 12);

        // Both trashed under their respective rules.
        $trashedIds = $pruner->getTrashedIds();
        sort($trashedIds);
        $this->assertSame([50, 51], $trashedIds);
    }

    // ──────────────────────────────────────────────
    //  Cross-pass interaction
    // ──────────────────────────────────────────────

    /** @test */
    public function officers_are_not_re_evaluated_under_the_inactivity_rule(): void
    {
        // A member with both an intergroup position and a home group
        // is owned by the officer pass: the home-group pass must
        // skip them entirely so a single member is never evaluated
        // under two competing rules in the same run.
        $rotated = $this->makeMember(
            id: 10,
            position: 100,
            rotation: '2024-01-01',
            homeGroup: 50,
            isGSR: false,
            updated: '2025-07-01 00:00:00' // very recent → would survive home-group rule
        );
        $successor = $this->makeMember(id: 11, position: 100, rotation: '2025-01-01');

        $pruner = $this->makePruner([$rotated, $successor]);
        $result = $pruner->prune(rotationGraceMonths: 3, inactivityMonths: 12);

        $this->assertSame([10], $pruner->getTrashedIds());

        // The rotated member is recorded as "officer rotated", not
        // "home group inactive" — they were owned by the officer
        // pass and never reached the inactivity check.
        $reasons = array_column($result->getTrashed(), 'reason', 'member_id');
        $this->assertSame(PruneResult::REASON_OFFICER_ROTATED, $reasons[10] ?? null);

        // And the home-group counter never incremented for them.
        $this->assertSame(0, $result->getHomeGroupConsidered());
    }

    /** @test */
    public function a_member_trashed_in_the_officer_pass_is_not_trashed_again_in_the_home_group_pass(): void
    {
        // Belt-and-braces: even if the home-group pass were reached,
        // an already-trashed ID must not be re-submitted to wp_trash_post.
        $rotated = $this->makeMember(
            id: 20,
            position: 100,
            rotation: '2024-01-01',
            homeGroup: 50,
            isGSR: false,
            updated: '2020-01-01 00:00:00' // would also qualify under inactivity
        );
        $successor = $this->makeMember(id: 21, position: 100, rotation: '2025-01-01');

        $pruner = $this->makePruner([$rotated, $successor]);
        $pruner->prune(rotationGraceMonths: 3, inactivityMonths: 12);

        // Trashed exactly once.
        $this->assertSame([20], $pruner->getTrashedIds());
    }

    // ──────────────────────────────────────────────
    //  Former-GSR interactions
    //
    //  In this codebase a current GSR is identified by the combination
    //  homeGroup > 0 AND isGSR === true. A *former* GSR is therefore a
    //  member with homeGroup > 0 AND isGSR === false — they once held
    //  the role, the group has moved on, and the flag has been cleared.
    //
    //  Such a member may simultaneously hold an intergroup officer
    //  position. The officer pass must own that case: the rotation
    //  rule decides their fate, and the home-group inactivity rule
    //  must not re-evaluate them. These tests pin that down.
    // ──────────────────────────────────────────────

    /** @test */
    public function a_former_gsr_with_a_rotated_position_is_trashed_under_the_officer_rule(): void
    {
        // homeGroup set, isGSR === false (former GSR), and they hold a
        // position whose rotation is past with a successor in place.
        // The officer rule applies; the home-group rule does not run
        // for them at all.
        $formerGsrOfficer = $this->makeMember(
            id: 30,
            position: 100,
            rotation: '2024-01-01',
            homeGroup: 50,
            isGSR: false,
            updated: '2025-07-10 00:00:00' // recent enough to survive inactivity rule
        );
        $successor = $this->makeMember(id: 31, position: 100, rotation: '2025-01-01');

        $pruner = $this->makePruner([$formerGsrOfficer, $successor]);
        $result = $pruner->prune(rotationGraceMonths: 3, inactivityMonths: 12);

        $this->assertSame([30], $pruner->getTrashedIds());

        // Recorded as officer-rotated, never reaches the home-group pass.
        $reasons = array_column($result->getTrashed(), 'reason', 'member_id');
        $this->assertSame(PruneResult::REASON_OFFICER_ROTATED, $reasons[30] ?? null);
        $this->assertSame(0, $result->getHomeGroupConsidered());
    }

    /** @test */
    public function a_former_gsr_who_is_a_current_incumbent_officer_is_kept_even_with_an_old_updated_timestamp(): void
    {
        // Lone officer for the position (no successor), so they are
        // the current incumbent — kept by the officer rule. Their
        // updated timestamp is years old, which would otherwise
        // qualify them under the inactivity rule. The position-guard
        // in the home-group pass prevents that double-evaluation.
        $formerGsrIncumbent = $this->makeMember(
            id: 32,
            position: 200,
            rotation: '2020-01-01', // long past, but they're the only holder
            homeGroup: 50,
            isGSR: false,
            updated: '2020-01-01 00:00:00' // ancient
        );

        $pruner = $this->makePruner([$formerGsrIncumbent]);
        $result = $pruner->prune(rotationGraceMonths: 3, inactivityMonths: 12);

        // Not trashed: officer rule keeps them as the incumbent, and
        // the home-group rule never sees them.
        $this->assertSame([], $pruner->getTrashedIds());
        $this->assertSame(0, $result->getHomeGroupConsidered());

        // The skip reason confirms the officer pass acknowledged them
        // explicitly rather than letting them fall through silently.
        $skipReasons = array_column($result->getSkipped(), 'reason', 'member_id');
        $this->assertSame(PruneResult::SKIP_OFFICER_EARLIER_PEER_EXISTS, $skipReasons[32] ?? null);
    }

    /** @test */
    public function a_former_gsr_within_their_rotation_grace_window_is_kept_and_not_re_evaluated(): void
    {
        // homeGroup + isGSR=false + position. Rotation is recent
        // enough to be inside the grace window, so the officer pass
        // keeps them. The home-group pass must not get a second
        // crack at them on the basis of an old updated timestamp.
        $formerGsrCurrentOfficer = $this->makeMember(
            id: 33,
            position: 300,
            rotation: '2025-06-01', // ~1.5 months ago, within 3-month grace
            homeGroup: 50,
            isGSR: false,
            updated: '2020-01-01 00:00:00' // ancient — would qualify under inactivity
        );
        // Add a successor so the incumbent test isn't what's keeping them.
        $successor = $this->makeMember(id: 34, position: 300, rotation: '2025-07-10');

        $pruner = $this->makePruner([$formerGsrCurrentOfficer, $successor]);
        $result = $pruner->prune(rotationGraceMonths: 3, inactivityMonths: 12);

        $this->assertSame([], $pruner->getTrashedIds());
        $this->assertSame(0, $result->getHomeGroupConsidered());

        $skipReasons = array_column($result->getSkipped(), 'reason', 'member_id');
        $this->assertSame(PruneResult::SKIP_OFFICER_NOT_DUE, $skipReasons[33] ?? null);
    }

    // ──────────────────────────────────────────────
    //  Edge cases
    // ──────────────────────────────────────────────

    /** @test */
    public function negative_grace_periods_are_clamped_to_zero(): void
    {
        // Defensive: a misconfigured caller passing negative months
        // must not have the cutoff slide into the future and start
        // trashing currently-valid members. We clamp to zero, which
        // means "anything past today qualifies".
        $rotated   = $this->makeMember(id: 1, position: 100, rotation: '2025-07-14');
        $successor = $this->makeMember(id: 2, position: 100, rotation: '2025-07-15');

        $pruner = $this->makePruner([$rotated, $successor]);
        $pruner->prune(rotationGraceMonths: -50, inactivityMonths: -50);

        // With the clamp at zero, the rotated officer (yesterday) is
        // past the cutoff (today) and should be trashed.
        $this->assertSame([1], $pruner->getTrashedIds());
    }

    /** @test */
    public function it_handles_an_empty_member_list_without_error(): void
    {
        $pruner = $this->makePruner([]);
        $result = $pruner->prune(rotationGraceMonths: 3, inactivityMonths: 12);

        $this->assertSame(0, $result->getTrashedCount());
        $this->assertSame(0, $result->getSkippedCount());
        $this->assertSame(0, $result->getOfficersConsidered());
        $this->assertSame(0, $result->getHomeGroupConsidered());
    }

    /** @test */
    public function it_records_a_skip_when_wp_trash_post_fails(): void
    {
        // Simulate a WordPress failure: trashMember() returns false.
        // The pruner must not record a successful trash and must
        // surface the failure in the result so an admin can chase it.
        $rotated   = $this->makeMember(id: 1, position: 100, rotation: '2024-01-01');
        $successor = $this->makeMember(id: 2, position: 100, rotation: '2025-01-01');

        $pruner = $this->makePruner([$rotated, $successor], trashSucceeds: false);
        $result = $pruner->prune(rotationGraceMonths: 3, inactivityMonths: 12);

        $this->assertSame(0, $result->getTrashedCount());

        $skipReasons = array_column($result->getSkipped(), 'reason', 'member_id');
        $this->assertSame(PruneResult::SKIP_TRASH_FAILED, $skipReasons[1] ?? null);
    }

    // ──────────────────────────────────────────────
    //  Disabled flag
    //
    //  The pruner reads PrunerSettings::isEnabled() at the start of
    //  prune() and short-circuits if the toggle is off. The check
    //  lives on the service so every caller (admin button, WP-CLI,
    //  cron) automatically respects the toggle without each one
    //  having to remember to read the flag separately.
    // ──────────────────────────────────────────────

    /** @test */
    public function it_short_circuits_when_settings_report_disabled(): void
    {
        // Set up a scenario where the officer pass would normally
        // trash member 1. With settings reporting disabled, the
        // pruner must return immediately without trashing anything.
        $GLOBALS['scrutiny_test_options'] = [
            PrunerSettings::OPTION_ENABLED => 0,
        ];
        $settings = new PrunerSettings();

        $rotated   = $this->makeMember(id: 1, position: 100, rotation: '2024-01-01');
        $successor = $this->makeMember(id: 2, position: 100, rotation: '2025-01-01');

        $pruner = $this->makePruner([$rotated, $successor], settings: $settings);
        $result = $pruner->prune(rotationGraceMonths: 3, inactivityMonths: 12);

        $this->assertSame(0, $result->getTrashedCount());
        $this->assertSame([], $pruner->getTrashedIds());

        // Skipping for "disabled" is recorded explicitly so an admin
        // looking at the result can tell "didn't run" apart from
        // "ran and found nothing to do".
        $skipReasons = array_column($result->getSkipped(), 'reason');
        $this->assertContains(PruneResult::SKIP_DISABLED, $skipReasons);

        // And neither pass even ran — the candidate counters stay at
        // zero, proving the short-circuit happened before findAll()
        // would have been called.
        $this->assertSame(0, $result->getOfficersConsidered());
        $this->assertSame(0, $result->getHomeGroupConsidered());
    }

    /** @test */
    public function it_runs_normally_when_settings_report_enabled(): void
    {
        // The complement of the previous test: with the same
        // scenario but the toggle on, the officer pass trashes
        // member 1 as expected. Proves the short-circuit is
        // conditional on the flag, not on the presence of settings.
        $GLOBALS['scrutiny_test_options'] = [
            PrunerSettings::OPTION_ENABLED => 1,
        ];
        $settings = new PrunerSettings();

        $rotated   = $this->makeMember(id: 1, position: 100, rotation: '2024-01-01');
        $successor = $this->makeMember(id: 2, position: 100, rotation: '2025-01-01');

        $pruner = $this->makePruner([$rotated, $successor], settings: $settings);
        $pruner->prune(rotationGraceMonths: 3, inactivityMonths: 12);

        $this->assertSame([1], $pruner->getTrashedIds());
    }

    /** @test */
    public function it_runs_normally_when_no_settings_object_is_supplied(): void
    {
        // Settings parameter is optional for backward compatibility
        // with callers that don't have a settings instance to hand
        // (and for tests that exercise the pruning logic in
        // isolation). Null means "skip the toggle check entirely",
        // not "treat as disabled" — otherwise the test suite would
        // need a settings stub everywhere.
        $rotated   = $this->makeMember(id: 1, position: 100, rotation: '2024-01-01');
        $successor = $this->makeMember(id: 2, position: 100, rotation: '2025-01-01');

        $pruner = $this->makePruner([$rotated, $successor]); // no settings
        $pruner->prune(rotationGraceMonths: 3, inactivityMonths: 12);

        $this->assertSame([1], $pruner->getTrashedIds());
    }

    /** @test */
    public function disabled_flag_blocks_the_home_group_pass_too(): void
    {
        // The officer pass tests above prove the short-circuit
        // covers pass 1. This test is a belt-and-braces check that
        // it covers pass 2 as well — a member who would otherwise
        // be trashed under the inactivity rule must also be left
        // alone when the pruner is disabled.
        $GLOBALS['scrutiny_test_options'] = [
            PrunerSettings::OPTION_ENABLED => 0,
        ];
        $settings = new PrunerSettings();

        $stale = $this->makeMember(
            id: 5,
            homeGroup: 50,
            isGSR: false,
            updated: '2020-01-01 00:00:00'
        );

        $pruner = $this->makePruner([$stale], settings: $settings);
        $result = $pruner->prune(rotationGraceMonths: 3, inactivityMonths: 12);

        $this->assertSame([], $pruner->getTrashedIds());
        $this->assertSame(0, $result->getHomeGroupConsidered());
    }

    // ──────────────────────────────────────────────
    //  Test helpers
    // ──────────────────────────────────────────────

    /**
     * Build a Member implementation without going through the TSML
     * factory (which would need ACF and the WP database). Only the
     * accessors the pruner actually calls are populated.
     */
    private function makeMember(
        int $id,
        int $position = 0,
        string $rotation = '',
        int $homeGroup = 0,
        bool $isGSR = false,
        string $updated = ''
    ): Member {
        return new class($id, $position, $rotation, $homeGroup, $isGSR, $updated) implements Member {
            public function __construct(
                private int $id,
                private int $position,
                private string $rotation,
                private int $homeGroup,
                private bool $isGSR,
                private string $updated
            ) {}

            public function getId(): int { return $this->id; }
            public function getIntergroupPosition(): int { return $this->position; }
            public function getIntergroupPositionRotation(): string { return $this->rotation; }
            public function getHomeGroup(): int { return $this->homeGroup; }
            public function isGSR(): bool { return $this->isGSR; }
            public function getUpdated(): string { return $this->updated; }

            // Unused by the pruner; provide harmless defaults so the
            // interface contract is satisfied.
            public function getAnonymousName(): string { return ''; }
            public function showAnonymousName(): bool { return false; }
            public function showMemberProfile(): bool { return false; }
            public function getAnonymousProfile(): string { return ''; }
            public function getMeetingPO(): mixed { return null; }
            public function getPersonalEmail(): string { return ''; }
            public function getMobileNumber(): string { return ''; }
            public function isGdprAccepted(): bool { return false; }
            public function getGdprAcceptedAt(): string { return ''; }
            public function getGdprAcceptanceVersion(): string { return ''; }
            public function getGdprAcceptanceMethod(): string { return ''; }
            public function getGdprAcceptanceStatement(): string { return ''; }
        };
    }

    /**
     * @param array<Member> $members
     */
    private function makePruner(
        array $members,
        bool $trashSucceeds = true,
        ?PrunerSettings $settings = null
    ): MemberPrunerForTest {
        $repository = new InMemoryMemberRepository($members);

        return new MemberPrunerForTest(
            $repository,
            new DateTimeImmutable(self::NOW),
            $trashSucceeds,
            $settings
        );
    }

    /**
     * @param array<int> $expectedIds
     */
    private function assertTrashedIds(PruneResult $result, array $expectedIds): void
    {
        $actual = array_column($result->getTrashed(), 'member_id');
        sort($actual);
        sort($expectedIds);
        $this->assertSame($expectedIds, $actual);
    }
}

