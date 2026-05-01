<?php

declare(strict_types=1);

namespace Scrutiny\Cleanup;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use function add_action;
use function wp_clear_scheduled_hook;
use function wp_next_scheduled;
use function wp_schedule_event;

/**
 * Pruner Cron
 *
 * Wires the MemberPruner into WP-Cron so it runs unattended once a week.
 * Each scheduled run performs two steps in order:
 *
 *   1. Prune — MemberPruner::prune() trashes members that match the
 *      configured rotation and inactivity rules.
 *   2. Trash cleanup — MemberTrashCleaner::clean() permanently deletes
 *      members that have been in the trash longer than the configured
 *      retention period (default 7 days, matching the cron interval).
 *
 * Both steps are gated by PrunerSettings::isEnabled(): when the pruner
 * is disabled, neither runs. That keeps the "disabled" semantics
 * consistent — Scrutiny will not make destructive changes to members,
 * including permanent deletion.
 *
 * Schedule lifecycle:
 *
 *   - Plugin activation calls PrunerCron::schedule(). That registers
 *     a recurring event against the 'weekly' interval (built into
 *     WordPress since 5.4 — exactly seven days, not "approximately
 *     weekly").
 *
 *   - Plugin deactivation calls PrunerCron::unschedule(). The event
 *     is cleared so a deactivated plugin leaves no lingering
 *     entries in the cron queue.
 *
 *   - register() defensively re-schedules on every 'init' if the
 *     event is missing from the cron queue. Activation runs once and
 *     can fail (failed DB write, unrelated activation error, manual
 *     wp-cron flush). The defensive check keeps the schedule
 *     resilient without making activation more complex than it is.
 *
 * Enabled flag:
 *
 *   The cron event is scheduled regardless of whether the pruner is
 *   enabled in PrunerSettings. The handler invokes MemberPruner::prune(),
 *   which itself short-circuits when the toggle is off and emits a
 *   single "skipped: disabled" log entry. This means an admin who
 *   toggles the pruner on and off doesn't have to remember to also
 *   clear and re-create cron events; the schedule is durable, only
 *   the action it triggers is gated.
 *
 * The cron handler is intentionally thin. It reads thresholds from
 * PrunerSettings and calls MemberPruner::prune(). All decision logic,
 * trashing, and result-logging lives in MemberPruner — the cron
 * handler is a transport, not a coordinator.
 */
class PrunerCron
{
    /**
     * The action hook fired by WP-Cron when the scheduled event is due.
     *
     * Public so deactivation code paths in scrutiny.php can clear it
     * by name without instantiating the class.
     */
    public const HOOK = 'scrutiny_prune_members';

    /**
     * The recurrence interval.
     *
     * 'weekly' is shipped by WordPress core (5.4+) and means exactly
     * 7 days (604800 seconds). We deliberately use the built-in
     * interval rather than registering a custom 'every_7_days' so
     * there's nothing in cron_schedules for Scrutiny to maintain.
     */
    public const RECURRENCE = 'weekly';

    private MemberPruner $pruner;
    private PrunerSettings $settings;
    private MemberTrashCleaner $trashCleaner;

    public function __construct(
        MemberPruner $pruner,
        PrunerSettings $settings,
        MemberTrashCleaner $trashCleaner
    ) {
        $this->pruner       = $pruner;
        $this->settings     = $settings;
        $this->trashCleaner = $trashCleaner;
    }

    /**
     * Wire the cron handler and the defensive re-scheduling check.
     *
     * Called from Plugin::run() so the action is registered on every
     * page load — including front-end loads, since WP-Cron can fire
     * scheduled events on whichever wake-up cycle hits first.
     */
    public function register(): void
    {
        add_action(self::HOOK, [$this, 'runScheduledPrune']);

        // Defensive: if the event isn't in the cron queue (e.g.
        // activation didn't run, or the queue was cleared), put it
        // back. Hooked on 'init' so we run after WP-Cron itself has
        // initialised, and at default priority because there's no
        // dependency on other plugins' init handlers.
        add_action('init', [self::class, 'ensureScheduled']);
    }

    /**
     * Schedule the recurring event.
     *
     * Called from Plugin::activate(). Idempotent: if the event is
     * already scheduled, this is a no-op, so an admin who reactivates
     * the plugin (or who does so via WP-CLI) doesn't get duplicate
     * events.
     *
     * The first run is scheduled for one hour after activation rather
     * than immediately, so a fresh install that ships with default
     * (disabled) settings doesn't fire a no-op cron event during
     * activation cleanup.
     */
    public static function schedule(): void
    {
        if (wp_next_scheduled(self::HOOK) !== false) {
            return;
        }

        wp_schedule_event(time() + HOUR_IN_SECONDS, self::RECURRENCE, self::HOOK);
    }

    /**
     * Same defensive scheduler, exposed as an action handler so it
     * can run on 'init' without leaking instance state. Kept separate
     * from schedule() for naming clarity: schedule() is the
     * activation entry point, ensureScheduled() is the runtime
     * heartbeat.
     */
    public static function ensureScheduled(): void
    {
        self::schedule();
    }

    /**
     * Clear the scheduled event.
     *
     * Called from Plugin::deactivate(). Uses wp_clear_scheduled_hook
     * (clears all instances of this hook) rather than
     * wp_unschedule_event (which needs a specific timestamp), so a
     * cron queue with stale duplicates from a botched earlier
     * activation is also cleaned up.
     */
    public static function unschedule(): void
    {
        wp_clear_scheduled_hook(self::HOOK);
    }

    /**
     * Cron action handler.
     *
     * Runs the full pruner pipeline: the prune itself, then the
     * trash cleanup of any members whose retention period has
     * expired. The pipeline is gated by PrunerSettings::isEnabled():
     * when the pruner is disabled, neither step runs (the pruner
     * short-circuits internally and we skip the cleaner explicitly).
     *
     * Gating the cleaner at this level — rather than letting it
     * always run — keeps "disabled" meaning "Scrutiny will not make
     * destructive changes to members". Permanent deletion is the
     * most destructive change there is, so the same toggle that
     * stops trashing must also stop permanent deletion.
     *
     * Public because it's wired to the cron action; no caller
     * outside WP-Cron should need to invoke it directly.
     */
    public function runScheduledPrune(): void
    {
        $this->pruner->prune(
            $this->settings->getRotationGraceMonths(),
            $this->settings->getInactivityMonths()
        );

        // The pruner short-circuits internally when disabled, so
        // its own log entry already records why nothing happened.
        // Mirror that here for the cleaner: when disabled, skip
        // entirely without invoking the cleaner.
        if (!$this->settings->isEnabled()) {
            return;
        }

        $this->trashCleaner->clean(
            $this->settings->getTrashRetentionDays()
        );
    }
}
