<?php

declare(strict_types=1);

namespace Scrutiny\Tests\Unit\Cleanup;

use DateTimeImmutable;
use Scrutiny\Cleanup\MemberPruner;
use Scrutiny\Cleanup\MemberTrashCleaner;
use Scrutiny\Cleanup\PruneResult;
use Scrutiny\Cleanup\PrunerCron;
use Scrutiny\Cleanup\PrunerSettings;
use Scrutiny\Cleanup\TrashCleanResult;
use Unity\Testing\Doubles\InMemoryMemberRepository;

/*
 * Tests for PrunerCron.
 *
 * Three concerns being exercised:
 *
 *   - Scheduling (schedule / unschedule / idempotence): asserts the in-memory
 *     cron queue stub records the right event with the right recurrence, and
 *     that ensureScheduled() doesn't duplicate.
 *
 *   - Hook registration: register() must wire the cron callback to the HOOK
 *     constant and the defensive re-scheduler to 'init'. The bootstrap's
 *     add_action() stub records every call so the test inspects the recorded
 *     list.
 *
 *   - Run handler: runScheduledPrune() must read thresholds from
 *     PrunerSettings and pass them to MemberPruner::prune(). A spy pruner
 *     records the call args.
 *
 * The bootstrap stubs reset between tests via beforeEach().
 */

/**
 * Spy that captures the args passed to prune() without doing any pruning.
 * Subclassing MemberPruner is appropriate here because the cron handler
 * depends on the concrete class, not an interface — so a spy that satisfies
 * the type constraint must extend it.
 */
final class SpyPruner extends MemberPruner
{
    public int $callCount          = 0;
    public ?int $lastRotationGrace = null;
    public ?int $lastInactivity    = null;

    public function __construct()
    {
        // Bypass parent::__construct's repository requirement: pass an empty
        // in-memory repo. The spy never calls findAll() because prune() is
        // overridden.
        parent::__construct(
            new InMemoryMemberRepository([], rejectWrites: true),
            new DateTimeImmutable('2025-07-15 12:00:00')
        );
    }

    public function prune(int $rotationGraceMonths, int $inactivityMonths): PruneResult
    {
        $this->callCount++;
        $this->lastRotationGrace = $rotationGraceMonths;
        $this->lastInactivity    = $inactivityMonths;
        return new PruneResult();
    }
}

/**
 * Spy that captures whether clean() was called and with what retention
 * argument. Same subclassing rationale as SpyPruner: the cron handler depends
 * on the concrete MemberTrashCleaner class.
 */
final class SpyTrashCleaner extends MemberTrashCleaner
{
    public int $callCount      = 0;
    public ?int $lastRetention = null;

    public function __construct()
    {
        parent::__construct(
            new InMemoryMemberRepository([], rejectWrites: true),
            new DateTimeImmutable('2025-07-15 12:00:00')
        );
    }

    public function clean(int $retentionDays): TrashCleanResult
    {
        $this->callCount++;
        $this->lastRetention = $retentionDays;
        return new TrashCleanResult();
    }
}

/**
 * Build a real PrunerCron with a real (no-op) MemberPruner and
 * MemberTrashCleaner underneath. Used by tests that only care about
 * scheduling or hook registration, where neither service actually runs.
 */
function realCron(): PrunerCron
{
    $repository   = new InMemoryMemberRepository([], rejectWrites: true);
    $settings     = new PrunerSettings();
    $pruner       = new MemberPruner($repository, new DateTimeImmutable('2025-07-15 12:00:00'), $settings);
    $trashCleaner = new MemberTrashCleaner($repository, new DateTimeImmutable('2025-07-15 12:00:00'));

    return new PrunerCron($pruner, $settings, $trashCleaner);
}

/**
 * Run the scheduled prune over spies and hand them back for inspection.
 *
 * @return array{SpyPruner, SpyTrashCleaner}
 */
function runScheduledPruneWith(PrunerSettings $settings): array
{
    $pruner       = new SpyPruner();
    $trashCleaner = new SpyTrashCleaner();

    (new PrunerCron($pruner, $settings, $trashCleaner))->runScheduledPrune();

    return [$pruner, $trashCleaner];
}

beforeEach(function () {
    // Reset the in-memory cron queue and the recorded action list so each
    // test sees a clean slate. Without this the previous test's
    // wp_schedule_event call would still be visible and the idempotence test
    // would falsely "pass".
    $GLOBALS['scrutiny_test_cron_queue'] = [];
    $GLOBALS['scrutiny_test_actions']    = [];
    $GLOBALS['scrutiny_test_options']    = [];
});

// ──────────────────────────────────────────────
//  Scheduling
// ──────────────────────────────────────────────
describe('scheduling', function () {
    it('adds a weekly event when none is queued', function () {
        PrunerCron::schedule();

        expect($GLOBALS['scrutiny_test_cron_queue'])->toHaveKey(PrunerCron::HOOK);

        $entry = $GLOBALS['scrutiny_test_cron_queue'][PrunerCron::HOOK];

        expect($entry['recurrence'])->toBe('weekly')->toBe(PrunerCron::RECURRENCE)
            // First run is scheduled in the future (one hour from now, by
            // design) so a fresh activation doesn't fire a no-op event during
            // the activation cleanup window.
            ->and($entry['timestamp'])->toBeGreaterThan(time());
    });

    it('is idempotent', function () {
        // Calling schedule() twice in a row must not produce duplicate cron
        // entries. wp_schedule_event itself does produce duplicates (the WP
        // behaviour), so the idempotence guard lives in PrunerCron::schedule()
        // and this test pins it down.
        PrunerCron::schedule();
        $firstTimestamp = $GLOBALS['scrutiny_test_cron_queue'][PrunerCron::HOOK]['timestamp'];

        PrunerCron::schedule();
        $secondTimestamp = $GLOBALS['scrutiny_test_cron_queue'][PrunerCron::HOOK]['timestamp'];

        // Same timestamp → schedule() returned early on the second call
        // without re-queueing.
        expect($secondTimestamp)->toBe($firstTimestamp);
    });

    it('clears the event on unschedule', function () {
        // Schedule, then unschedule. The cron queue must end up empty so a
        // deactivated plugin doesn't leave an orphan event.
        PrunerCron::schedule();
        expect($GLOBALS['scrutiny_test_cron_queue'])->toHaveKey(PrunerCron::HOOK);

        PrunerCron::unschedule();

        expect($GLOBALS['scrutiny_test_cron_queue'])->not->toHaveKey(PrunerCron::HOOK)
            ->and(wp_next_scheduled(PrunerCron::HOOK))->toBeFalse();
    });

    it('re-adds the event when it is missing', function () {
        // Simulate a state where activation didn't run cleanly: the event
        // isn't in the queue. ensureScheduled() (the runtime heartbeat) must
        // put it back so the schedule self-heals.
        expect(wp_next_scheduled(PrunerCron::HOOK))->toBeFalse();

        PrunerCron::ensureScheduled();

        expect($GLOBALS['scrutiny_test_cron_queue'])->toHaveKey(PrunerCron::HOOK);
    });
});

// ──────────────────────────────────────────────
//  Hook registration
// ──────────────────────────────────────────────
describe('register', function () {
    it('wires the cron action handler', function () {
        realCron()->register();

        expect(array_column($GLOBALS['scrutiny_test_actions'], 'hook'))->toContain(PrunerCron::HOOK);
    });

    it('wires the defensive init re-scheduler', function () {
        // ensureScheduled is wired on 'init' so a missing event in the cron
        // queue self-heals on the next request, without forcing the admin to
        // deactivate/reactivate.
        realCron()->register();

        $initActions = array_filter(
            $GLOBALS['scrutiny_test_actions'],
            static fn (array $entry) => $entry['hook'] === 'init'
        );

        expect($initActions)->not->toBeEmpty('expected an init action for ensureScheduled');
    });
});

// ──────────────────────────────────────────────
//  Run handler
// ──────────────────────────────────────────────
describe('runScheduledPrune', function () {
    it('invokes the pruner with the settings values', function () {
        // Settings configured to non-default values so the test can tell
        // "pruner was called with settings values" apart from "pruner was
        // called with hard-coded defaults".
        $settings = new PrunerSettings();
        $settings->setRotationGraceMonths(7);
        $settings->setInactivityMonths(15);
        $settings->setEnabled(true);

        [$pruner] = runScheduledPruneWith($settings);

        expect($pruner)
            ->callCount->toBe(1)
            ->lastRotationGrace->toBe(7)
            ->lastInactivity->toBe(15);
    });

    it('invokes the trash cleaner with the settings value', function () {
        // After a successful prune, the cleaner runs with the configured
        // retention period. This test pins down that the trash retention
        // setting reaches the cleaner unchanged.
        $settings = new PrunerSettings();
        $settings->setEnabled(true);
        $settings->setTrashRetentionDays(14);

        [, $trashCleaner] = runScheduledPruneWith($settings);

        expect($trashCleaner)
            ->callCount->toBe(1)
            ->lastRetention->toBe(14);
    });

    it('invokes the pruner even when disabled', function () {
        // The pruner's own short-circuit lives inside prune(), so the cron
        // handler always invokes it. The pruner records the disabled-skip log
        // entry; no decision logic is duplicated at the cron level for this
        // step.
        $settings = new PrunerSettings();
        $settings->setEnabled(false);

        [$pruner] = runScheduledPruneWith($settings);

        expect($pruner->callCount)->toBe(1);
    });

    it('skips the trash cleaner when disabled', function () {
        // Permanent deletion is the most destructive action in the pipeline.
        // The disabled toggle means "Scrutiny will not make destructive
        // changes to members", so the cleaner must not run when disabled. The
        // cron handler enforces this explicitly because the cleaner has no
        // internal toggle of its own.
        $settings = new PrunerSettings();
        $settings->setEnabled(false);
        $settings->setTrashRetentionDays(7);

        [, $trashCleaner] = runScheduledPruneWith($settings);

        expect($trashCleaner->callCount)->toBe(0, 'cleaner must not run when disabled');
    });

    it('uses the default thresholds on a fresh install', function () {
        // No options set → PrunerSettings returns the documented defaults.
        // Those must reach the pruner unchanged so an admin who never visited
        // the settings page still gets the documented behaviour on the first
        // scheduled run.
        //
        // The cleaner is gated on the enabled flag (disabled by default), so
        // on a fresh install only the pruner runs.
        [$pruner] = runScheduledPruneWith(new PrunerSettings());

        expect($pruner)
            ->lastRotationGrace->toBe(PrunerSettings::DEFAULT_ROTATION_GRACE_MONTHS)
            ->lastInactivity->toBe(PrunerSettings::DEFAULT_INACTIVITY_MONTHS);
    });
});
