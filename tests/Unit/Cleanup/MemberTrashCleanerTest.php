<?php

declare(strict_types=1);

namespace Scrutiny\Tests\Unit\Cleanup;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Scrutiny\Cleanup\MemberTrashCleaner;
use Scrutiny\Cleanup\TrashCleanResult;
use Unity\Members\Interfaces\Member;

/**
 * Tests for MemberTrashCleaner.
 *
 * The cleaner walks members returned by the repository, reads each
 * one's _wp_trash_meta_time post-meta, and permanently deletes any
 * whose trash timestamp is older than the configured retention.
 *
 * Strategy:
 *   - InMemoryMemberRepository (shared with the pruner tests) feeds
 *     trashed members into the cleaner.
 *   - The bootstrap's get_post_meta() stub returns whatever the
 *     test seeds into $GLOBALS['scrutiny_test_post_meta'].
 *   - A test subclass overrides deleteMember() to capture IDs
 *     without going through wp_delete_post — same pattern as
 *     MemberPrunerForTest.
 */
class MemberTrashCleanerTest extends TestCase
{
    private const NOW = '2025-07-15 12:00:00';

    protected function setUp(): void
    {
        // Reset everything that could leak between tests.
        $GLOBALS['scrutiny_test_post_meta']         = [];
        $GLOBALS['scrutiny_test_deleted_posts']     = [];
        $GLOBALS['scrutiny_test_log_entries']       = [];
        $GLOBALS['scrutiny_test_options']           = [];
        unset($GLOBALS['scrutiny_test_delete_returns_false']);
    }

    /** @test */
    public function it_deletes_a_trashed_member_past_the_retention_window(): void
    {
        // Trashed 14 days ago against a 7-day retention → eligible.
        $member = $this->makeMember(id: 1);
        $this->seedTrashTime(1, '2025-07-01 12:00:00');

        $cleaner = $this->makeCleaner([$member]);
        $result  = $cleaner->clean(retentionDays: 7);

        $this->assertSame(1, $result->getDeletedCount());
        $this->assertSame([1], $cleaner->getDeletedIds());

        $reasons = array_column($result->getDeleted(), 'reason', 'member_id');
        $this->assertSame(TrashCleanResult::REASON_RETENTION_EXPIRED, $reasons[1] ?? null);
    }

    /** @test */
    public function it_keeps_a_trashed_member_within_the_retention_window(): void
    {
        // Trashed 3 days ago against a 7-day retention → kept.
        $member = $this->makeMember(id: 2);
        $this->seedTrashTime(2, '2025-07-12 12:00:00');

        $cleaner = $this->makeCleaner([$member]);
        $result  = $cleaner->clean(retentionDays: 7);

        $this->assertSame(0, $result->getDeletedCount());
        $this->assertSame([], $cleaner->getDeletedIds());

        $skipReasons = array_column($result->getSkipped(), 'reason', 'member_id');
        $this->assertSame(TrashCleanResult::SKIP_RETENTION_NOT_REACHED, $skipReasons[2] ?? null);
    }

    /** @test */
    public function it_skips_a_member_with_no_trash_meta_time(): void
    {
        // Without a parseable trash timestamp the cleaner refuses to
        // act — permanent deletion is irreversible, so guessing isn't
        // safe. The skip is recorded so an admin can investigate.
        $member = $this->makeMember(id: 3);
        // No meta seeded for id 3 → get_post_meta returns ''.

        $cleaner = $this->makeCleaner([$member]);
        $result  = $cleaner->clean(retentionDays: 7);

        $this->assertSame(0, $result->getDeletedCount());

        $skipReasons = array_column($result->getSkipped(), 'reason', 'member_id');
        $this->assertSame(TrashCleanResult::SKIP_MISSING_TRASH_TIME, $skipReasons[3] ?? null);
    }

    /** @test */
    public function it_skips_a_member_when_trash_meta_time_is_zero(): void
    {
        // A "0" trash timestamp would be 1970, which can't be a
        // legitimate trash time — almost certainly bad data. The
        // cleaner refuses to act on it for the same safety reason
        // as the missing-meta case.
        $member = $this->makeMember(id: 4);
        $GLOBALS['scrutiny_test_post_meta'][4]['_wp_trash_meta_time'] = '0';

        $cleaner = $this->makeCleaner([$member]);
        $result  = $cleaner->clean(retentionDays: 7);

        $this->assertSame(0, $result->getDeletedCount());

        $skipReasons = array_column($result->getSkipped(), 'reason', 'member_id');
        $this->assertSame(TrashCleanResult::SKIP_MISSING_TRASH_TIME, $skipReasons[4] ?? null);
    }

    /** @test */
    public function it_records_skip_when_wp_delete_post_fails(): void
    {
        // Simulate WP returning false from wp_delete_post — the
        // cleaner must record the failure rather than treat it as
        // success.
        $GLOBALS['scrutiny_test_delete_returns_false'] = true;

        $member = $this->makeMember(id: 5);
        $this->seedTrashTime(5, '2025-07-01 12:00:00');

        // Use a real (non-overridden) cleaner so the wp_delete_post
        // path is actually exercised. That means it will call the
        // bootstrap stub, which honours scrutiny_test_delete_returns_false.
        $cleaner = new MemberTrashCleaner(
            new InMemoryMemberRepository([$member]),
            new DateTimeImmutable(self::NOW)
        );
        $result = $cleaner->clean(retentionDays: 7);

        $this->assertSame(0, $result->getDeletedCount());

        $skipReasons = array_column($result->getSkipped(), 'reason', 'member_id');
        $this->assertSame(TrashCleanResult::SKIP_DELETE_FAILED, $skipReasons[5] ?? null);
    }

    /** @test */
    public function negative_retention_is_clamped_to_zero(): void
    {
        // Defence in depth: a misconfigured caller passing negative
        // days must not pull the cutoff into the future and start
        // permanently deleting recently-trashed members.
        $member = $this->makeMember(id: 6);
        $this->seedTrashTime(6, '2025-07-15 11:00:00'); // an hour ago

        $cleaner = $this->makeCleaner([$member]);
        $cleaner->clean(retentionDays: -50);

        // With clamp at zero, anything trashed at or before "now"
        // qualifies — so this hour-ago member is deleted, but we
        // can't trash anything in the future.
        $this->assertSame([6], $cleaner->getDeletedIds());
    }

    /** @test */
    public function zero_retention_deletes_everything_currently_in_trash(): void
    {
        // An admin who deliberately sets retention to zero is asking
        // for "delete everything in the trash on the next run". The
        // cleaner must honour that without surprises.
        $a = $this->makeMember(id: 7);
        $b = $this->makeMember(id: 8);
        $this->seedTrashTime(7, '2025-07-15 11:00:00');
        $this->seedTrashTime(8, '2025-07-14 11:00:00');

        $cleaner = $this->makeCleaner([$a, $b]);
        $cleaner->clean(retentionDays: 0);

        $deleted = $cleaner->getDeletedIds();
        sort($deleted);
        $this->assertSame([7, 8], $deleted);
    }

    /** @test */
    public function empty_member_list_completes_cleanly(): void
    {
        // No work to do → result is empty, no errors, considered
        // counter is zero.
        $cleaner = $this->makeCleaner([]);
        $result  = $cleaner->clean(retentionDays: 7);

        $this->assertSame(0, $result->getDeletedCount());
        $this->assertSame(0, $result->getSkippedCount());
        $this->assertSame(0, $result->getConsidered());
    }

    /** @test */
    public function it_logs_a_summary_entry_at_info_level(): void
    {
        // Mirrors MemberPruner's logging contract: a single closing
        // summary entry with counters.
        $member = $this->makeMember(id: 9);
        $this->seedTrashTime(9, '2025-07-01 12:00:00');

        $cleaner = $this->makeCleaner([$member]);
        $cleaner->clean(retentionDays: 7);

        $summary = array_filter(
            $GLOBALS['scrutiny_test_log_entries'],
            static fn(array $entry) => $entry['message'] === 'Trash clean complete'
        );

        $this->assertCount(1, $summary);
        $entry = array_values($summary)[0];

        $this->assertSame('info', $entry['level']);
        $this->assertSame('scrutiny', $entry['channel']);
        $this->assertSame(1, $entry['context']['deleted']);
        $this->assertSame(7, $entry['context']['retention_days']);
    }

    /** @test */
    public function it_logs_per_deletion_at_info_and_per_failure_at_warning(): void
    {
        // Two trashed members; the second has missing meta so
        // surfaces as a SKIP_MISSING_TRASH_TIME warning. The first
        // produces an info-level "permanently deleted" entry.
        $deletable    = $this->makeMember(id: 10);
        $missingMeta  = $this->makeMember(id: 11);
        $this->seedTrashTime(10, '2025-07-01 12:00:00');
        // No meta for 11.

        $cleaner = $this->makeCleaner([$deletable, $missingMeta]);
        $cleaner->clean(retentionDays: 7);

        $messagesByLevel = [];
        foreach ($GLOBALS['scrutiny_test_log_entries'] as $entry) {
            $messagesByLevel[$entry['level']][] = $entry['message'];
        }

        $this->assertContains('Trashed member permanently deleted', $messagesByLevel['info'] ?? []);
        $this->assertContains('Trash cleaner could not delete a member', $messagesByLevel['warning'] ?? []);
    }

    // ──────────────────────────────────────────────
    //  Helpers
    // ──────────────────────────────────────────────

    /**
     * Seed _wp_trash_meta_time for a given member ID.
     *
     * Stored as a string (which is what WP itself writes) so the
     * cleaner's parsing path is exercised.
     */
    private function seedTrashTime(int $memberId, string $iso): void
    {
        $stamp = (new DateTimeImmutable($iso))->getTimestamp();
        $GLOBALS['scrutiny_test_post_meta'][$memberId]['_wp_trash_meta_time'] = (string) $stamp;
    }

    /**
     * Same anonymous-class Member double used by the pruner tests,
     * trimmed to just the accessors the cleaner touches (getId).
     */
    private function makeMember(int $id): Member
    {
        return new class($id) implements Member {
            public function __construct(private int $id) {}

            public function getId(): int { return $this->id; }
            public function getIntergroupPosition(): int { return 0; }
            public function getIntergroupPositionRotation(): string { return ''; }
            public function getHomeGroup(): int { return 0; }
            public function isGSR(): bool { return false; }
            public function getUpdated(): string { return ''; }

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
    private function makeCleaner(array $members): MemberTrashCleanerForTest
    {
        return new MemberTrashCleanerForTest(
            new InMemoryMemberRepository($members),
            new DateTimeImmutable(self::NOW)
        );
    }
}

/**
 * Test subclass that records IDs passed to deleteMember() and
 * short-circuits the wp_delete_post call. Same pattern as
 * MemberPrunerForTest.
 *
 * One test (it_records_skip_when_wp_delete_post_fails) bypasses
 * this subclass and uses the real MemberTrashCleaner so the
 * bootstrap's wp_delete_post stub gets exercised.
 */
final class MemberTrashCleanerForTest extends MemberTrashCleaner
{
    /** @var array<int> */
    private array $deleted = [];

    /** @return array<int> */
    public function getDeletedIds(): array
    {
        return $this->deleted;
    }

    protected function deleteMember(int $memberId): bool
    {
        $this->deleted[] = $memberId;
        return true;
    }
}
