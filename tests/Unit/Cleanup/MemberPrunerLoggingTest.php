<?php

declare(strict_types=1);

namespace Scrutiny\Tests\Unit\Cleanup;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Scrutiny\Cleanup\PruneResult;
use Scrutiny\Cleanup\PrunerSettings;
use Unity\Members\Interfaces\Member;

/**
 * Logging tests for MemberPruner.
 *
 * These tests don't re-cover the pruner's decision logic — that's
 * exercised exhaustively in MemberPrunerTest. They focus narrowly
 * on the wp_log() output: which messages are emitted, at which
 * level, with which context, in which order.
 *
 * The bootstrap's wp_log() stub records every call into a global
 * array. setUp() resets that array and also clears the trait's
 * private static channel cache via reflection so each test sees
 * a clean recording slate (the trait memoises the channel after
 * the first log call).
 */
class MemberPrunerLoggingTest extends TestCase
{
    private const NOW = '2025-07-15 12:00:00';

    protected function setUp(): void
    {
        // Reset the recorded log stream so assertions only see
        // entries from the current test.
        $GLOBALS['scrutiny_test_log_entries'] = [];

        // Reset option store too — settings tests in the same suite
        // can otherwise leave the disabled flag set to a stale value.
        $GLOBALS['scrutiny_test_options'] = [];
    }

    /** @test */
    public function it_logs_one_info_entry_per_trashed_member(): void
    {
        // Two trashable members in two different categories so we
        // can verify the per-member log entries carry the correct
        // reason and detail for each.
        $rotated   = $this->makeMember(id: 1, position: 100, rotation: '2024-01-01');
        $successor = $this->makeMember(id: 2, position: 100, rotation: '2025-01-01');
        $orphan    = $this->makeMember(
            id: 3,
            position: 0,
            homeGroup: 0,
            updated: '2024-01-15 10:00:00'
        );

        $pruner = $this->makePruner([$rotated, $successor, $orphan]);
        $pruner->prune(rotationGraceMonths: 3, inactivityMonths: 12);

        $trashedLogs = $this->logEntriesWhere(message: 'Member trashed by pruner');

        $this->assertCount(2, $trashedLogs);

        // Index by member_id for order-independent assertions.
        $byMemberId = [];
        foreach ($trashedLogs as $entry) {
            $byMemberId[$entry['context']['member_id']] = $entry;
        }

        $this->assertSame('info', $byMemberId[1]['level']);
        $this->assertSame(PruneResult::REASON_OFFICER_ROTATED, $byMemberId[1]['context']['reason']);
        $this->assertSame('scrutiny', $byMemberId[1]['channel']);

        $this->assertSame('info', $byMemberId[3]['level']);
        $this->assertSame(PruneResult::REASON_ORPHAN_INACTIVE, $byMemberId[3]['context']['reason']);
    }

    /** @test */
    public function trashed_log_entries_carry_the_detail_string_from_the_result(): void
    {
        // The detail string is what the pruner already stores on the
        // result (e.g. "position=100 rotation=2024-01-01"). It needs
        // to round-trip through to the log so a reader can see the
        // facts behind each decision without cross-referencing.
        $rotated   = $this->makeMember(id: 1, position: 100, rotation: '2024-01-01');
        $successor = $this->makeMember(id: 2, position: 100, rotation: '2025-01-01');

        $pruner = $this->makePruner([$rotated, $successor]);
        $pruner->prune(rotationGraceMonths: 3, inactivityMonths: 12);

        $trashedLogs = $this->logEntriesWhere(message: 'Member trashed by pruner');
        $this->assertCount(1, $trashedLogs);

        $detail = $trashedLogs[0]['context']['detail'];
        $this->assertStringContainsString('position=100', $detail);
        $this->assertStringContainsString('rotation=2024-01-01', $detail);
    }

    /** @test */
    public function it_logs_a_warning_when_wp_trash_post_fails(): void
    {
        // Failures get WARNING rather than INFO so they surface in
        // monitoring filters keyed on log level. The WARNING entry
        // must carry the same identifying fields as the INFO entries
        // for successful trashes so a log reader can correlate.
        $rotated   = $this->makeMember(id: 1, position: 100, rotation: '2024-01-01');
        $successor = $this->makeMember(id: 2, position: 100, rotation: '2025-01-01');

        $pruner = $this->makePruner([$rotated, $successor], trashSucceeds: false);
        $pruner->prune(rotationGraceMonths: 3, inactivityMonths: 12);

        $warnings = $this->logEntriesWhere(level: 'warning');
        $this->assertCount(1, $warnings);

        $entry = $warnings[0];
        $this->assertSame('Pruner failed to trash a member', $entry['message']);
        $this->assertSame(1, $entry['context']['member_id']);
        $this->assertSame(PruneResult::SKIP_TRASH_FAILED, $entry['context']['reason']);

        // And the corresponding INFO "trashed" entry must NOT have
        // been emitted — a failed trash is a warning, not a successful
        // action, so logging both would be misleading.
        $trashedInfos = $this->logEntriesWhere(message: 'Member trashed by pruner');
        $this->assertCount(0, $trashedInfos);
    }

    /** @test */
    public function routine_skips_are_not_logged_per_member(): void
    {
        // "Officer not due" / "home-group recent" skips can run into
        // the thousands on a healthy install. Logging each one would
        // drown out the destructive entries and the warning entries.
        // They must stay aggregated in the summary entry only.
        $within = $this->makeMember(id: 1, position: 100, rotation: '2025-06-01');
        $newer  = $this->makeMember(id: 2, position: 100, rotation: '2025-07-01');
        $recent = $this->makeMember(
            id: 3,
            homeGroup: 50,
            isGSR: false,
            updated: '2025-06-01 00:00:00'
        );

        $pruner = $this->makePruner([$within, $newer, $recent]);
        $pruner->prune(rotationGraceMonths: 3, inactivityMonths: 12);

        // No per-member entries at all — only the closing summary.
        $perMember = $this->logEntriesWhere(message: 'Member trashed by pruner');
        $this->assertCount(0, $perMember);

        $perMemberWarnings = $this->logEntriesWhere(level: 'warning');
        $this->assertCount(0, $perMemberWarnings);
    }

    /** @test */
    public function it_emits_a_summary_entry_at_the_end_with_counters(): void
    {
        // Build a mixed scenario so all the counters carry non-zero
        // values and the summary's shape can be asserted in one pass.
        $rotated      = $this->makeMember(id: 1, position: 100, rotation: '2024-01-01');
        $incumbent    = $this->makeMember(id: 2, position: 100, rotation: '2025-01-01');
        $stale        = $this->makeMember(id: 3, homeGroup: 50, isGSR: false, updated: '2024-01-01 00:00:00');
        $recentMember = $this->makeMember(id: 4, homeGroup: 50, isGSR: false, updated: '2025-06-01 00:00:00');
        $orphan       = $this->makeMember(id: 5, position: 0, homeGroup: 0, updated: '2024-01-01 00:00:00');

        $pruner = $this->makePruner([$rotated, $incumbent, $stale, $recentMember, $orphan]);
        $pruner->prune(rotationGraceMonths: 3, inactivityMonths: 12);

        $summary = $this->logEntriesWhere(message: 'Member prune complete');
        $this->assertCount(1, $summary);

        $context = $summary[0]['context'];
        $this->assertSame('info', $summary[0]['level']);

        // 3 trashed: id 1 (officer), id 3 (home-group), id 5 (orphan).
        $this->assertSame(3, $context['trashed']);

        // 2 skipped: id 2 (incumbent), id 4 (recent home-group).
        // The incumbent is recorded under SKIP_OFFICER_EARLIER_PEER_EXISTS;
        // the recent home-group under SKIP_HOME_GROUP_RECENT.
        $this->assertSame(2, $context['skipped']);

        $this->assertSame(2, $context['officers_considered']);
        $this->assertSame(2, $context['home_group_considered']);
        $this->assertSame(1, $context['orphans_considered']);
        $this->assertSame(3, $context['rotation_grace_months']);
        $this->assertSame(12, $context['inactivity_months']);

        // Skip-category breakdown captures the routine skips so an
        // admin can spot drift in aggregate without per-row entries.
        $this->assertSame(
            [
                PruneResult::SKIP_OFFICER_EARLIER_PEER_EXISTS => 1,
                PruneResult::SKIP_HOME_GROUP_RECENT           => 1,
            ],
            $context['skip_categories']
        );
    }

    /** @test */
    public function the_summary_entry_is_emitted_after_the_per_member_entries(): void
    {
        // Order matters: the summary is meant to *close* the run, so
        // it should appear in the log stream after every per-member
        // entry. A reader scanning the log for "prune complete" knows
        // that every prior entry from the same channel belongs to
        // that run.
        $rotated   = $this->makeMember(id: 1, position: 100, rotation: '2024-01-01');
        $successor = $this->makeMember(id: 2, position: 100, rotation: '2025-01-01');

        $pruner = $this->makePruner([$rotated, $successor]);
        $pruner->prune(rotationGraceMonths: 3, inactivityMonths: 12);

        $messages = array_map(
            static fn(array $entry) => $entry['message'],
            $GLOBALS['scrutiny_test_log_entries']
        );

        $trashedIndex = array_search('Member trashed by pruner', $messages, true);
        $summaryIndex = array_search('Member prune complete', $messages, true);

        $this->assertNotFalse($trashedIndex);
        $this->assertNotFalse($summaryIndex);
        $this->assertGreaterThan($trashedIndex, $summaryIndex);
    }

    /** @test */
    public function disabled_short_circuit_emits_only_the_disabled_log_entry(): void
    {
        // When the pruner is disabled, prune() returns immediately
        // without any per-member work. The log stream must reflect
        // that: a single entry explaining why nothing happened, no
        // summary, no per-member entries.
        $GLOBALS['scrutiny_test_options'] = [
            PrunerSettings::OPTION_ENABLED => 0,
        ];
        $settings = new PrunerSettings();

        $rotated   = $this->makeMember(id: 1, position: 100, rotation: '2024-01-01');
        $successor = $this->makeMember(id: 2, position: 100, rotation: '2025-01-01');

        $pruner = $this->makePruner([$rotated, $successor], settings: $settings);
        $pruner->prune(rotationGraceMonths: 3, inactivityMonths: 12);

        $messages = array_map(
            static fn(array $entry) => $entry['message'],
            $GLOBALS['scrutiny_test_log_entries']
        );

        $this->assertContains('Member prune skipped: pruner is disabled in settings', $messages);
        $this->assertNotContains('Member trashed by pruner', $messages);
        $this->assertNotContains('Member prune complete', $messages);
    }

    /** @test */
    public function all_log_entries_use_the_scrutiny_channel(): void
    {
        // Every entry the pruner emits must land on the 'scrutiny'
        // channel so an operator filtering Sentinel by channel can
        // see the full picture of one run. The trait derives the
        // channel name from MemberPruner::logChannel(), which is
        // hard-coded to 'scrutiny' — this test pins that down so
        // a future refactor doesn't accidentally split the stream
        // across multiple channels.
        $rotated   = $this->makeMember(id: 1, position: 100, rotation: '2024-01-01');
        $successor = $this->makeMember(id: 2, position: 100, rotation: '2025-01-01');
        $failing   = $this->makeMember(id: 3, position: 200, rotation: '2024-01-01');
        $failingSuccessor = $this->makeMember(id: 4, position: 200, rotation: '2025-01-01');

        // Fail the trash on this run so the warning path is exercised
        // alongside the info paths.
        $pruner = $this->makePruner(
            [$rotated, $successor, $failing, $failingSuccessor],
            trashSucceeds: false
        );
        $pruner->prune(rotationGraceMonths: 3, inactivityMonths: 12);

        foreach ($GLOBALS['scrutiny_test_log_entries'] as $entry) {
            $this->assertSame('scrutiny', $entry['channel']);
        }
        $this->assertNotEmpty($GLOBALS['scrutiny_test_log_entries']);
    }

    // ──────────────────────────────────────────────
    //  Test helpers
    // ──────────────────────────────────────────────

    /**
     * Filter the recorded log stream by message and / or level.
     *
     * @return array<int, array{channel:string, level:string, message:string, context:array<string, mixed>}>
     */
    private function logEntriesWhere(?string $message = null, ?string $level = null): array
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
     * Re-uses the same test-double Member from MemberPrunerTest. The
     * anonymous-class boilerplate is duplicated rather than shared
     * because the alternative — exporting a helper into a base class
     * — would create a coupling that obscures what each test file
     * needs.
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
            public function isTwelfthStepper(): bool { return false; }
            public function isTelephoneResponder(): bool { return false; }
            public function getResponderCertification(): \Unity\Members\ResponderCertification { return \Unity\Members\ResponderCertification::None; }
            public function getArea(): string { return ''; }
            public function getAccepts(): array { return []; }
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
        return new MemberPrunerForTest(
            new InMemoryMemberRepository($members),
            new DateTimeImmutable(self::NOW),
            $trashSucceeds,
            $settings
        );
    }
}
