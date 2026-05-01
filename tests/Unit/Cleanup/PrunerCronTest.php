<?php

declare(strict_types=1);

namespace Scrutiny\Tests\Unit\Cleanup;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Scrutiny\Cleanup\MemberPruner;
use Scrutiny\Cleanup\MemberTrashCleaner;
use Scrutiny\Cleanup\PrunerCron;
use Scrutiny\Cleanup\PrunerSettings;
use Unity\Members\Interfaces\MemberRepository;

/**
 * Tests for PrunerCron.
 *
 * Three concerns being exercised:
 *
 *   - Scheduling (schedule / unschedule / idempotence): asserts the
 *     in-memory cron queue stub records the right event with the
 *     right recurrence, and that ensureScheduled() doesn't duplicate.
 *
 *   - Hook registration: register() must wire the cron callback to
 *     the HOOK constant and the defensive re-scheduler to 'init'.
 *     The bootstrap's add_action() stub records every call so the
 *     test inspects the recorded list.
 *
 *   - Run handler: runScheduledPrune() must read thresholds from
 *     PrunerSettings and pass them to MemberPruner::prune(). A
 *     spy pruner records the call args.
 *
 * The bootstrap stubs reset between tests via setUp().
 */
class PrunerCronTest extends TestCase
{
    protected function setUp(): void
    {
        // Reset the in-memory cron queue and the recorded action
        // list so each test sees a clean slate. Without this the
        // previous test's wp_schedule_event call would still be
        // visible and the idempotence test would falsely "pass".
        $GLOBALS['scrutiny_test_cron_queue'] = [];
        $GLOBALS['scrutiny_test_actions']    = [];
        $GLOBALS['scrutiny_test_options']    = [];
    }

    // ──────────────────────────────────────────────
    //  Scheduling
    // ──────────────────────────────────────────────

    /** @test */
    public function schedule_adds_a_weekly_event_when_none_is_queued(): void
    {
        PrunerCron::schedule();

        $this->assertArrayHasKey(
            PrunerCron::HOOK,
            $GLOBALS['scrutiny_test_cron_queue']
        );

        $entry = $GLOBALS['scrutiny_test_cron_queue'][PrunerCron::HOOK];
        $this->assertSame('weekly', $entry['recurrence']);
        $this->assertSame(PrunerCron::RECURRENCE, $entry['recurrence']);

        // First run is scheduled in the future (one hour from now,
        // by design) so a fresh activation doesn't fire a no-op
        // event during the activation cleanup window.
        $this->assertGreaterThan(time(), $entry['timestamp']);
    }

    /** @test */
    public function schedule_is_idempotent(): void
    {
        // Calling schedule() twice in a row must not produce
        // duplicate cron entries. wp_schedule_event itself does
        // produce duplicates (the WP behaviour), so the idempotence
        // guard lives in PrunerCron::schedule() and this test pins
        // it down.
        PrunerCron::schedule();
        $firstTimestamp = $GLOBALS['scrutiny_test_cron_queue'][PrunerCron::HOOK]['timestamp'];

        PrunerCron::schedule();
        $secondTimestamp = $GLOBALS['scrutiny_test_cron_queue'][PrunerCron::HOOK]['timestamp'];

        // Same timestamp → schedule() returned early on the second
        // call without re-queueing.
        $this->assertSame($firstTimestamp, $secondTimestamp);
    }

    /** @test */
    public function unschedule_clears_the_event(): void
    {
        // Schedule, then unschedule. The cron queue must end up
        // empty so a deactivated plugin doesn't leave an orphan
        // event.
        PrunerCron::schedule();
        $this->assertArrayHasKey(PrunerCron::HOOK, $GLOBALS['scrutiny_test_cron_queue']);

        PrunerCron::unschedule();

        $this->assertArrayNotHasKey(PrunerCron::HOOK, $GLOBALS['scrutiny_test_cron_queue']);
        $this->assertFalse(wp_next_scheduled(PrunerCron::HOOK));
    }

    /** @test */
    public function ensure_scheduled_re_adds_the_event_when_missing(): void
    {
        // Simulate a state where activation didn't run cleanly: the
        // event isn't in the queue. ensureScheduled() (the runtime
        // heartbeat) must put it back so the schedule self-heals.
        $this->assertFalse(wp_next_scheduled(PrunerCron::HOOK));

        PrunerCron::ensureScheduled();

        $this->assertArrayHasKey(PrunerCron::HOOK, $GLOBALS['scrutiny_test_cron_queue']);
    }

    // ──────────────────────────────────────────────
    //  Hook registration
    // ──────────────────────────────────────────────

    /** @test */
    public function register_wires_the_cron_action_handler(): void
    {
        $cron = $this->makeCron();
        $cron->register();

        $hooks = array_column($GLOBALS['scrutiny_test_actions'], 'hook');
        $this->assertContains(PrunerCron::HOOK, $hooks);
    }

    /** @test */
    public function register_wires_the_defensive_init_re_scheduler(): void
    {
        // ensureScheduled is wired on 'init' so a missing event in
        // the cron queue self-heals on the next request, without
        // forcing the admin to deactivate/reactivate.
        $cron = $this->makeCron();
        $cron->register();

        $initActions = array_filter(
            $GLOBALS['scrutiny_test_actions'],
            static fn(array $entry) => $entry['hook'] === 'init'
        );

        $this->assertNotEmpty($initActions, 'expected an init action for ensureScheduled');
    }

    // ──────────────────────────────────────────────
    //  Run handler
    // ──────────────────────────────────────────────

    /** @test */
    public function runScheduledPrune_invokes_the_pruner_with_settings_values(): void
    {
        // Settings configured to non-default values so the test can
        // tell "pruner was called with settings values" apart from
        // "pruner was called with hard-coded defaults".
        $settings = new PrunerSettings();
        $settings->setRotationGraceMonths(7);
        $settings->setInactivityMonths(15);
        $settings->setEnabled(true);

        $pruner       = new SpyPruner();
        $trashCleaner = new SpyTrashCleaner();

        $cron = new PrunerCron($pruner, $settings, $trashCleaner);
        $cron->runScheduledPrune();

        $this->assertSame(1, $pruner->callCount);
        $this->assertSame(7, $pruner->lastRotationGrace);
        $this->assertSame(15, $pruner->lastInactivity);
    }

    /** @test */
    public function runScheduledPrune_invokes_the_trash_cleaner_with_settings_value(): void
    {
        // After a successful prune, the cleaner runs with the
        // configured retention period. This test pins down that
        // the trash retention setting reaches the cleaner unchanged.
        $settings = new PrunerSettings();
        $settings->setEnabled(true);
        $settings->setTrashRetentionDays(14);

        $pruner       = new SpyPruner();
        $trashCleaner = new SpyTrashCleaner();

        $cron = new PrunerCron($pruner, $settings, $trashCleaner);
        $cron->runScheduledPrune();

        $this->assertSame(1, $trashCleaner->callCount);
        $this->assertSame(14, $trashCleaner->lastRetention);
    }

    /** @test */
    public function runScheduledPrune_invokes_the_pruner_even_when_disabled(): void
    {
        // The pruner's own short-circuit lives inside prune(), so
        // the cron handler always invokes it. The pruner records
        // the disabled-skip log entry; no decision logic is
        // duplicated at the cron level for this step.
        $settings = new PrunerSettings();
        $settings->setEnabled(false);

        $pruner       = new SpyPruner();
        $trashCleaner = new SpyTrashCleaner();

        $cron = new PrunerCron($pruner, $settings, $trashCleaner);
        $cron->runScheduledPrune();

        $this->assertSame(1, $pruner->callCount);
    }

    /** @test */
    public function runScheduledPrune_skips_the_trash_cleaner_when_disabled(): void
    {
        // Permanent deletion is the most destructive action in the
        // pipeline. The disabled toggle means "Scrutiny will not
        // make destructive changes to members", so the cleaner must
        // not run when disabled. The cron handler enforces this
        // explicitly because the cleaner has no internal toggle of
        // its own.
        $settings = new PrunerSettings();
        $settings->setEnabled(false);
        $settings->setTrashRetentionDays(7);

        $pruner       = new SpyPruner();
        $trashCleaner = new SpyTrashCleaner();

        $cron = new PrunerCron($pruner, $settings, $trashCleaner);
        $cron->runScheduledPrune();

        $this->assertSame(0, $trashCleaner->callCount, 'cleaner must not run when disabled');
    }

    /** @test */
    public function runScheduledPrune_uses_default_thresholds_for_a_fresh_install(): void
    {
        // No options set → PrunerSettings returns the documented
        // defaults. Those must reach the pruner unchanged so an
        // admin who never visited the settings page still gets the
        // documented behaviour on the first scheduled run.
        //
        // The cleaner is gated on the enabled flag (disabled by
        // default), so on a fresh install only the pruner runs.
        $settings     = new PrunerSettings();
        $pruner       = new SpyPruner();
        $trashCleaner = new SpyTrashCleaner();

        $cron = new PrunerCron($pruner, $settings, $trashCleaner);
        $cron->runScheduledPrune();

        $this->assertSame(PrunerSettings::DEFAULT_ROTATION_GRACE_MONTHS, $pruner->lastRotationGrace);
        $this->assertSame(PrunerSettings::DEFAULT_INACTIVITY_MONTHS, $pruner->lastInactivity);
    }

    // ──────────────────────────────────────────────
    //  Helpers
    // ──────────────────────────────────────────────

    /**
     * Build a real PrunerCron with a real (no-op) MemberPruner and
     * MemberTrashCleaner underneath. Used by tests that only care
     * about scheduling or hook registration, where neither service
     * actually runs.
     */
    private function makeCron(): PrunerCron
    {
        $repository   = new InMemoryMemberRepository([]);
        $settings     = new PrunerSettings();
        $pruner       = new MemberPruner($repository, new DateTimeImmutable('2025-07-15 12:00:00'), $settings);
        $trashCleaner = new MemberTrashCleaner($repository, new DateTimeImmutable('2025-07-15 12:00:00'));

        return new PrunerCron($pruner, $settings, $trashCleaner);
    }
}

/**
 * Spy that captures the args passed to prune() without doing any
 * pruning. Subclassing MemberPruner is appropriate here because the
 * cron handler depends on the concrete class, not an interface — so
 * a spy that satisfies the type constraint must extend it.
 */
final class SpyPruner extends MemberPruner
{
    public int $callCount         = 0;
    public ?int $lastRotationGrace = null;
    public ?int $lastInactivity    = null;

    public function __construct()
    {
        // Bypass parent::__construct's repository requirement: pass
        // an empty in-memory repo. The spy never calls findAll()
        // because prune() is overridden.
        parent::__construct(
            new InMemoryMemberRepository([]),
            new DateTimeImmutable('2025-07-15 12:00:00')
        );
    }

    public function prune(int $rotationGraceMonths, int $inactivityMonths): \Scrutiny\Cleanup\PruneResult
    {
        $this->callCount++;
        $this->lastRotationGrace = $rotationGraceMonths;
        $this->lastInactivity    = $inactivityMonths;
        return new \Scrutiny\Cleanup\PruneResult();
    }
}

/**
 * Spy that captures whether clean() was called and with what
 * retention argument. Same subclassing rationale as SpyPruner: the
 * cron handler depends on the concrete MemberTrashCleaner class.
 */
final class SpyTrashCleaner extends MemberTrashCleaner
{
    public int $callCount        = 0;
    public ?int $lastRetention   = null;

    public function __construct()
    {
        parent::__construct(
            new InMemoryMemberRepository([]),
            new DateTimeImmutable('2025-07-15 12:00:00')
        );
    }

    public function clean(int $retentionDays): \Scrutiny\Cleanup\TrashCleanResult
    {
        $this->callCount++;
        $this->lastRetention = $retentionDays;
        return new \Scrutiny\Cleanup\TrashCleanResult();
    }
}
