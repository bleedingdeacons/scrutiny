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
 * Logging tests for MemberPruner.
 *
 * These tests don't re-cover the pruner's decision logic — that's exercised
 * exhaustively in MemberPrunerTest. They focus narrowly on the wp_log()
 * output: which messages are emitted, at which level, with which context, in
 * which order.
 *
 * The bootstrap's wp_log() stub records every call into a global array.
 * beforeEach() resets that array so each test sees a clean recording slate.
 */

const LOGGING_NOW = '2025-07-15 12:00:00';

/**
 * Filter the recorded log stream by message and / or level.
 *
 * @return array<int, array{channel:string, level:string, message:string, context:array<string, mixed>}>
 */
function logEntriesWhere(?string $message = null, ?string $level = null): array
{
    $matches = [];
    foreach ($GLOBALS['scrutiny_test_log_entries'] as $entry) {
        if ($message !== null && $entry['message'] !== $message) {
            continue;
        }
        if ($level !== null && $entry['level'] !== $level) {
            continue;
        }
        $matches[] = $entry;
    }
    return $matches;
}

/**
 * @return list<string> every recorded message, in order
 */
function loggedMessages(): array
{
    return array_map(static fn (array $entry) => $entry['message'], $GLOBALS['scrutiny_test_log_entries']);
}

/**
 * The same Member double MemberPrunerTest uses. Duplicated rather than shared
 * because the alternative — exporting a helper into a common file — would
 * create a coupling that obscures what each test file needs.
 */
function loggedMember(
    int $id,
    int $position = 0,
    string $rotation = '',
    int $homeGroup = 0,
    bool $isGSR = false,
    string $updated = ''
): Member {
    return new MemberStub(
        id: $id,
        intergroupPosition: $position,
        intergroupPositionRotation: $rotation,
        homeGroup: $homeGroup,
        isGSR: $isGSR,
        updated: $updated,
    );
}

/**
 * @param array<Member> $members
 */
function loggedPruner(array $members, bool $trashSucceeds = true, ?PrunerSettings $settings = null): MemberPrunerForTest
{
    return new MemberPrunerForTest(
        new InMemoryMemberRepository($members, rejectWrites: true),
        new DateTimeImmutable(LOGGING_NOW),
        $trashSucceeds,
        $settings
    );
}

beforeEach(function () {
    // Reset the recorded log stream so assertions only see entries from the
    // current test.
    $GLOBALS['scrutiny_test_log_entries'] = [];

    // Reset option store too — settings tests in the same suite can otherwise
    // leave the disabled flag set to a stale value.
    $GLOBALS['scrutiny_test_options'] = [];
});

describe('per-member entries', function () {
    it('logs one info entry per trashed member', function () {
        // Two trashable members in two different categories so we can verify
        // the per-member log entries carry the correct reason and detail for
        // each.
        loggedPruner([
            loggedMember(id: 1, position: 100, rotation: '2024-01-01'),
            loggedMember(id: 2, position: 100, rotation: '2025-01-01'),
            loggedMember(id: 3, position: 0, homeGroup: 0, updated: '2024-01-15 10:00:00'),
        ])->prune(rotationGraceMonths: 3, inactivityMonths: 12);

        $trashedLogs = logEntriesWhere(message: 'Member trashed by pruner');

        expect($trashedLogs)->toHaveCount(2);

        // Index by member_id for order-independent assertions.
        $byMemberId = [];
        foreach ($trashedLogs as $entry) {
            $byMemberId[$entry['context']['member_id']] = $entry;
        }

        expect($byMemberId[1]['level'])->toBe('info')
            ->and($byMemberId[1]['context']['reason'])->toBe(PruneResult::REASON_OFFICER_ROTATED)
            ->and($byMemberId[1]['channel'])->toBe('scrutiny')
            ->and($byMemberId[3]['level'])->toBe('info')
            ->and($byMemberId[3]['context']['reason'])->toBe(PruneResult::REASON_ORPHAN_INACTIVE);
    });

    it('carries the detail string from the result', function () {
        // The detail string is what the pruner already stores on the result
        // (e.g. "position=100 rotation=2024-01-01"). It needs to round-trip
        // through to the log so a reader can see the facts behind each
        // decision without cross-referencing.
        loggedPruner([
            loggedMember(id: 1, position: 100, rotation: '2024-01-01'),
            loggedMember(id: 2, position: 100, rotation: '2025-01-01'),
        ])->prune(rotationGraceMonths: 3, inactivityMonths: 12);

        $trashedLogs = logEntriesWhere(message: 'Member trashed by pruner');

        expect($trashedLogs)->toHaveCount(1)
            ->and($trashedLogs[0]['context']['detail'])->toContain('position=100', 'rotation=2024-01-01');
    });

    it('logs a warning when wp_trash_post fails', function () {
        // Failures get WARNING rather than INFO so they surface in monitoring
        // filters keyed on log level. The WARNING entry must carry the same
        // identifying fields as the INFO entries for successful trashes so a
        // log reader can correlate.
        loggedPruner([
            loggedMember(id: 1, position: 100, rotation: '2024-01-01'),
            loggedMember(id: 2, position: 100, rotation: '2025-01-01'),
        ], trashSucceeds: false)->prune(rotationGraceMonths: 3, inactivityMonths: 12);

        $warnings = logEntriesWhere(level: 'warning');

        expect($warnings)->toHaveCount(1)
            ->and($warnings[0]['message'])->toBe('Pruner failed to trash a member')
            ->and($warnings[0]['context']['member_id'])->toBe(1)
            ->and($warnings[0]['context']['reason'])->toBe(PruneResult::SKIP_TRASH_FAILED)
            // And the corresponding INFO "trashed" entry must NOT have been
            // emitted — a failed trash is a warning, not a successful action,
            // so logging both would be misleading.
            ->and(logEntriesWhere(message: 'Member trashed by pruner'))->toHaveCount(0);
    });

    it('does not log routine skips per member', function () {
        // "Officer not due" / "home-group recent" skips can run into the
        // thousands on a healthy install. Logging each one would drown out the
        // destructive entries and the warning entries. They must stay
        // aggregated in the summary entry only.
        loggedPruner([
            loggedMember(id: 1, position: 100, rotation: '2025-06-01'),
            loggedMember(id: 2, position: 100, rotation: '2025-07-01'),
            loggedMember(id: 3, homeGroup: 50, isGSR: false, updated: '2025-06-01 00:00:00'),
        ])->prune(rotationGraceMonths: 3, inactivityMonths: 12);

        // No per-member entries at all — only the closing summary.
        expect(logEntriesWhere(message: 'Member trashed by pruner'))->toHaveCount(0)
            ->and(logEntriesWhere(level: 'warning'))->toHaveCount(0);
    });
});

describe('the summary entry', function () {
    it('closes the run with the counters', function () {
        // Build a mixed scenario so all the counters carry non-zero values and
        // the summary's shape can be asserted in one pass.
        loggedPruner([
            loggedMember(id: 1, position: 100, rotation: '2024-01-01'),
            loggedMember(id: 2, position: 100, rotation: '2025-01-01'),
            loggedMember(id: 3, homeGroup: 50, isGSR: false, updated: '2024-01-01 00:00:00'),
            loggedMember(id: 4, homeGroup: 50, isGSR: false, updated: '2025-06-01 00:00:00'),
            loggedMember(id: 5, position: 0, homeGroup: 0, updated: '2024-01-01 00:00:00'),
        ])->prune(rotationGraceMonths: 3, inactivityMonths: 12);

        $summary = logEntriesWhere(message: 'Member prune complete');

        expect($summary)->toHaveCount(1)
            ->and($summary[0]['level'])->toBe('info')
            ->and($summary[0]['context'])
            // 3 trashed: id 1 (officer), id 3 (home-group), id 5 (orphan).
            ->trashed->toBe(3)
            // 2 skipped: id 2 (incumbent), id 4 (recent home-group). The
            // incumbent is recorded under SKIP_OFFICER_EARLIER_PEER_EXISTS;
            // the recent home-group under SKIP_HOME_GROUP_RECENT.
            ->skipped->toBe(2)
            ->officers_considered->toBe(2)
            ->home_group_considered->toBe(2)
            ->orphans_considered->toBe(1)
            ->rotation_grace_months->toBe(3)
            ->inactivity_months->toBe(12)
            // Skip-category breakdown captures the routine skips so an admin
            // can spot drift in aggregate without per-row entries.
            ->skip_categories->toBe([
                PruneResult::SKIP_OFFICER_EARLIER_PEER_EXISTS => 1,
                PruneResult::SKIP_HOME_GROUP_RECENT           => 1,
            ]);
    });

    it('comes after the per-member entries', function () {
        // Order matters: the summary is meant to *close* the run, so it should
        // appear in the log stream after every per-member entry. A reader
        // scanning the log for "prune complete" knows that every prior entry
        // from the same channel belongs to that run.
        loggedPruner([
            loggedMember(id: 1, position: 100, rotation: '2024-01-01'),
            loggedMember(id: 2, position: 100, rotation: '2025-01-01'),
        ])->prune(rotationGraceMonths: 3, inactivityMonths: 12);

        $trashedIndex = array_search('Member trashed by pruner', loggedMessages(), true);
        $summaryIndex = array_search('Member prune complete', loggedMessages(), true);

        expect($trashedIndex)->not->toBeFalse()
            ->and($summaryIndex)->not->toBeFalse()
            ->and($summaryIndex)->toBeGreaterThan($trashedIndex);
    });
});

it('emits only the disabled entry when the pruner is switched off', function () {
    // When the pruner is disabled, prune() returns immediately without any
    // per-member work. The log stream must reflect that: a single entry
    // explaining why nothing happened, no summary, no per-member entries.
    $GLOBALS['scrutiny_test_options'] = [
        PrunerSettings::OPTION_ENABLED => 0,
    ];

    loggedPruner([
        loggedMember(id: 1, position: 100, rotation: '2024-01-01'),
        loggedMember(id: 2, position: 100, rotation: '2025-01-01'),
    ], settings: new PrunerSettings())->prune(rotationGraceMonths: 3, inactivityMonths: 12);

    expect(loggedMessages())
        ->toContain('Member prune skipped: pruner is disabled in settings')
        ->not->toContain('Member trashed by pruner')
        ->not->toContain('Member prune complete');
});

it('puts every entry on the scrutiny channel', function () {
    // Every entry the pruner emits must land on the 'scrutiny' channel so an
    // operator filtering Sentinel by channel can see the full picture of one
    // run. The trait derives the channel name from MemberPruner::logChannel(),
    // which is hard-coded to 'scrutiny' — this test pins that down so a future
    // refactor doesn't accidentally split the stream across multiple channels.
    //
    // Fail the trash on this run so the warning path is exercised alongside
    // the info paths.
    loggedPruner([
        loggedMember(id: 1, position: 100, rotation: '2024-01-01'),
        loggedMember(id: 2, position: 100, rotation: '2025-01-01'),
        loggedMember(id: 3, position: 200, rotation: '2024-01-01'),
        loggedMember(id: 4, position: 200, rotation: '2025-01-01'),
    ], trashSucceeds: false)->prune(rotationGraceMonths: 3, inactivityMonths: 12);

    expect($GLOBALS['scrutiny_test_log_entries'])->not->toBeEmpty()
        ->each(fn ($entry) => $entry->channel->toBe('scrutiny'));
});
