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
}
