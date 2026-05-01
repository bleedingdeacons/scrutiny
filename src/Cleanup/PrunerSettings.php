<?php

declare(strict_types=1);

namespace Scrutiny\Cleanup;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use function get_option;
use function update_option;

/**
 * Settings for MemberPruner.
 *
 * Wraps get_option / update_option so the admin page and the pruner
 * itself share one source of truth for defaults, option keys, and
 * sanitisation rules. Centralising the keys here means changing
 * them later only touches one file.
 *
 * Both values are integers in months. Negative values are clamped
 * to zero on the way in *and* on the way out — a hand-edited
 * wp_options row containing a negative integer must not be able to
 * pull the cutoff into the future at runtime. The pruner itself
 * also clamps as a final defence in depth.
 */
final class PrunerSettings
{
    /**
     * Option key for the rotation grace period (months).
     *
     * Officers whose rotation date is older than now minus this many
     * months become candidates for the officer pass.
     */
    public const OPTION_ROTATION_GRACE_MONTHS = 'scrutiny_prune_rotation_grace_months';

    /**
     * Option key for the inactivity threshold (months).
     *
     * Home-group non-GSRs whose underlying post hasn't been updated in
     * this many months become candidates for the home-group pass.
     */
    public const OPTION_INACTIVITY_MONTHS = 'scrutiny_prune_inactivity_months';

    /**
     * Option key for the pruner enabled flag.
     *
     * When false (the default on a fresh install), MemberPruner::prune()
     * short-circuits with an empty result. The check lives on the service
     * itself so every caller — admin button, WP-CLI command, cron job —
     * automatically respects the toggle without each one having to
     * remember to read this flag separately.
     */
    public const OPTION_ENABLED = 'scrutiny_prune_enabled';

    /**
     * Option key for the trash-retention period (days).
     *
     * After each scheduled pruner run, members whose trash timestamp
     * (_wp_trash_meta_time) is older than now minus this many days
     * are permanently deleted. Stored in days rather than months
     * because the natural cadence is short — typically a single
     * cron interval — and a months-resolution input would feel
     * coarse for the admin to tune.
     */
    public const OPTION_TRASH_RETENTION_DAYS = 'scrutiny_prune_trash_retention_days';

    /**
     * Default rotation grace period — three months after a rotation
     * date passes is enough time for a successor to be elected and
     * recorded without leaving stale officers indefinitely.
     */
    public const DEFAULT_ROTATION_GRACE_MONTHS = 3;

    /**
     * Default inactivity threshold — 12 months mirrors typical
     * intergroup retention conventions and matches the audit-log
     * purge cadence already in Scrutiny.
     */
    public const DEFAULT_INACTIVITY_MONTHS = 12;

    /**
     * Default state on a fresh install — disabled.
     *
     * The pruner trashes member records, which is destructive (even
     * if recoverable from the WordPress trash). Requiring an explicit
     * opt-in means an admin who installs Scrutiny without reading the
     * docs cannot have member data removed by an accidental cron run.
     */
    public const DEFAULT_ENABLED = false;

    /**
     * Default trash-retention period — seven days, matching the
     * default cron interval. A member trashed in run N is therefore
     * eligible for permanent deletion in run N+1, unless an admin
     * restores them from the trash in between. This gives a full
     * cron cycle to notice and reverse an unwanted trash before it
     * becomes irreversible.
     */
    public const DEFAULT_TRASH_RETENTION_DAYS = 7;

    public function getRotationGraceMonths(): int
    {
        $stored = (int) get_option(
            self::OPTION_ROTATION_GRACE_MONTHS,
            self::DEFAULT_ROTATION_GRACE_MONTHS
        );

        return max(0, $stored);
    }

    public function getInactivityMonths(): int
    {
        $stored = (int) get_option(
            self::OPTION_INACTIVITY_MONTHS,
            self::DEFAULT_INACTIVITY_MONTHS
        );

        return max(0, $stored);
    }

    /**
     * Persist a new rotation grace value. Negative input is clamped
     * to zero rather than rejected so callers don't have to handle
     * a separate validation error path.
     */
    public function setRotationGraceMonths(int $months): void
    {
        update_option(self::OPTION_ROTATION_GRACE_MONTHS, max(0, $months));
    }

    public function setInactivityMonths(int $months): void
    {
        update_option(self::OPTION_INACTIVITY_MONTHS, max(0, $months));
    }

    /**
     * Whether the pruner is currently enabled.
     *
     * Stored as a boolean cast from whatever WordPress hands back —
     * the Settings API tends to round-trip checkboxes as the strings
     * '1' / '' or the integers 1 / 0, so an explicit (bool) cast
     * normalises all of them.
     */
    public function isEnabled(): bool
    {
        $stored = get_option(self::OPTION_ENABLED, self::DEFAULT_ENABLED);

        return (bool) $stored;
    }

    /**
     * Persist the enabled flag.
     *
     * Stored as a 1/0 integer rather than a PHP bool because WordPress
     * historically serialises booleans inconsistently across versions
     * and option backends; an integer round-trips cleanly everywhere.
     */
    public function setEnabled(bool $enabled): void
    {
        update_option(self::OPTION_ENABLED, $enabled ? 1 : 0);
    }

    /**
     * Days a trashed member is retained before permanent deletion.
     *
     * Same clamp pattern as the months getters: a hand-edited
     * wp_options row with a negative value must not pull the
     * retention cutoff into the future and start permanently
     * deleting recently-trashed members.
     */
    public function getTrashRetentionDays(): int
    {
        $stored = (int) get_option(
            self::OPTION_TRASH_RETENTION_DAYS,
            self::DEFAULT_TRASH_RETENTION_DAYS
        );

        return max(0, $stored);
    }

    public function setTrashRetentionDays(int $days): void
    {
        update_option(self::OPTION_TRASH_RETENTION_DAYS, max(0, $days));
    }
}
