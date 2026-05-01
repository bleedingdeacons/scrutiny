<?php

declare(strict_types=1);

namespace Scrutiny\Cleanup;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use DateTimeImmutable;
use Unity\Members\Interfaces\MemberRepository;
use function function_exists;
use function get_post_meta;
use function wp_delete_post;

/**
 * Member Trash Cleaner
 *
 * Permanently deletes member records that have been in the WordPress
 * trash longer than a configured retention period. Designed to run
 * after each scheduled MemberPruner pass so the cron pipeline both
 * trashes stale members AND eventually deletes them.
 *
 * Scope:
 *
 *   The cleaner deletes ANY trashed member whose trash timestamp is
 *   older than the retention threshold — not just members the
 *   pruner itself trashed. That covers manually-trashed members
 *   too, which is what an admin operating a single "trash retention"
 *   knob would expect. Members trashed for distinct reasons all
 *   exit the trash by the same rule.
 *
 * Trash timestamp:
 *
 *   WordPress's wp_trash_post() records the trash time in the
 *   _wp_trash_meta_time post meta as a Unix timestamp. The cleaner
 *   reads that value directly rather than going through a Member
 *   accessor — the trash-meta concern is WordPress-specific, not a
 *   property of the domain Member object.
 *
 *   When _wp_trash_meta_time is missing (a malformed trash, or a
 *   member trashed by code that bypassed wp_trash_post), the
 *   cleaner refuses to act and surfaces the situation in the
 *   result. We don't guess at a fallback because permanent
 *   deletion is irreversible.
 *
 * Permanent deletion:
 *
 *   wp_delete_post($id, true) is used with force_delete=true so
 *   the post and its meta are removed entirely (rather than
 *   re-trashed). The Unity MemberChangeTracker observes
 *   wp_delete_post and fires unity/member_deleted, which
 *   Scrutiny's AuditTracker logs — same audit chain as the
 *   pruner's trash actions.
 */
class MemberTrashCleaner
{
    use \Scrutiny\Logger\HasLogger;

    protected static function logChannel(): string
    {
        return 'scrutiny';
    }

    private MemberRepository $members;
    private DateTimeImmutable $now;

    /**
     * @param MemberRepository       $members  Unity member repository — used
     *                                          to enumerate trashed members
     *                                          via findAll(['post_status' =>
     *                                          'trash']).
     * @param DateTimeImmutable|null $now      Override "now" for testing.
     */
    public function __construct(
        MemberRepository $members,
        ?DateTimeImmutable $now = null
    ) {
        $this->members = $members;
        $this->now     = $now ?? new DateTimeImmutable('now');
    }

    /**
     * Permanently delete trashed members older than the retention period.
     *
     * Negative input is clamped to zero — same defence-in-depth
     * convention as MemberPruner. A retention of zero means
     * "delete everything currently in trash"; that's a legitimate
     * (if aggressive) configuration the admin might choose, so
     * we don't reject it.
     *
     * @param int $retentionDays Days a trashed member is retained
     *                           before permanent deletion.
     * @return TrashCleanResult
     */
    public function clean(int $retentionDays): TrashCleanResult
    {
        $retentionDays = max(0, $retentionDays);
        $result        = new TrashCleanResult();

        $cutoff        = $this->now->modify('-' . $retentionDays . ' days');
        $cutoffStamp   = $cutoff->getTimestamp();

        $trashedMembers = $this->members->findAll(['post_status' => 'trash']);

        foreach ($trashedMembers as $member) {
            $result->incrementConsidered();
            $memberId = $member->getId();

            $trashTime = $this->readTrashTime($memberId);

            if ($trashTime === null) {
                // Without a parseable trash timestamp we can't tell
                // whether retention has expired. Permanent deletion
                // is irreversible, so we refuse to act and record
                // the row so an admin can investigate.
                $result->recordSkipped(
                    $memberId,
                    TrashCleanResult::SKIP_MISSING_TRASH_TIME,
                    'no _wp_trash_meta_time meta'
                );
                continue;
            }

            if ($trashTime > $cutoffStamp) {
                $result->recordSkipped(
                    $memberId,
                    TrashCleanResult::SKIP_RETENTION_NOT_REACHED,
                    'trashed_at=' . gmdate('Y-m-d H:i:s', $trashTime)
                );
                continue;
            }

            if ($this->deleteMember($memberId)) {
                $result->recordDeleted(
                    $memberId,
                    TrashCleanResult::REASON_RETENTION_EXPIRED,
                    'trashed_at=' . gmdate('Y-m-d H:i:s', $trashTime)
                );
            } else {
                $result->recordSkipped(
                    $memberId,
                    TrashCleanResult::SKIP_DELETE_FAILED,
                    'wp_delete_post returned falsy'
                );
            }
        }

        $this->logResult($result, $retentionDays);

        return $result;
    }

    /**
     * Read _wp_trash_meta_time for the given post ID, returning the
     * Unix timestamp it records or null if absent / unparseable.
     *
     * Single-meta read (not get_post_meta($id, $key, true) returning
     * an array). The value WP writes is a string-encoded integer.
     */
    private function readTrashTime(int $memberId): ?int
    {
        if (!function_exists('get_post_meta')) {
            return null;
        }

        $raw = get_post_meta($memberId, '_wp_trash_meta_time', true);

        if ($raw === '' || $raw === false || $raw === null) {
            return null;
        }

        // The meta is written as a string by WP. cast through int
        // and reject anything that came out as zero (which would
        // mean "1970" — almost certainly bad data, not a legitimate
        // trash time).
        $stamp = (int) $raw;
        if ($stamp <= 0) {
            return null;
        }

        return $stamp;
    }

    /**
     * Wrap wp_delete_post so the call site can be mocked / overridden
     * in tests. Returns true on success.
     */
    protected function deleteMember(int $memberId): bool
    {
        if (!function_exists('wp_delete_post')) {
            return false;
        }

        // force_delete=true: skip the trash and remove the post
        // entirely. We're already operating on a trashed post; a
        // re-trash here would be a no-op WP-side and would leave
        // the member sitting in the queue forever.
        $result = wp_delete_post($memberId, true);

        return $result !== false && $result !== null;
    }

    /**
     * Write the result of a clean run to the wp_log channel.
     *
     * Mirrors MemberPruner::logResult tiering: per-deletion INFO,
     * per-failure WARNING, summary INFO at the end. Routine
     * "retention not reached" skips are not logged per row — they
     * can outnumber the deletions at any healthy steady state.
     */
    private function logResult(TrashCleanResult $result, int $retentionDays): void
    {
        foreach ($result->getDeleted() as $entry) {
            self::logInfo('Trashed member permanently deleted', [
                'member_id' => $entry['member_id'],
                'reason'    => $entry['reason'],
                'detail'    => $entry['detail'],
            ]);
        }

        $skipCategoryCounts = [];
        foreach ($result->getSkipped() as $entry) {
            $reason = $entry['reason'];

            if (
                $reason === TrashCleanResult::SKIP_DELETE_FAILED
                || $reason === TrashCleanResult::SKIP_MISSING_TRASH_TIME
            ) {
                self::logWarning('Trash cleaner could not delete a member', [
                    'member_id' => $entry['member_id'],
                    'reason'    => $reason,
                    'detail'    => $entry['detail'],
                ]);
                continue;
            }

            $skipCategoryCounts[$reason] = ($skipCategoryCounts[$reason] ?? 0) + 1;
        }

        self::logInfo('Trash clean complete', [
            'deleted'         => $result->getDeletedCount(),
            'skipped'         => $result->getSkippedCount(),
            'considered'      => $result->getConsidered(),
            'retention_days'  => $retentionDays,
            'skip_categories' => $skipCategoryCounts,
        ]);
    }
}
