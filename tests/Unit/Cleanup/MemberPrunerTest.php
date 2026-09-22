<?php

declare(strict_types=1);

namespace Scrutiny\Tests\Unit\Cleanup;

use DateTimeImmutable;
use Scrutiny\Cleanup\PruneResult;
use Scrutiny\Cleanup\PrunerSettings;
use Unity\Members\Interfaces\Member;
use Unity\Testing\Doubles\InMemoryMemberRepository;
use Unity\Testing\Doubles\MemberStub;

/*
 * Tests for MemberPruner.
 *
 * The pruner is exercised end-to-end through prune(), with a fake repository
 * feeding the in-memory member list and a test subclass overriding
 * trashMember() so wp_trash_post is never called. "Now" is fixed via the
 * constructor so date-based assertions are stable.
 */

/**
 * Fixed reference time for all tests. Picked deliberately mid-month so a
 * "-X months" arithmetic doesn't snap onto a month boundary and accidentally
 * line up with a test fixture.
 */
const PRUNE_NOW = '2025-07-15 12:00:00';

/**
 * Build a Member implementation without going through the TSML factory (which
 * would need ACF and the WP database). Only the accessors the pruner actually
 * calls are populated.
 */
function pruneMember(
    int $id,
    int $position = 0,
    string $rotation = '',
    int $homeGroup = 0,
    bool $isGSR = false,
    string $updated = '',
    bool $isTwelfthStepper = false
): Member {
    return new MemberStub(
        id: $id,
        intergroupPosition: $position,
        intergroupPositionRotation: $rotation,
        homeGroup: $homeGroup,
        isGSR: $isGSR,
        twelfthStepper: $isTwelfthStepper,
        updated: $updated,
    );
}

/**
 * @param array<Member> $members
 */
function prunerOver(array $members, bool $trashSucceeds = true, ?PrunerSettings $settings = null): MemberPrunerForTest
{
    return new MemberPrunerForTest(
        new InMemoryMemberRepository($members, rejectWrites: true),
        new DateTimeImmutable(PRUNE_NOW),
        $trashSucceeds,
        $settings
    );
}

/**
 * @return list<int> the trashed member ids in the result, sorted
 */
function trashedIdsIn(PruneResult $result): array
{
    $ids = array_column($result->getTrashed(), 'member_id');
    sort($ids);

    return $ids;
}

/**
 * @return array<int, string> the reason each member was trashed, by member id
 */
function trashReasons(PruneResult $result): array
{
    return array_column($result->getTrashed(), 'reason', 'member_id');
}

/**
 * @return array<int, string> the reason each member was skipped, by member id
 */
function pruneSkipReasons(PruneResult $result): array
{
    return array_column($result->getSkipped(), 'reason', 'member_id');
}

/**
 * A disabled or enabled PrunerSettings, read from the option store as the
 * pruner reads it.
 */
function prunerSettingsEnabled(bool $enabled): PrunerSettings
{
    $GLOBALS['scrutiny_test_options'] = [
        PrunerSettings::OPTION_ENABLED => $enabled ? 1 : 0,
    ];

    return new PrunerSettings();
}

// ──────────────────────────────────────────────
//  Officer pass
// ──────────────────────────────────────────────
describe('the officer pass', function () {
    it('trashes an officer whose rotation is past when a successor with a later rotation exists', function () {
        $pruner = prunerOver([
            pruneMember(id: 1, position: 100, rotation: '2024-01-01'),
            pruneMember(id: 2, position: 100, rotation: '2025-01-01'),
        ]);
        $result = $pruner->prune(rotationGraceMonths: 3, inactivityMonths: 12);

        expect(trashedIdsIn($result))->toBe([1])
            ->and($pruner->getTrashedIds())->toBe([1])
            ->and($result->getOfficersConsidered())->toBe(2);
    });

    it('keeps the current incumbent even when their rotation is past', function () {
        // Both rotated long ago. The later one is still the incumbent because
        // there is no successor with an even later date — pruning them would
        // leave the position empty, which is the exact scenario the rule is
        // designed to prevent.
        $result = prunerOver([
            pruneMember(id: 1, position: 100, rotation: '2023-01-01'),
            pruneMember(id: 2, position: 100, rotation: '2024-01-01'),
        ])->prune(rotationGraceMonths: 3, inactivityMonths: 12);

        // Earlier one is trashed, later one is kept as the incumbent.
        expect(trashedIdsIn($result))->toBe([1])
            // The reason the incumbent was skipped is recorded.
            ->and(pruneSkipReasons($result)[2] ?? null)->toBe(PruneResult::SKIP_OFFICER_EARLIER_PEER_EXISTS);
    });

    it('does not trash a lone officer with a past rotation', function () {
        // Single officer for the position. They are the incumbent by
        // definition — there is no successor to take over — so the pruner
        // leaves them in place even though their rotation is years past.
        $pruner = prunerOver([pruneMember(id: 1, position: 100, rotation: '2020-01-01')]);
        $result = $pruner->prune(rotationGraceMonths: 3, inactivityMonths: 12);

        expect($pruner->getTrashedIds())->toBe([])
            ->and($result->getTrashedCount())->toBe(0);
    });

    it('does not trash an officer still within the grace period', function () {
        // Rotation was 2 months ago, grace period is 3 months — the officer is
        // still within their post-rotation grace window and should not be
        // touched, even though a successor exists.
        $pruner = prunerOver([
            pruneMember(id: 1, position: 100, rotation: '2025-05-01'),
            pruneMember(id: 2, position: 100, rotation: '2025-07-01'),
        ]);
        $result = $pruner->prune(rotationGraceMonths: 3, inactivityMonths: 12);

        expect($pruner->getTrashedIds())->toBe([])
            ->and(pruneSkipReasons($result)[1] ?? null)->toBe(PruneResult::SKIP_OFFICER_NOT_DUE);
    });

    it('records a skip when an officer has an unparseable rotation date', function () {
        // Garbage rotation value can't be compared to the cutoff, so the
        // pruner refuses to act on it and surfaces the situation in the result
        // rather than swallowing it.
        $pruner = prunerOver([pruneMember(id: 1, position: 100, rotation: 'not-a-date')]);
        $result = $pruner->prune(rotationGraceMonths: 3, inactivityMonths: 12);

        expect($pruner->getTrashedIds())->toBe([])
            ->and(pruneSkipReasons($result)[1] ?? null)->toBe(PruneResult::SKIP_OFFICER_INVALID_ROTATION);
    });

    it('accepts the d/m/Y rotation format as a fallback', function () {
        // Imports and older code paths may bypass the factory's normaliser and
        // write d/m/Y directly. The pruner accepts that format too so
        // historical data isn't silently skipped.
        $pruner = prunerOver([
            pruneMember(id: 1, position: 100, rotation: '01/01/2024'),
            pruneMember(id: 2, position: 100, rotation: '2025-01-01'),
        ]);
        $pruner->prune(rotationGraceMonths: 3, inactivityMonths: 12);

        expect($pruner->getTrashedIds())->toBe([1]);
    });

    it('does not treat a member with an invalid rotation as the incumbent', function () {
        // The pruner picks the incumbent by latest *parseable* rotation date.
        // A peer with a malformed rotation must not be allowed to become the
        // de-facto incumbent and shield a real, rotated member from pruning —
        // that would let bad data hide stale officers indefinitely.
        $pruner = prunerOver([
            pruneMember(id: 1, position: 100, rotation: '2024-01-01'),
            pruneMember(id: 2, position: 100, rotation: 'garbage'),
            pruneMember(id: 3, position: 100, rotation: '2025-01-01'),
        ]);
        $pruner->prune(rotationGraceMonths: 3, inactivityMonths: 12);

        // 1 trashed (rotated), 2 skipped as invalid, 3 is the incumbent.
        expect($pruner->getTrashedIds())->toBe([1]);
    });

    it('groups officers by position so other positions do not interfere', function () {
        // Position 100 has a successor; position 200 does not. The member with
        // the past rotation under position 100 should be trashed, but the lone
        // member under position 200 should be left alone — an unrelated
        // position must not provide cover and must not provide grounds either.
        $pruner = prunerOver([
            pruneMember(id: 1, position: 100, rotation: '2024-01-01'),
            pruneMember(id: 2, position: 100, rotation: '2025-01-01'),
            pruneMember(id: 3, position: 200, rotation: '2020-01-01'),
        ]);
        $pruner->prune(rotationGraceMonths: 3, inactivityMonths: 12);

        expect($pruner->getTrashedIds())->toBe([1]);
    });
});

// ──────────────────────────────────────────────
//  Home-group non-GSR pass
// ──────────────────────────────────────────────
describe('the home-group non-GSR pass', function () {
    it('trashes a home-group non-GSR member inactive beyond the threshold', function () {
        // No position, has a home group, isn't the GSR, last updated 18 months
        // ago — well past a 12-month inactivity threshold, so they should be
        // pruned.
        $pruner = prunerOver([pruneMember(id: 5, homeGroup: 50, isGSR: false, updated: '2024-01-15 10:00:00')]);
        $result = $pruner->prune(rotationGraceMonths: 3, inactivityMonths: 12);

        expect($pruner->getTrashedIds())->toBe([5])
            ->and($result->getHomeGroupConsidered())->toBe(1);
    });

    it('does not trash a home-group member who is a GSR', function () {
        // GSRs are explicitly exempt — they're the formal intergroup
        // representative for their home group, so the pruner mustn't act on
        // them under the inactivity rule no matter how stale.
        $pruner = prunerOver([pruneMember(id: 6, homeGroup: 50, isGSR: true, updated: '2020-01-01 00:00:00')]);
        $pruner->prune(rotationGraceMonths: 3, inactivityMonths: 12);

        expect($pruner->getTrashedIds())->toBe([]);
    });

    it('does not trash a home-group member within the inactivity window', function () {
        // Last updated 6 months ago against a 12-month threshold — still
        // active enough to keep, even though they have a home group and aren't
        // the GSR.
        $pruner = prunerOver([pruneMember(id: 7, homeGroup: 50, isGSR: false, updated: '2025-01-15 10:00:00')]);
        $result = $pruner->prune(rotationGraceMonths: 3, inactivityMonths: 12);

        expect($pruner->getTrashedIds())->toBe([])
            ->and(pruneSkipReasons($result)[7] ?? null)->toBe(PruneResult::SKIP_HOME_GROUP_RECENT);
    });

    it('skips home-group members with no updated timestamp', function () {
        // Empty getUpdated() values can occur for posts loaded before
        // post_modified_gmt was populated, or in fixtures. The pruner refuses
        // to guess and records the situation so an admin can investigate
        // rather than silently trashing the row.
        $pruner = prunerOver([pruneMember(id: 8, homeGroup: 50, isGSR: false, updated: '')]);
        $result = $pruner->prune(rotationGraceMonths: 3, inactivityMonths: 12);

        expect($pruner->getTrashedIds())->toBe([])
            ->and(pruneSkipReasons($result)[8] ?? null)->toBe(PruneResult::SKIP_HOME_GROUP_INVALID_UPDATED);
    });

    it('does not take members without a home group', function () {
        // The home-group pass requires homeGroup > 0 — orphans (no home group,
        // no position) are owned by pass 3, not pass 2. The updated timestamp
        // here is recent so the orphan pass also doesn't trash; this test is
        // purely about the home-group-pass filter, not about pass 3.
        $pruner = prunerOver([pruneMember(id: 9, homeGroup: 0, updated: '2025-07-10 00:00:00')]);
        $result = $pruner->prune(rotationGraceMonths: 3, inactivityMonths: 12);

        expect($pruner->getTrashedIds())->toBe([])
            ->and($result->getHomeGroupConsidered())->toBe(0);
    });
});

// ──────────────────────────────────────────────
//  Twelfth-stepper protection
//
//  Cross-cutting rule that overrides the officer-rotation and
//  home-group-inactivity passes: a member who has a home group AND is
//  flagged as a twelfth stepper is an active service worker (they take
//  12th-step calls) and must never be silently trashed. These tests pin
//  the rule from both directions — protected members stay, unprotected
//  ones still go — and confirm the orphan pass is unaffected because the
//  rule requires a home group.
// ──────────────────────────────────────────────
describe('twelfth-stepper protection', function () {
    it('keeps a home-group member who is a twelfth stepper even when inactive', function () {
        // Updated long enough ago that the inactivity rule would normally
        // trash them, but the twelfth-stepper flag combined with the home
        // group shields them from the home-group pass.
        $pruner = prunerOver([
            pruneMember(id: 1, homeGroup: 50, isGSR: false, updated: '2023-01-01 00:00:00', isTwelfthStepper: true),
        ]);
        $result = $pruner->prune(rotationGraceMonths: 3, inactivityMonths: 12);

        expect($pruner->getTrashedIds())->toBe([])
            // The skip is recorded with the dedicated reason so an admin
            // reviewing the result can tell at a glance the member was
            // protected, not merely "recent enough".
            ->and(pruneSkipReasons($result)[1] ?? null)->toBe(PruneResult::SKIP_PROTECTED_TWELFTH_STEPPER);
    });

    it('still trashes an inactive home-group member who is not a twelfth stepper', function () {
        // Regression guard: the new protection rule must not change the
        // outcome for the common case. Same inactivity profile as the previous
        // test, but with the flag off → the home-group pass should still trash
        // them.
        $result = prunerOver([
            pruneMember(id: 2, homeGroup: 50, isGSR: false, updated: '2023-01-01 00:00:00', isTwelfthStepper: false),
        ])->prune(rotationGraceMonths: 3, inactivityMonths: 12);

        expect(trashedIdsIn($result))->toBe([2]);
    });

    it('requires a home group: an orphan twelfth stepper is not protected', function () {
        // A member flagged as a twelfth stepper but with no home group is
        // technically an orphan. The rule is "home group AND twelfth stepper"
        // — both halves matter. Without the home group, they fall through to
        // the orphan pass and the normal inactivity rule applies. Documents
        // the boundary explicitly so a future refactor can't quietly broaden
        // the shield.
        $result = prunerOver([
            pruneMember(id: 3, homeGroup: 0, updated: '2023-01-01 00:00:00', isTwelfthStepper: true),
        ])->prune(rotationGraceMonths: 3, inactivityMonths: 12);

        expect(trashedIdsIn($result))->toBe([3]);
    });

    it('keeps a rotated officer who has a home group and is a twelfth stepper', function () {
        // The protection is cross-cutting: it applies in the officer pass too,
        // not only the home-group pass. A former officer (rotation past,
        // successor present) who also has a home group and is a twelfth
        // stepper is still doing service work and must be kept. Without this
        // branch the officer pass would trash them before the home-group pass
        // ever ran.
        $pruner = prunerOver([
            pruneMember(id: 4, position: 100, rotation: '2024-01-01', homeGroup: 50, isTwelfthStepper: true),
            pruneMember(id: 5, position: 100, rotation: '2025-01-01'),
        ]);
        $result = $pruner->prune(rotationGraceMonths: 3, inactivityMonths: 12);

        expect($pruner->getTrashedIds())->toBe([])
            ->and(pruneSkipReasons($result)[4] ?? null)->toBe(PruneResult::SKIP_PROTECTED_TWELFTH_STEPPER);
    });

    it('still trashes a rotated officer who is a twelfth stepper without a home group', function () {
        // Mirror of the previous test for the officer pass: the
        // twelfth-stepper flag alone isn't enough — a home group is also
        // required for the shield to apply. Without one, the rotated officer
        // is trashed as normal.
        $result = prunerOver([
            pruneMember(id: 6, position: 100, rotation: '2024-01-01', homeGroup: 0, isTwelfthStepper: true),
            pruneMember(id: 7, position: 100, rotation: '2025-01-01'),
        ])->prune(rotationGraceMonths: 3, inactivityMonths: 12);

        expect(trashedIdsIn($result))->toBe([6]);
    });

    it('does not raise the home-group considered count beyond the normal path', function () {
        // The home-group pass increments its considered counter before the
        // protection check runs, so a protected member is still "considered" —
        // just skipped. This keeps the counter honest: it counts every
        // home-group member the pass examined, with the breakdown of *why*
        // each was skipped recorded in the skip entries.
        $result = prunerOver([
            pruneMember(id: 8, homeGroup: 50, updated: '2023-01-01 00:00:00', isTwelfthStepper: true),
            pruneMember(id: 9, homeGroup: 50, updated: '2025-07-10 00:00:00', isTwelfthStepper: false),
        ])->prune(rotationGraceMonths: 3, inactivityMonths: 12);

        expect($result->getHomeGroupConsidered())->toBe(2);
    });
});

// ──────────────────────────────────────────────
//  Orphan pass
//
//  Orphans are members with no intergroup position AND no home group —
//  registrations that were never tied to either an officer role or a
//  group. Pass 3 catches them under the same inactivity threshold the
//  home-group pass uses, deliberately: one knob configures both kinds of
//  "stale" cleanup.
// ──────────────────────────────────────────────
describe('the orphan pass', function () {
    it('trashes an orphan inactive beyond the threshold', function () {
        // No position, no home group, last updated 18 months ago against a
        // 12-month threshold. Should be trashed under the orphan rule and
        // recorded with REASON_ORPHAN_INACTIVE so an admin can tell the row
        // apart from home-group inactives.
        $pruner = prunerOver([pruneMember(id: 40, position: 0, homeGroup: 0, updated: '2024-01-15 10:00:00')]);
        $result = $pruner->prune(rotationGraceMonths: 3, inactivityMonths: 12);

        expect($pruner->getTrashedIds())->toBe([40])
            ->and($result->getOrphansConsidered())->toBe(1)
            ->and(trashReasons($result)[40] ?? null)->toBe(PruneResult::REASON_ORPHAN_INACTIVE);
    });

    it('does not trash a recent orphan', function () {
        // Updated 6 months ago against a 12-month threshold — within the
        // inactivity window, so kept.
        $pruner = prunerOver([pruneMember(id: 41, position: 0, homeGroup: 0, updated: '2025-01-15 10:00:00')]);
        $result = $pruner->prune(rotationGraceMonths: 3, inactivityMonths: 12);

        expect($pruner->getTrashedIds())->toBe([])
            ->and(pruneSkipReasons($result)[41] ?? null)->toBe(PruneResult::SKIP_ORPHAN_RECENT);
    });

    it('skips orphans with no updated timestamp', function () {
        // Same defensive treatment as the home-group pass: an empty updated
        // value can't be compared, so the pruner refuses to act and surfaces
        // the situation rather than silently trashing.
        $pruner = prunerOver([pruneMember(id: 42, position: 0, homeGroup: 0, updated: '')]);
        $result = $pruner->prune(rotationGraceMonths: 3, inactivityMonths: 12);

        expect($pruner->getTrashedIds())->toBe([])
            ->and(pruneSkipReasons($result)[42] ?? null)->toBe(PruneResult::SKIP_ORPHAN_INVALID_UPDATED);
    });

    it('does not take members with a home group', function () {
        // A home-group non-GSR is owned by pass 2 even when stale. The orphan
        // pass must skip them so the result records the trash under
        // REASON_HOME_GROUP_INACTIVE, not REASON_ORPHAN_INACTIVE.
        $pruner = prunerOver([pruneMember(id: 43, homeGroup: 50, isGSR: false, updated: '2024-01-15 10:00:00')]);
        $result = $pruner->prune(rotationGraceMonths: 3, inactivityMonths: 12);

        expect($pruner->getTrashedIds())->toBe([43])
            ->and($result->getOrphansConsidered())->toBe(0)
            ->and(trashReasons($result)[43] ?? null)->toBe(PruneResult::REASON_HOME_GROUP_INACTIVE);
    });

    it('does not take members with an intergroup position', function () {
        // A lone officer with a stale rotation date is kept by the officer
        // pass (no successor → can't be replaced) and must not then fall
        // through to the orphan pass and be trashed there for inactivity. The
        // position guard in pass 3 stops that.
        $pruner = prunerOver([
            pruneMember(id: 44, position: 100, rotation: '2020-01-01', homeGroup: 0, updated: '2020-01-01 00:00:00'),
        ]);
        $result = $pruner->prune(rotationGraceMonths: 3, inactivityMonths: 12);

        expect($pruner->getTrashedIds())->toBe([])
            ->and($result->getOrphansConsidered())->toBe(0);
    });

    it('uses the same inactivity threshold as the home-group pass', function () {
        // Two members with identical updated timestamps — one orphan, one
        // home-group non-GSR — must be treated identically. This pins down the
        // design decision that one knob controls both.
        $pruner = prunerOver([
            pruneMember(id: 50, position: 0, homeGroup: 0, updated: '2024-01-15 10:00:00'),
            pruneMember(id: 51, homeGroup: 50, isGSR: false, updated: '2024-01-15 10:00:00'),
        ]);
        $pruner->prune(rotationGraceMonths: 3, inactivityMonths: 12);

        // Both trashed under their respective rules.
        $trashedIds = $pruner->getTrashedIds();
        sort($trashedIds);

        expect($trashedIds)->toBe([50, 51]);
    });
});

// ──────────────────────────────────────────────
//  Cross-pass interaction
// ──────────────────────────────────────────────
describe('cross-pass interaction', function () {
    it('does not re-evaluate officers under the inactivity rule', function () {
        // A member with both an intergroup position and a home group is owned
        // by the officer pass: the home-group pass must skip them entirely so
        // a single member is never evaluated under two competing rules in the
        // same run.
        $pruner = prunerOver([
            pruneMember(
                id: 10,
                position: 100,
                rotation: '2024-01-01',
                homeGroup: 50,
                isGSR: false,
                updated: '2025-07-01 00:00:00' // very recent → would survive home-group rule
            ),
            pruneMember(id: 11, position: 100, rotation: '2025-01-01'),
        ]);
        $result = $pruner->prune(rotationGraceMonths: 3, inactivityMonths: 12);

        expect($pruner->getTrashedIds())->toBe([10])
            // The rotated member is recorded as "officer rotated", not "home
            // group inactive" — they were owned by the officer pass and never
            // reached the inactivity check.
            ->and(trashReasons($result)[10] ?? null)->toBe(PruneResult::REASON_OFFICER_ROTATED)
            // And the home-group counter never incremented for them.
            ->and($result->getHomeGroupConsidered())->toBe(0);
    });

    it('does not trash a member again in the home-group pass once the officer pass has', function () {
        // Belt-and-braces: even if the home-group pass were reached, an
        // already-trashed ID must not be re-submitted to wp_trash_post.
        $pruner = prunerOver([
            pruneMember(
                id: 20,
                position: 100,
                rotation: '2024-01-01',
                homeGroup: 50,
                isGSR: false,
                updated: '2020-01-01 00:00:00' // would also qualify under inactivity
            ),
            pruneMember(id: 21, position: 100, rotation: '2025-01-01'),
        ]);
        $pruner->prune(rotationGraceMonths: 3, inactivityMonths: 12);

        // Trashed exactly once.
        expect($pruner->getTrashedIds())->toBe([20]);
    });
});

// ──────────────────────────────────────────────
//  Former-GSR interactions
//
//  In this codebase a current GSR is identified by the combination
//  homeGroup > 0 AND isGSR === true. A *former* GSR is therefore a member
//  with homeGroup > 0 AND isGSR === false — they once held the role, the
//  group has moved on, and the flag has been cleared.
//
//  Such a member may simultaneously hold an intergroup officer position.
//  The officer pass must own that case: the rotation rule decides their
//  fate, and the home-group inactivity rule must not re-evaluate them.
//  These tests pin that down.
// ──────────────────────────────────────────────
describe('former GSRs', function () {
    it('trashes a former GSR with a rotated position under the officer rule', function () {
        // homeGroup set, isGSR === false (former GSR), and they hold a
        // position whose rotation is past with a successor in place. The
        // officer rule applies; the home-group rule does not run for them at
        // all.
        $pruner = prunerOver([
            pruneMember(
                id: 30,
                position: 100,
                rotation: '2024-01-01',
                homeGroup: 50,
                isGSR: false,
                updated: '2025-07-10 00:00:00' // recent enough to survive inactivity rule
            ),
            pruneMember(id: 31, position: 100, rotation: '2025-01-01'),
        ]);
        $result = $pruner->prune(rotationGraceMonths: 3, inactivityMonths: 12);

        expect($pruner->getTrashedIds())->toBe([30])
            // Recorded as officer-rotated, never reaches the home-group pass.
            ->and(trashReasons($result)[30] ?? null)->toBe(PruneResult::REASON_OFFICER_ROTATED)
            ->and($result->getHomeGroupConsidered())->toBe(0);
    });

    it('keeps a former GSR who is the current incumbent officer, however old their updated timestamp', function () {
        // Lone officer for the position (no successor), so they are the
        // current incumbent — kept by the officer rule. Their updated
        // timestamp is years old, which would otherwise qualify them under the
        // inactivity rule. The position-guard in the home-group pass prevents
        // that double-evaluation.
        $pruner = prunerOver([
            pruneMember(
                id: 32,
                position: 200,
                rotation: '2020-01-01', // long past, but they're the only holder
                homeGroup: 50,
                isGSR: false,
                updated: '2020-01-01 00:00:00' // ancient
            ),
        ]);
        $result = $pruner->prune(rotationGraceMonths: 3, inactivityMonths: 12);

        // Not trashed: officer rule keeps them as the incumbent, and the
        // home-group rule never sees them.
        expect($pruner->getTrashedIds())->toBe([])
            ->and($result->getHomeGroupConsidered())->toBe(0)
            // The skip reason confirms the officer pass acknowledged them
            // explicitly rather than letting them fall through silently.
            ->and(pruneSkipReasons($result)[32] ?? null)->toBe(PruneResult::SKIP_OFFICER_EARLIER_PEER_EXISTS);
    });

    it('keeps a former GSR within their rotation grace window and does not re-evaluate them', function () {
        // homeGroup + isGSR=false + position. Rotation is recent enough to be
        // inside the grace window, so the officer pass keeps them. The
        // home-group pass must not get a second crack at them on the basis of
        // an old updated timestamp.
        $pruner = prunerOver([
            pruneMember(
                id: 33,
                position: 300,
                rotation: '2025-06-01', // ~1.5 months ago, within 3-month grace
                homeGroup: 50,
                isGSR: false,
                updated: '2020-01-01 00:00:00' // ancient — would qualify under inactivity
            ),
            // Add a successor so the incumbent test isn't what's keeping them.
            pruneMember(id: 34, position: 300, rotation: '2025-07-10'),
        ]);
        $result = $pruner->prune(rotationGraceMonths: 3, inactivityMonths: 12);

        expect($pruner->getTrashedIds())->toBe([])
            ->and($result->getHomeGroupConsidered())->toBe(0)
            ->and(pruneSkipReasons($result)[33] ?? null)->toBe(PruneResult::SKIP_OFFICER_NOT_DUE);
    });
});

// ──────────────────────────────────────────────
//  Edge cases
// ──────────────────────────────────────────────
describe('edge cases', function () {
    it('clamps negative grace periods to zero', function () {
        // Defensive: a misconfigured caller passing negative months must not
        // have the cutoff slide into the future and start trashing
        // currently-valid members. We clamp to zero, which means "anything
        // past today qualifies".
        $pruner = prunerOver([
            pruneMember(id: 1, position: 100, rotation: '2025-07-14'),
            pruneMember(id: 2, position: 100, rotation: '2025-07-15'),
        ]);
        $pruner->prune(rotationGraceMonths: -50, inactivityMonths: -50);

        // With the clamp at zero, the rotated officer (yesterday) is past the
        // cutoff (today) and should be trashed.
        expect($pruner->getTrashedIds())->toBe([1]);
    });

    it('handles an empty member list without error', function () {
        $result = prunerOver([])->prune(rotationGraceMonths: 3, inactivityMonths: 12);

        expect($result->getTrashedCount())->toBe(0)
            ->and($result->getSkippedCount())->toBe(0)
            ->and($result->getOfficersConsidered())->toBe(0)
            ->and($result->getHomeGroupConsidered())->toBe(0);
    });

    it('records a skip when wp_trash_post fails', function () {
        // Simulate a WordPress failure: trashMember() returns false. The
        // pruner must not record a successful trash and must surface the
        // failure in the result so an admin can chase it.
        $result = prunerOver([
            pruneMember(id: 1, position: 100, rotation: '2024-01-01'),
            pruneMember(id: 2, position: 100, rotation: '2025-01-01'),
        ], trashSucceeds: false)->prune(rotationGraceMonths: 3, inactivityMonths: 12);

        expect($result->getTrashedCount())->toBe(0)
            ->and(pruneSkipReasons($result)[1] ?? null)->toBe(PruneResult::SKIP_TRASH_FAILED);
    });
});

// ──────────────────────────────────────────────
//  Disabled flag
//
//  The pruner reads PrunerSettings::isEnabled() at the start of prune()
//  and short-circuits if the toggle is off. The check lives on the
//  service so every caller (admin button, WP-CLI, cron) automatically
//  respects the toggle without each one having to remember to read the
//  flag separately.
// ──────────────────────────────────────────────
describe('the disabled flag', function () {
    it('short-circuits when the settings report disabled', function () {
        // Set up a scenario where the officer pass would normally trash member
        // 1. With settings reporting disabled, the pruner must return
        // immediately without trashing anything.
        $pruner = prunerOver([
            pruneMember(id: 1, position: 100, rotation: '2024-01-01'),
            pruneMember(id: 2, position: 100, rotation: '2025-01-01'),
        ], settings: prunerSettingsEnabled(false));
        $result = $pruner->prune(rotationGraceMonths: 3, inactivityMonths: 12);

        expect($result->getTrashedCount())->toBe(0)
            ->and($pruner->getTrashedIds())->toBe([])
            // Skipping for "disabled" is recorded explicitly so an admin
            // looking at the result can tell "didn't run" apart from "ran and
            // found nothing to do".
            ->and(array_column($result->getSkipped(), 'reason'))->toContain(PruneResult::SKIP_DISABLED)
            // And neither pass even ran — the candidate counters stay at zero,
            // proving the short-circuit happened before findAll() would have
            // been called.
            ->and($result->getOfficersConsidered())->toBe(0)
            ->and($result->getHomeGroupConsidered())->toBe(0);
    });

    it('runs normally when the settings report enabled', function () {
        // The complement of the previous test: with the same scenario but the
        // toggle on, the officer pass trashes member 1 as expected. Proves the
        // short-circuit is conditional on the flag, not on the presence of
        // settings.
        $pruner = prunerOver([
            pruneMember(id: 1, position: 100, rotation: '2024-01-01'),
            pruneMember(id: 2, position: 100, rotation: '2025-01-01'),
        ], settings: prunerSettingsEnabled(true));
        $pruner->prune(rotationGraceMonths: 3, inactivityMonths: 12);

        expect($pruner->getTrashedIds())->toBe([1]);
    });

    it('runs normally when no settings object is supplied', function () {
        // Settings parameter is optional for backward compatibility with
        // callers that don't have a settings instance to hand (and for tests
        // that exercise the pruning logic in isolation). Null means "skip the
        // toggle check entirely", not "treat as disabled" — otherwise the test
        // suite would need a settings stub everywhere.
        $pruner = prunerOver([
            pruneMember(id: 1, position: 100, rotation: '2024-01-01'),
            pruneMember(id: 2, position: 100, rotation: '2025-01-01'),
        ]); // no settings
        $pruner->prune(rotationGraceMonths: 3, inactivityMonths: 12);

        expect($pruner->getTrashedIds())->toBe([1]);
    });

    it('blocks the home-group pass too', function () {
        // The officer pass tests above prove the short-circuit covers pass 1.
        // This test is a belt-and-braces check that it covers pass 2 as well —
        // a member who would otherwise be trashed under the inactivity rule
        // must also be left alone when the pruner is disabled.
        $pruner = prunerOver(
            [pruneMember(id: 5, homeGroup: 50, isGSR: false, updated: '2020-01-01 00:00:00')],
            settings: prunerSettingsEnabled(false)
        );
        $result = $pruner->prune(rotationGraceMonths: 3, inactivityMonths: 12);

        expect($pruner->getTrashedIds())->toBe([])
            ->and($result->getHomeGroupConsidered())->toBe(0);
    });
});
