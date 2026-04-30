<?php

declare(strict_types=1);

namespace Scrutiny\Tests\Unit\Cleanup;

use PHPUnit\Framework\TestCase;
use Scrutiny\Cleanup\PrunerSettings;

/**
 * Tests for PrunerSettings.
 *
 * The class is a thin wrapper over get_option / update_option, but the
 * clamping behaviour and default values matter — a misconfigured
 * wp_options row must not be allowed to pull pruner cutoffs into the
 * future. These tests exercise the wrapper against the in-memory
 * option store stubbed in the bootstrap.
 */
class PrunerSettingsTest extends TestCase
{
    protected function setUp(): void
    {
        // Reset the in-memory option store before every test so one
        // test's writes can't leak into the next.
        $GLOBALS['scrutiny_test_options'] = [];
    }

    /** @test */
    public function it_returns_the_documented_defaults_when_no_value_is_stored(): void
    {
        $settings = new PrunerSettings();

        $this->assertSame(
            PrunerSettings::DEFAULT_ROTATION_GRACE_MONTHS,
            $settings->getRotationGraceMonths()
        );
        $this->assertSame(
            PrunerSettings::DEFAULT_INACTIVITY_MONTHS,
            $settings->getInactivityMonths()
        );
    }

    /** @test */
    public function it_round_trips_a_saved_rotation_grace_value(): void
    {
        $settings = new PrunerSettings();

        $settings->setRotationGraceMonths(6);

        $this->assertSame(6, $settings->getRotationGraceMonths());
    }

    /** @test */
    public function it_round_trips_a_saved_inactivity_value(): void
    {
        $settings = new PrunerSettings();

        $settings->setInactivityMonths(18);

        $this->assertSame(18, $settings->getInactivityMonths());
    }

    /** @test */
    public function setters_clamp_negative_values_to_zero(): void
    {
        // A negative grace period would slide the cutoff into the
        // future and start trashing currently-valid members. The
        // setter must reject this even if the admin form somehow
        // bypassed its own validation.
        $settings = new PrunerSettings();

        $settings->setRotationGraceMonths(-5);
        $settings->setInactivityMonths(-12);

        $this->assertSame(0, $settings->getRotationGraceMonths());
        $this->assertSame(0, $settings->getInactivityMonths());
    }

    /** @test */
    public function getters_clamp_negative_stored_values_to_zero(): void
    {
        // Defence in depth: a negative integer in wp_options written
        // by hand or by an older buggy version of the code must not
        // be returned as-is. The getter clamps too, so the pruner
        // can trust whatever PrunerSettings hands it.
        $GLOBALS['scrutiny_test_options'][PrunerSettings::OPTION_ROTATION_GRACE_MONTHS] = -3;
        $GLOBALS['scrutiny_test_options'][PrunerSettings::OPTION_INACTIVITY_MONTHS] = -7;

        $settings = new PrunerSettings();

        $this->assertSame(0, $settings->getRotationGraceMonths());
        $this->assertSame(0, $settings->getInactivityMonths());
    }

    /** @test */
    public function getters_coerce_string_values_into_integers(): void
    {
        // WordPress sometimes stores option values as strings (e.g.
        // when written via the Settings API). The getter must not
        // hand a string to a downstream caller that expects int.
        $GLOBALS['scrutiny_test_options'][PrunerSettings::OPTION_ROTATION_GRACE_MONTHS] = '4';
        $GLOBALS['scrutiny_test_options'][PrunerSettings::OPTION_INACTIVITY_MONTHS] = '24';

        $settings = new PrunerSettings();

        $this->assertSame(4, $settings->getRotationGraceMonths());
        $this->assertSame(24, $settings->getInactivityMonths());
    }

    /** @test */
    public function rotation_and_inactivity_are_stored_under_distinct_keys(): void
    {
        // Regression guard: writing one must not silently overwrite
        // the other.
        $settings = new PrunerSettings();

        $settings->setRotationGraceMonths(2);
        $settings->setInactivityMonths(15);

        $this->assertSame(2, $settings->getRotationGraceMonths());
        $this->assertSame(15, $settings->getInactivityMonths());
        $this->assertNotSame(
            PrunerSettings::OPTION_ROTATION_GRACE_MONTHS,
            PrunerSettings::OPTION_INACTIVITY_MONTHS
        );
    }

    /** @test */
    public function zero_is_a_valid_persisted_value(): void
    {
        // Zero means "no grace", which is a legitimate (if aggressive)
        // configuration. It must round-trip without being mistaken for
        // a default fallback.
        $settings = new PrunerSettings();

        $settings->setRotationGraceMonths(0);
        $settings->setInactivityMonths(0);

        $this->assertSame(0, $settings->getRotationGraceMonths());
        $this->assertSame(0, $settings->getInactivityMonths());
    }

    // ──────────────────────────────────────────────
    //  Enabled flag
    // ──────────────────────────────────────────────

    /** @test */
    public function pruner_is_disabled_by_default(): void
    {
        // The pruner is destructive (even if recoverable from trash),
        // so a fresh install must default to disabled. This test
        // would fail loudly if anyone ever flipped DEFAULT_ENABLED
        // to true — that change deserves to be caught at code review.
        $settings = new PrunerSettings();

        $this->assertFalse($settings->isEnabled());
        $this->assertFalse(PrunerSettings::DEFAULT_ENABLED);
    }

    /** @test */
    public function setEnabled_round_trips_true(): void
    {
        $settings = new PrunerSettings();

        $settings->setEnabled(true);

        $this->assertTrue($settings->isEnabled());
    }

    /** @test */
    public function setEnabled_round_trips_false(): void
    {
        // Enable then disable — proves the off-state isn't just the
        // default fallback being hit.
        $settings = new PrunerSettings();

        $settings->setEnabled(true);
        $settings->setEnabled(false);

        $this->assertFalse($settings->isEnabled());
    }

    /** @test */
    public function isEnabled_coerces_string_truthy_values(): void
    {
        // The Settings API and various WP option backends serialise
        // checkbox state inconsistently — '1', 1, true, 'on' have
        // all been seen. Anything truthy must read back as enabled
        // so a hand-edited wp_options row still works.
        foreach (['1', 1, true, 'on'] as $truthy) {
            $GLOBALS['scrutiny_test_options'] = [
                PrunerSettings::OPTION_ENABLED => $truthy,
            ];

            $settings = new PrunerSettings();
            $this->assertTrue(
                $settings->isEnabled(),
                'Expected enabled=true for stored value: ' . var_export($truthy, true)
            );
        }
    }

    /** @test */
    public function isEnabled_coerces_string_falsy_values(): void
    {
        // The complement of the above: anything PHP treats as falsy
        // (empty string, '0', integer 0, false) must read back as
        // disabled.
        foreach (['', '0', 0, false] as $falsy) {
            $GLOBALS['scrutiny_test_options'] = [
                PrunerSettings::OPTION_ENABLED => $falsy,
            ];

            $settings = new PrunerSettings();
            $this->assertFalse(
                $settings->isEnabled(),
                'Expected enabled=false for stored value: ' . var_export($falsy, true)
            );
        }
    }
}
