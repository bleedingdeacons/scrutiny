<?php

declare(strict_types=1);

namespace Scrutiny\Tests\Unit\Cleanup;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Scrutiny\Cleanup\MemberPruner;
use Scrutiny\Cleanup\PruneResult;
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
    public function it_does_not_consider_members_without_a_home_group_in_the_inactivity_pass(): void
    {
        // No home group at all → nothing to clean up under either rule.
        // The inactivity pass should not even count them as a candidate.
        $orphan = $this->makeMember(id: 9, homeGroup: 0, updated: '2020-01-01 00:00:00');

        $pruner = $this->makePruner([$orphan]);
        $result = $pruner->prune(rotationGraceMonths: 3, inactivityMonths: 12);

        $this->assertSame([], $pruner->getTrashedIds());
        $this->assertSame(0, $result->getHomeGroupConsidered());
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
    private function makePruner(array $members, bool $trashSucceeds = true): MemberPrunerForTest
    {
        $repository = new InMemoryMemberRepository($members);

        return new MemberPrunerForTest(
            $repository,
            new DateTimeImmutable(self::NOW),
            $trashSucceeds
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

// ──────────────────────────────────────────────
//  Test doubles
// ──────────────────────────────────────────────

/**
 * In-memory MemberRepository: returns the array passed at construction.
 *
 * Only findAll() is used by the pruner; the other methods are
 * implemented as no-ops to satisfy the interface and would throw if
 * a future change to the pruner accidentally started using them.
 */
final class InMemoryMemberRepository implements MemberRepository
{
    /** @param array<Member> $members */
    public function __construct(private array $members) {}

    public function findById(int $id): ?Member
    {
        foreach ($this->members as $member) {
            if ($member->getId() === $id) {
                return $member;
            }
        }
        return null;
    }

    public function findAll(array $args = []): array
    {
        return $this->members;
    }

    public function count(array $args = []): int
    {
        return count($this->members);
    }

    public function create(string $anonymousName): int
    {
        throw new \LogicException('Not implemented in test double');
    }

    public function save(Member $member): bool
    {
        throw new \LogicException('Not implemented in test double');
    }

    public function delete(int $id): bool
    {
        throw new \LogicException('Not implemented in test double');
    }

    public function update(Member $member): bool
    {
        throw new \LogicException('Not implemented in test double');
    }
}

/**
 * Subclass that records IDs passed to trashMember() and short-circuits
 * the wp_trash_post call. This lets the suite assert exactly which
 * members would be trashed without needing a WordPress runtime.
 */
final class MemberPrunerForTest extends MemberPruner
{
    /** @var array<int> */
    private array $trashed = [];

    public function __construct(
        MemberRepository $members,
        DateTimeImmutable $now,
        private bool $trashSucceeds
    ) {
        parent::__construct($members, $now);
    }

    /** @return array<int> */
    public function getTrashedIds(): array
    {
        return $this->trashed;
    }

    protected function trashMember(int $memberId): bool
    {
        if (!$this->trashSucceeds) {
            return false;
        }
        $this->trashed[] = $memberId;
        return true;
    }
}
