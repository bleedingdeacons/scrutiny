<?php

declare(strict_types=1);

namespace Scrutiny\Tests\Unit\Cleanup;

use DateTimeImmutable;
use Scrutiny\Cleanup\MemberTrashCleaner;
use Scrutiny\Cleanup\TrashCleanResult;
use Unity\Members\Interfaces\Member;
use Unity\Testing\Doubles\InMemoryMemberRepository;
use Unity\Testing\Doubles\MemberStub;

/*
 * Tests for MemberTrashCleaner.
 *
 * The cleaner walks members returned by the repository, reads each one's
 * _wp_trash_meta_time post-meta, and permanently deletes any whose trash
 * timestamp is older than the configured retention.
 *
 * Strategy:
 *   - InMemoryMemberRepository (shared with the pruner tests) feeds trashed
 *     members into the cleaner.
 *   - The bootstrap's get_post_meta() stub returns whatever the test seeds
 *     into $GLOBALS['scrutiny_test_post_meta'].
 *   - A test subclass overrides deleteMember() to capture IDs without going
 *     through wp_delete_post — same pattern as MemberPrunerForTest.
 */

const TRASH_CLEAN_NOW = '2025-07-15 12:00:00';

/**
 * Test subclass that records IDs passed to deleteMember() and short-circuits
 * the wp_delete_post call. Same pattern as MemberPrunerForTest.
 *
 * One test ("records a skip when wp_delete_post fails") bypasses this
 * subclass and uses the real MemberTrashCleaner so the bootstrap's
 * wp_delete_post stub gets exercised.
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

/**
 * Seed _wp_trash_meta_time for a given member ID.
 *
 * Stored as a string (which is what WP itself writes) so the cleaner's
 * parsing path is exercised.
 */
function seedTrashTime(int $memberId, string $iso): void
{
    $stamp = (new DateTimeImmutable($iso))->getTimestamp();
    $GLOBALS['scrutiny_test_post_meta'][$memberId]['_wp_trash_meta_time'] = (string) $stamp;
}

/**
 * @param array<Member> $members
 */
function trashCleaner(array $members): MemberTrashCleanerForTest
{
    return new MemberTrashCleanerForTest(
        new InMemoryMemberRepository($members, rejectWrites: true),
        new DateTimeImmutable(TRASH_CLEAN_NOW)
    );
}

/**
 * @return array<int, string> skip reasons keyed by member id
 */
function skipReasons(TrashCleanResult $result): array
{
    return array_column($result->getSkipped(), 'reason', 'member_id');
}

beforeEach(function () {
    // Reset everything that could leak between tests.
    $GLOBALS['scrutiny_test_post_meta']     = [];
    $GLOBALS['scrutiny_test_deleted_posts'] = [];
    $GLOBALS['scrutiny_test_log_entries']   = [];
    $GLOBALS['scrutiny_test_options']       = [];
    unset($GLOBALS['scrutiny_test_delete_returns_false']);
});

describe('the retention window', function () {
    it('deletes a trashed member past the retention window', function () {
        // Trashed 14 days ago against a 7-day retention → eligible.
        seedTrashTime(1, '2025-07-01 12:00:00');

        $cleaner = trashCleaner([new MemberStub(id: 1)]);
        $result  = $cleaner->clean(retentionDays: 7);

        expect($result->getDeletedCount())->toBe(1)
            ->and($cleaner->getDeletedIds())->toBe([1])
            ->and(array_column($result->getDeleted(), 'reason', 'member_id')[1] ?? null)
            ->toBe(TrashCleanResult::REASON_RETENTION_EXPIRED);
    });

    it('keeps a trashed member within the retention window', function () {
        // Trashed 3 days ago against a 7-day retention → kept.
        seedTrashTime(2, '2025-07-12 12:00:00');

        $cleaner = trashCleaner([new MemberStub(id: 2)]);
        $result  = $cleaner->clean(retentionDays: 7);

        expect($result->getDeletedCount())->toBe(0)
            ->and($cleaner->getDeletedIds())->toBe([])
            ->and(skipReasons($result)[2] ?? null)->toBe(TrashCleanResult::SKIP_RETENTION_NOT_REACHED);
    });

    it('clamps a negative retention to zero', function () {
        // Defence in depth: a misconfigured caller passing negative days must
        // not pull the cutoff into the future and start permanently deleting
        // recently-trashed members.
        seedTrashTime(6, '2025-07-15 11:00:00'); // an hour ago

        $cleaner = trashCleaner([new MemberStub(id: 6)]);
        $cleaner->clean(retentionDays: -50);

        // With clamp at zero, anything trashed at or before "now" qualifies —
        // so this hour-ago member is deleted, but we can't trash anything in
        // the future.
        expect($cleaner->getDeletedIds())->toBe([6]);
    });

    it('deletes everything currently in trash at zero retention', function () {
        // An admin who deliberately sets retention to zero is asking for
        // "delete everything in the trash on the next run". The cleaner must
        // honour that without surprises.
        seedTrashTime(7, '2025-07-15 11:00:00');
        seedTrashTime(8, '2025-07-14 11:00:00');

        $cleaner = trashCleaner([new MemberStub(id: 7), new MemberStub(id: 8)]);
        $cleaner->clean(retentionDays: 0);

        $deleted = $cleaner->getDeletedIds();
        sort($deleted);

        expect($deleted)->toBe([7, 8]);
    });

    it('completes cleanly with an empty member list', function () {
        // No work to do → result is empty, no errors, considered counter is
        // zero.
        $result = trashCleaner([])->clean(retentionDays: 7);

        expect($result->getDeletedCount())->toBe(0)
            ->and($result->getSkippedCount())->toBe(0)
            ->and($result->getConsidered())->toBe(0);
    });
});

describe('refusing to act', function () {
    it('skips a member with no trash meta time', function () {
        // Without a parseable trash timestamp the cleaner refuses to act —
        // permanent deletion is irreversible, so guessing isn't safe. The skip
        // is recorded so an admin can investigate.
        // No meta seeded for id 3 → get_post_meta returns ''.
        $result = trashCleaner([new MemberStub(id: 3)])->clean(retentionDays: 7);

        expect($result->getDeletedCount())->toBe(0)
            ->and(skipReasons($result)[3] ?? null)->toBe(TrashCleanResult::SKIP_MISSING_TRASH_TIME);
    });

    it('skips a member whose trash meta time is zero', function () {
        // A "0" trash timestamp would be 1970, which can't be a legitimate
        // trash time — almost certainly bad data. The cleaner refuses to act
        // on it for the same safety reason as the missing-meta case.
        $GLOBALS['scrutiny_test_post_meta'][4]['_wp_trash_meta_time'] = '0';

        $result = trashCleaner([new MemberStub(id: 4)])->clean(retentionDays: 7);

        expect($result->getDeletedCount())->toBe(0)
            ->and(skipReasons($result)[4] ?? null)->toBe(TrashCleanResult::SKIP_MISSING_TRASH_TIME);
    });

    it('records a skip when wp_delete_post fails', function () {
        // Simulate WP returning false from wp_delete_post — the cleaner must
        // record the failure rather than treat it as success.
        $GLOBALS['scrutiny_test_delete_returns_false'] = true;

        seedTrashTime(5, '2025-07-01 12:00:00');

        // Use a real (non-overridden) cleaner so the wp_delete_post path is
        // actually exercised. That means it will call the bootstrap stub,
        // which honours scrutiny_test_delete_returns_false.
        $cleaner = new MemberTrashCleaner(
            new InMemoryMemberRepository([new MemberStub(id: 5)], rejectWrites: true),
            new DateTimeImmutable(TRASH_CLEAN_NOW)
        );
        $result = $cleaner->clean(retentionDays: 7);

        expect($result->getDeletedCount())->toBe(0)
            ->and(skipReasons($result)[5] ?? null)->toBe(TrashCleanResult::SKIP_DELETE_FAILED);
    });
});

describe('logging', function () {
    it('logs a summary entry at info level', function () {
        // Mirrors MemberPruner's logging contract: a single closing summary
        // entry with counters.
        seedTrashTime(9, '2025-07-01 12:00:00');

        trashCleaner([new MemberStub(id: 9)])->clean(retentionDays: 7);

        $summary = array_values(array_filter(
            $GLOBALS['scrutiny_test_log_entries'],
            static fn (array $entry) => $entry['message'] === 'Trash clean complete'
        ));

        expect($summary)->toHaveCount(1)
            ->and($summary[0])
            ->level->toBe('info')
            ->channel->toBe('scrutiny')
            ->and($summary[0]['context'])
            ->deleted->toBe(1)
            ->retention_days->toBe(7);
    });

    it('logs each deletion at info and each failure at warning', function () {
        // Two trashed members; the second has missing meta so surfaces as a
        // SKIP_MISSING_TRASH_TIME warning. The first produces an info-level
        // "permanently deleted" entry.
        seedTrashTime(10, '2025-07-01 12:00:00');
        // No meta for 11.

        trashCleaner([new MemberStub(id: 10), new MemberStub(id: 11)])->clean(retentionDays: 7);

        $messagesByLevel = [];
        foreach ($GLOBALS['scrutiny_test_log_entries'] as $entry) {
            $messagesByLevel[$entry['level']][] = $entry['message'];
        }

        expect($messagesByLevel['info'] ?? [])->toContain('Trashed member permanently deleted')
            ->and($messagesByLevel['warning'] ?? [])->toContain('Trash cleaner could not delete a member');
    });
});
