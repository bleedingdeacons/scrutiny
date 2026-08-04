<?php

declare(strict_types=1);

namespace Scrutiny\Tests\Unit\Admin;

use BleedingDeacons\WpMocks\Exceptions\WpDieException;
use BleedingDeacons\WpMocks\WpState;
use ReflectionMethod;
use Scrutiny\Admin\MemberPrunerAdmin;
use Scrutiny\Admin\ScrutinyMenu;
use Scrutiny\Cleanup\PrunerCron;
use Scrutiny\Cleanup\PrunerSettings;
use Scrutiny\Tests\TestCase;

/**
 * Tests for the Pruner Settings screen.
 *
 * Three kinds of method, three techniques — the pattern Amber established and
 * Integrity's SettingsPageTest documents:
 *
 *   - Registration (the constructor's hooks, registerMenu) runs for real and
 *     is asserted against the recorded state.
 *   - The capability guards call wp_die(), which the shared stubs turn into a
 *     WpDieException, so each refusal is a plain expectException.
 *   - renderPage() is called inside an output buffer and asserted on as HTML.
 *
 * Note this plugin's bootstrap predates wp-mocks and keeps its own recording
 * add_action() and globals-backed current_user_can() / get_option() /
 * wp_next_scheduled(). Those win over the shared stubs by design (see the
 * comment at the foot of tests/bootstrap.php), so hooks are read from
 * $GLOBALS['scrutiny_test_actions'] rather than through assertActionAdded(),
 * and capabilities are granted through $GLOBALS['scrutiny_test_capabilities']
 * rather than WpState::$userCan. Menus, escaping and wp_die do come from the
 * shared layer, which is why WpState::$menus works below.
 *
 * handleSave() ends in wp_safe_redirect() followed by a bare exit. The stubs
 * record the redirect rather than throwing, so exit runs and would take PHPUnit
 * with it — the live success path genuinely cannot run in-process. Its guards
 * are covered here; the work behind them is reached through reflection on
 * persistPostedSettings() and savedRedirectUrl(), which were split out of
 * handleSave() for exactly that reason.
 *
 * @covers \Scrutiny\Admin\MemberPrunerAdmin
 */
final class MemberPrunerAdminTest extends TestCase
{
    private PrunerSettings $settings;
    private MemberPrunerAdmin $page;

    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['scrutiny_test_actions']      = [];
        $GLOBALS['scrutiny_test_capabilities'] = [];
        $GLOBALS['scrutiny_test_options']      = [];
        $GLOBALS['scrutiny_test_cron_queue']   = [];

        $_GET  = [];
        $_POST = [];

        $this->settings = new PrunerSettings();
        $this->page     = new MemberPrunerAdmin($this->settings);
    }

    protected function tearDown(): void
    {
        $_GET  = [];
        $_POST = [];

        parent::tearDown();
    }

    /** Grant the capability the screen and the save both check. */
    private function grantCapability(): void
    {
        $GLOBALS['scrutiny_test_capabilities'][MemberPrunerAdmin::CAPABILITY] = true;
    }

    /**
     * Render the screen and hand back its markup with line endings
     * normalised — the template is a heredoc-style PHP block, so on Windows
     * every attribute is separated by "\r\n" and an assertion written against
     * "\n" would pass on CI and fail locally.
     */
    private function render(): string
    {
        ob_start();
        try {
            $this->page->renderPage();
        } finally {
            $html = (string) ob_get_clean();
        }

        return str_replace("\r\n", "\n", $html);
    }

    // ── registration ──────────────────────────────────────────────────

    /** @test */
    public function it_hooks_the_menu_and_the_save_handler_on_construction(): void
    {
        $hooks = [];
        foreach ($GLOBALS['scrutiny_test_actions'] as $action) {
            $hooks[$action['hook']] = $action['priority'];
        }

        $this->assertArrayHasKey('admin_menu', $hooks);
        $this->assertArrayHasKey('admin_init', $hooks);
    }

    /**
     * ScrutinyMenu registers the parent at the default priority 10 and strips
     * the auto-generated child at 999. This page has to land between the two,
     * or it attaches to a menu that does not exist yet.
     *
     * @test
     */
    public function the_menu_registration_runs_after_the_parent_menu_is_created(): void
    {
        $priorities = [];
        foreach ($GLOBALS['scrutiny_test_actions'] as $action) {
            $priorities[$action['hook']] = $action['priority'];
        }

        $this->assertSame(20, $priorities['admin_menu']);
    }

    /** @test */
    public function it_registers_a_submenu_under_the_scrutiny_menu(): void
    {
        $this->page->registerMenu();

        $this->assertCount(1, WpState::$menus);
        $this->assertSame([
            'type'   => 'submenu',
            'parent' => ScrutinyMenu::MENU_SLUG,
            'slug'   => MemberPrunerAdmin::MENU_SLUG,
            'title'  => 'Pruner Settings',
            'cap'    => MemberPrunerAdmin::CAPABILITY,
        ], WpState::$menus[0]);
    }

    // ── save: guards ──────────────────────────────────────────────────

    /**
     * admin_init fires on every admin request, so the handler has to leave
     * unrelated screens alone rather than consuming their nonces or reading
     * their POST values.
     *
     * @test
     */
    public function the_save_handler_ignores_a_request_that_is_not_its_own_form(): void
    {
        $_POST = ['rotation_grace_months' => '99', 'enabled' => '1'];

        $this->page->handleSave();

        $this->assertSame([], $GLOBALS['scrutiny_test_options'], 'nothing should have been written');
        $this->assertSame([], WpState::$redirects, 'and no redirect should have been issued');
    }

    /**
     * The nonce proves the request came from the form; it does not prove the
     * submitter is allowed to change the settings, so the capability is
     * checked separately.
     *
     * @test
     */
    public function the_save_handler_refuses_a_user_without_the_capability(): void
    {
        $_POST = [MemberPrunerAdmin::NONCE_FIELD => 'nonce-' . MemberPrunerAdmin::NONCE_ACTION];

        $this->expectException(WpDieException::class);
        $this->page->handleSave();
    }

    /** @test */
    public function nothing_is_written_when_the_capability_check_fails(): void
    {
        $_POST = [
            MemberPrunerAdmin::NONCE_FIELD => 'nonce-' . MemberPrunerAdmin::NONCE_ACTION,
            'rotation_grace_months'        => '99',
        ];

        try {
            $this->page->handleSave();
            $this->fail('expected wp_die() to stop the save');
        } catch (WpDieException) {
            $this->assertSame([], $GLOBALS['scrutiny_test_options']);
        }
    }

    // ── save: persistence (reflection: the live caller exits) ─────────

    /** @param array<string, mixed> $post */
    private function persist(array $post): void
    {
        $_POST = $post;

        (new ReflectionMethod(MemberPrunerAdmin::class, 'persistPostedSettings'))
            ->invoke($this->page);
    }

    /** @test */
    public function a_full_submission_is_written_through_to_the_settings(): void
    {
        $this->persist([
            'rotation_grace_months' => '6',
            'inactivity_months'     => '18',
            'trash_retention_days'  => '30',
            'enabled'               => '1',
        ]);

        $this->assertSame(6, $this->settings->getRotationGraceMonths());
        $this->assertSame(18, $this->settings->getInactivityMonths());
        $this->assertSame(30, $this->settings->getTrashRetentionDays());
        $this->assertTrue($this->settings->isEnabled());
    }

    /**
     * An unticked checkbox is not posted at all, so "field absent" has to mean
     * disabled — otherwise the pruner could never be turned off from the form.
     *
     * @test
     */
    public function an_absent_checkbox_disables_the_pruner(): void
    {
        $this->settings->setEnabled(true);

        $this->persist(['rotation_grace_months' => '3']);

        $this->assertFalse($this->settings->isEnabled());
    }

    /** @test */
    public function a_checkbox_posted_as_zero_also_disables_the_pruner(): void
    {
        $this->settings->setEnabled(true);

        $this->persist(['enabled' => '0']);

        $this->assertFalse($this->settings->isEnabled());
    }

    /**
     * Every field runs through the same clamp, so the boundaries are asserted
     * once per field rather than once per case.
     *
     * @test
     * @dataProvider boundedValues
     */
    public function posted_values_are_clamped_into_range(
        string $posted,
        int $expectedMonths,
        int $expectedDays
    ): void {
        $this->persist([
            'rotation_grace_months' => $posted,
            'inactivity_months'     => $posted,
            'trash_retention_days'  => $posted,
        ]);

        $this->assertSame($expectedMonths, $this->settings->getRotationGraceMonths());
        $this->assertSame($expectedMonths, $this->settings->getInactivityMonths());
        $this->assertSame($expectedDays, $this->settings->getTrashRetentionDays());
    }

    /**
     * Months clamp at 144, days at 365.
     *
     * @return array<string, array{0: string, 1: int, 2: int}>
     */
    public static function boundedValues(): array
    {
        return [
            'zero'                  => ['0', 0, 0],
            'in range'              => ['12', 12, 12],
            'negative becomes zero' => ['-5', 0, 0],
            'non-numeric is zero'   => ['nonsense', 0, 0],
            'empty string is zero'  => ['', 0, 0],
            'at the months ceiling' => ['144', 144, 144],
            'over the months ceiling, under the days one' => ['200', 144, 200],
            'over both ceilings'    => ['9999', 144, 365],
        ];
    }

    /**
     * A wiped input posts an empty string and a missing one posts nothing;
     * both mean "no grace period" rather than "reject the submission".
     *
     * @test
     */
    public function a_missing_field_is_saved_as_zero_rather_than_left_alone(): void
    {
        $this->settings->setRotationGraceMonths(9);

        $this->persist(['inactivity_months' => '12']);

        $this->assertSame(0, $this->settings->getRotationGraceMonths());
    }

    /** @test */
    public function the_success_redirect_returns_to_this_page_with_the_updated_flag(): void
    {
        $url = (new ReflectionMethod(MemberPrunerAdmin::class, 'savedRedirectUrl'))
            ->invoke($this->page);

        $this->assertIsString($url);
        $this->assertStringContainsString('admin.php', $url);
        $this->assertStringContainsString('page=' . MemberPrunerAdmin::MENU_SLUG, $url);
        $this->assertStringContainsString('updated=1', $url);
    }

    // ── render: guard ─────────────────────────────────────────────────

    /** @test */
    public function the_screen_refuses_a_user_without_the_capability(): void
    {
        $this->expectException(WpDieException::class);
        $this->page->renderPage();
    }

    // ── render: output ────────────────────────────────────────────────

    /** @test */
    public function the_screen_renders_a_form_with_a_nonce_and_the_three_fields(): void
    {
        $this->grantCapability();

        $html = $this->render();

        $this->assertStringContainsString('Scrutiny – Pruner Settings', $html);
        $this->assertStringContainsString(MemberPrunerAdmin::NONCE_FIELD, $html);
        $this->assertStringContainsString('name="rotation_grace_months"', $html);
        $this->assertStringContainsString('name="inactivity_months"', $html);
        $this->assertStringContainsString('name="trash_retention_days"', $html);
        $this->assertStringContainsString('name="enabled"', $html);
    }

    /** @test */
    public function the_stored_values_are_rendered_into_the_inputs(): void
    {
        $this->grantCapability();
        $this->settings->setRotationGraceMonths(4);
        $this->settings->setInactivityMonths(24);
        $this->settings->setTrashRetentionDays(14);

        $html = $this->render();

        $this->assertMatchesRegularExpression('/name="rotation_grace_months"\s+value="4"/', $html);
        $this->assertMatchesRegularExpression('/name="inactivity_months"\s+value="24"/', $html);
        $this->assertMatchesRegularExpression('/name="trash_retention_days"\s+value="14"/', $html);
    }

    /**
     * The maxima are rendered as the inputs' max attribute, so the browser
     * enforces the same bound the save clamps to.
     *
     * @test
     */
    public function the_inputs_advertise_the_same_ceilings_the_save_clamps_to(): void
    {
        $this->grantCapability();

        $html = $this->render();

        $this->assertSame(2, substr_count($html, 'max="144"'), 'both month fields');
        $this->assertStringContainsString('max="365"', $html);
    }

    /**
     * Matched against the input element rather than the whole page: the
     * field's own description reads "When unchecked, …", so a bare search for
     * "checked" passes in both states.
     *
     * @test
     */
    public function the_enabled_checkbox_reflects_the_stored_state(): void
    {
        $this->grantCapability();
        $this->settings->setEnabled(true);

        $this->assertMatchesRegularExpression(
            '/name="enabled"\s+value="1"\s+checked\s*>/',
            $this->render()
        );
    }

    /** @test */
    public function the_checkbox_is_unchecked_when_the_pruner_is_disabled(): void
    {
        $this->grantCapability();
        $this->settings->setEnabled(false);

        $this->assertMatchesRegularExpression(
            '/name="enabled"\s+value="1"\s*>/',
            $this->render()
        );
        $this->assertDoesNotMatchRegularExpression(
            '/name="enabled"\s+value="1"\s+checked/',
            $this->render()
        );
    }

    /**
     * The banner is the at-a-glance answer to "is this about to do
     * something?", so the two states have to be distinguishable.
     *
     * @test
     */
    public function an_enabled_pruner_gets_a_warning_banner(): void
    {
        $this->grantCapability();
        $this->settings->setEnabled(true);

        $html = $this->render();

        $this->assertStringContainsString('The pruner is currently enabled.', $html);
        $this->assertStringContainsString('notice-warning', $html);
        $this->assertStringNotContainsString('currently disabled', $html);
    }

    /** @test */
    public function a_disabled_pruner_gets_an_info_banner(): void
    {
        $this->grantCapability();

        $html = $this->render();

        $this->assertStringContainsString('The pruner is currently disabled.', $html);
        $this->assertStringContainsString('notice-info', $html);
        $this->assertStringNotContainsString('currently enabled', $html);
    }

    /** @test */
    public function the_saved_notice_appears_only_after_a_save(): void
    {
        $this->grantCapability();

        $this->assertStringNotContainsString('Settings saved.', $this->render());

        $_GET['updated'] = '1';

        $this->assertStringContainsString('Settings saved.', $this->render());
    }

    /**
     * The flag is compared strictly against '1', so a truthy-but-different
     * value in the query string does not fake a save.
     *
     * @test
     */
    public function an_unrecognised_updated_flag_does_not_show_the_saved_notice(): void
    {
        $this->grantCapability();
        $_GET['updated'] = 'yes';

        $this->assertStringNotContainsString('Settings saved.', $this->render());
    }

    // ── render: the next-run line ─────────────────────────────────────

    /**
     * The line is shown whether or not the pruner is enabled, because the cron
     * schedule is independent of the flag — an admin re-enabling the pruner
     * needs to know when the next run will land.
     *
     * @test
     */
    public function an_unscheduled_cron_event_is_reported_as_such(): void
    {
        $this->grantCapability();

        $html = $this->render();

        $this->assertStringContainsString('Cron event is not scheduled', $html);
    }

    /** @test */
    public function a_future_run_is_reported_with_its_formatted_timestamp(): void
    {
        $this->grantCapability();
        $GLOBALS['scrutiny_test_options']['date_format'] = 'Y-m-d';
        $GLOBALS['scrutiny_test_options']['time_format'] = 'H:i';

        $future = time() + 3600;
        $GLOBALS['scrutiny_test_cron_queue'][PrunerCron::HOOK] = [
            'timestamp'  => $future,
            'recurrence' => 'weekly',
        ];

        $html = $this->render();

        $this->assertStringContainsString('Next scheduled run: ' . date('Y-m-d H:i', $future), $html);
        $this->assertStringNotContainsString('overdue', $html);
    }

    /**
     * A timestamp in the past means WP-Cron has not fired — common on a quiet
     * site — rather than that the event is missing, so it gets its own wording.
     *
     * @test
     */
    public function a_past_timestamp_is_reported_as_overdue(): void
    {
        $this->grantCapability();
        $GLOBALS['scrutiny_test_options']['date_format'] = 'Y-m-d';
        $GLOBALS['scrutiny_test_options']['time_format'] = 'H:i';

        $GLOBALS['scrutiny_test_cron_queue'][PrunerCron::HOOK] = [
            'timestamp'  => time() - 3600,
            'recurrence' => 'weekly',
        ];

        $html = $this->render();

        $this->assertStringContainsString('overdue — will fire on the next site visit', $html);
    }

    /** @test */
    public function the_next_run_line_is_shown_when_the_pruner_is_enabled_too(): void
    {
        $this->grantCapability();
        $this->settings->setEnabled(true);
        $GLOBALS['scrutiny_test_cron_queue'][PrunerCron::HOOK] = [
            'timestamp'  => time() + 3600,
            'recurrence' => 'weekly',
        ];

        $html = $this->render();

        $this->assertStringContainsString('Next scheduled run:', $html);
        $this->assertStringContainsString('The pruner is currently enabled.', $html);
    }
}
