<?php

declare(strict_types=1);

namespace Scrutiny\Tests\Unit\Cleanup;

use Scrutiny\Cleanup\PrunerSettings;

/*
 * Tests for PrunerSettings.
 *
 * The class is a thin wrapper over get_option / update_option, but the
 * clamping behaviour and default values matter — a misconfigured wp_options
 * row must not be allowed to pull pruner cutoffs into the future. These tests
 * exercise the wrapper against the in-memory option store stubbed in the
 * bootstrap.
 */

beforeEach(function () {
    // Reset the in-memory option store before every test so one test's writes
    // can't leak into the next.
    $GLOBALS['scrutiny_test_options'] = [];

    $this->settings = new PrunerSettings();
});

describe('grace and inactivity months', function () {
    it('returns the documented defaults when no value is stored', function () {
        expect($this->settings->getRotationGraceMonths())->toBe(PrunerSettings::DEFAULT_ROTATION_GRACE_MONTHS)
            ->and($this->settings->getInactivityMonths())->toBe(PrunerSettings::DEFAULT_INACTIVITY_MONTHS);
    });

    it('round-trips a saved rotation grace value', function () {
        $this->settings->setRotationGraceMonths(6);

        expect($this->settings->getRotationGraceMonths())->toBe(6);
    });

    it('round-trips a saved inactivity value', function () {
        $this->settings->setInactivityMonths(18);

        expect($this->settings->getInactivityMonths())->toBe(18);
    });

    it('clamps negative values to zero in the setters', function () {
        // A negative grace period would slide the cutoff into the future and
        // start trashing currently-valid members. The setter must reject this
        // even if the admin form somehow bypassed its own validation.
        $this->settings->setRotationGraceMonths(-5);
        $this->settings->setInactivityMonths(-12);

        expect($this->settings->getRotationGraceMonths())->toBe(0)
            ->and($this->settings->getInactivityMonths())->toBe(0);
    });

    it('clamps negative stored values to zero in the getters', function () {
        // Defence in depth: a negative integer in wp_options written by hand
        // or by an older buggy version of the code must not be returned
        // as-is. The getter clamps too, so the pruner can trust whatever
        // PrunerSettings hands it.
        $GLOBALS['scrutiny_test_options'][PrunerSettings::OPTION_ROTATION_GRACE_MONTHS] = -3;
        $GLOBALS['scrutiny_test_options'][PrunerSettings::OPTION_INACTIVITY_MONTHS] = -7;

        $settings = new PrunerSettings();

        expect($settings->getRotationGraceMonths())->toBe(0)
            ->and($settings->getInactivityMonths())->toBe(0);
    });

    it('coerces stored string values into integers', function () {
        // WordPress sometimes stores option values as strings (e.g. when
        // written via the Settings API). The getter must not hand a string to
        // a downstream caller that expects int.
        $GLOBALS['scrutiny_test_options'][PrunerSettings::OPTION_ROTATION_GRACE_MONTHS] = '4';
        $GLOBALS['scrutiny_test_options'][PrunerSettings::OPTION_INACTIVITY_MONTHS] = '24';

        $settings = new PrunerSettings();

        expect($settings->getRotationGraceMonths())->toBe(4)
            ->and($settings->getInactivityMonths())->toBe(24);
    });

    it('stores rotation and inactivity under distinct keys', function () {
        // Regression guard: writing one must not silently overwrite the other.
        $this->settings->setRotationGraceMonths(2);
        $this->settings->setInactivityMonths(15);

        expect($this->settings->getRotationGraceMonths())->toBe(2)
            ->and($this->settings->getInactivityMonths())->toBe(15)
            ->and(PrunerSettings::OPTION_ROTATION_GRACE_MONTHS)->not->toBe(PrunerSettings::OPTION_INACTIVITY_MONTHS);
    });

    it('persists zero as a valid value', function () {
        // Zero means "no grace", which is a legitimate (if aggressive)
        // configuration. It must round-trip without being mistaken for a
        // default fallback.
        $this->settings->setRotationGraceMonths(0);
        $this->settings->setInactivityMonths(0);

        expect($this->settings->getRotationGraceMonths())->toBe(0)
            ->and($this->settings->getInactivityMonths())->toBe(0);
    });
});

// ──────────────────────────────────────────────
//  Enabled flag
// ──────────────────────────────────────────────
describe('enabled flag', function () {
    it('is disabled by default', function () {
        // The pruner is destructive (even if recoverable from trash), so a
        // fresh install must default to disabled. This test would fail loudly
        // if anyone ever flipped DEFAULT_ENABLED to true — that change
        // deserves to be caught at code review.
        expect($this->settings->isEnabled())->toBeFalse()
            ->and(PrunerSettings::DEFAULT_ENABLED)->toBeFalse();
    });

    it('round-trips true', function () {
        $this->settings->setEnabled(true);

        expect($this->settings->isEnabled())->toBeTrue();
    });

    it('round-trips false', function () {
        // Enable then disable — proves the off-state isn't just the default
        // fallback being hit.
        $this->settings->setEnabled(true);
        $this->settings->setEnabled(false);

        expect($this->settings->isEnabled())->toBeFalse();
    });

    // The Settings API and various WP option backends serialise checkbox
    // state inconsistently — '1', 1, true, 'on' have all been seen. Anything
    // truthy must read back as enabled so a hand-edited wp_options row still
    // works.
    it('reads truthy stored values as enabled', function (mixed $truthy) {
        $GLOBALS['scrutiny_test_options'] = [PrunerSettings::OPTION_ENABLED => $truthy];

        expect((new PrunerSettings())->isEnabled())->toBeTrue();
    })->with([
        "string '1'" => ['1'],
        'integer 1'  => [1],
        'true'       => [true],
        "string 'on'" => ['on'],
    ]);

    // The complement of the above: anything PHP treats as falsy (empty
    // string, '0', integer 0, false) must read back as disabled.
    it('reads falsy stored values as disabled', function (mixed $falsy) {
        $GLOBALS['scrutiny_test_options'] = [PrunerSettings::OPTION_ENABLED => $falsy];

        expect((new PrunerSettings())->isEnabled())->toBeFalse();
    })->with([
        'empty string' => [''],
        "string '0'"   => ['0'],
        'integer 0'    => [0],
        'false'        => [false],
    ]);
});

// ──────────────────────────────────────────────
//  Trash retention
// ──────────────────────────────────────────────
describe('trash retention', function () {
    it('defaults to seven days', function () {
        // Default mirrors the cron interval so a member trashed in run N is
        // permanently deleted in run N+1 unless restored. This test pins down
        // the default.
        expect(PrunerSettings::DEFAULT_TRASH_RETENTION_DAYS)->toBe(7)
            ->and($this->settings->getTrashRetentionDays())->toBe(7);
    });

    it('round-trips', function () {
        $this->settings->setTrashRetentionDays(14);

        expect($this->settings->getTrashRetentionDays())->toBe(14);
    });

    it('clamps negative values to zero', function () {
        // Defence in depth — a hand-edited wp_options row containing a
        // negative integer must not be returned as-is, and the setter must
        // reject the same.
        $this->settings->setTrashRetentionDays(-3);
        expect($this->settings->getTrashRetentionDays())->toBe(0);

        $GLOBALS['scrutiny_test_options'][PrunerSettings::OPTION_TRASH_RETENTION_DAYS] = -10;
        expect((new PrunerSettings())->getTrashRetentionDays())->toBe(0);
    });

    it('persists zero as a valid value', function () {
        // Zero means "delete everything currently in trash" — a legitimate (if
        // aggressive) configuration. It must round-trip without being
        // mistaken for the default.
        $this->settings->setTrashRetentionDays(0);

        expect($this->settings->getTrashRetentionDays())->toBe(0);
    });
});
