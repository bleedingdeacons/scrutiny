<?php

declare(strict_types=1);

namespace Scrutiny\Tests\Unit\Admin;

use BleedingDeacons\WpMocks\WpState;
use Scrutiny\Admin\HelpPage;
use Scrutiny\Admin\ScrutinyMenu;
use Scrutiny\Tests\TestCase;

/**
 * Tests for the Help submenu and the footer script that hijacks its click.
 *
 * register() runs for real against WpState's menu recorder and this plugin's
 * own recording add_action() — see MemberPrunerAdminTest's docblock for why
 * hooks are read from $GLOBALS['scrutiny_test_actions'] here rather than
 * through assertActionAdded(), while WpState::$menus still works.
 *
 * Both render paths emit markup and are captured in an output buffer:
 * render() is the no-JavaScript fallback, and enqueueHelpTabScript() prints an
 * inline <script> whose selectors and window names are the contract that lets
 * the guide's back button refocus the admin tab instead of reloading it.
 *
 * @covers \Scrutiny\Admin\HelpPage
 */
final class HelpPageTest extends TestCase
{
    private HelpPage $page;

    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['scrutiny_test_actions'] = [];

        $this->page = new HelpPage();
    }

    private function capture(callable $render): string
    {
        ob_start();

        try {
            $render();
        } finally {
            $html = (string) ob_get_clean();
        }

        return str_replace("\r\n", "\n", $html);
    }

    // ── registration ──────────────────────────────────────────────────

    /** @test */
    public function it_registers_a_help_submenu_under_the_scrutiny_menu(): void
    {
        $this->page->register();

        $this->assertCount(1, WpState::$menus);
        $this->assertSame([
            'type'   => 'submenu',
            'parent' => ScrutinyMenu::MENU_SLUG,
            'slug'   => HelpPage::SLUG,
            'title'  => 'Help',
            'cap'    => HelpPage::CAPABILITY,
        ], WpState::$menus[0]);
    }

    /**
     * Help documents both screens, so it must not be visible to anyone who
     * cannot reach them — and must not be hidden from anyone who can.
     *
     * @test
     */
    public function the_submenu_sits_behind_the_same_capability_as_the_rest_of_the_menu(): void
    {
        $this->assertSame(ScrutinyMenu::CAPABILITY, HelpPage::CAPABILITY);
    }

    /**
     * The click interceptor has to be printed on every admin screen, not just
     * this one — the Help link lives in the sidebar and is clicked from
     * wherever the user happens to be.
     *
     * @test
     */
    public function registering_also_hooks_the_footer_script(): void
    {
        $this->page->register();

        $hooks = [];
        foreach ($GLOBALS['scrutiny_test_actions'] as $action) {
            $hooks[$action['hook']] = $action['callback'];
        }

        $this->assertArrayHasKey('admin_footer', $hooks);
        $this->assertSame([$this->page, 'enqueueHelpTabScript'], $hooks['admin_footer']);
    }

    // ── the no-JavaScript fallback ────────────────────────────────────

    /** @test */
    public function the_fallback_page_links_straight_to_the_bundled_guide(): void
    {
        $html = $this->capture(fn () => $this->page->render());

        $this->assertStringContainsString('<h1>Scrutiny Help</h1>', $html);
        $this->assertStringContainsString('assets/docs/scrutiny.html', $html);
        $this->assertStringContainsString('Open the guide', $html);
    }

    /**
     * The fallback opens a new tab, so it needs rel="noopener" — without it
     * the guide gets a handle on wp-admin through window.opener.
     *
     * @test
     */
    public function the_fallback_link_opens_safely_in_a_new_tab(): void
    {
        $html = $this->capture(fn () => $this->page->render());

        $this->assertStringContainsString('target="_blank"', $html);
        $this->assertStringContainsString('rel="noopener"', $html);
    }

    // ── the click interceptor ─────────────────────────────────────────

    /** @test */
    public function the_footer_script_is_emitted_as_an_inline_script_block(): void
    {
        $html = $this->capture(fn () => $this->page->enqueueHelpTabScript());

        $this->assertStringContainsString('<script>', $html);
        $this->assertStringContainsString('</script>', $html);
    }

    /**
     * The script finds the Help link by its exact admin URL and falls back to
     * a slug match if WordPress rendered the href differently — both selectors
     * are load-bearing.
     *
     * @test
     */
    public function the_script_matches_the_help_link_by_url_and_by_slug(): void
    {
        $html = $this->capture(fn () => $this->page->enqueueHelpTabScript());

        $this->assertStringContainsString('admin.php?page=' . HelpPage::SLUG . '"]', $html);
        $this->assertStringContainsString('a[href*="page=' . HelpPage::SLUG . '"]', $html);
    }

    /**
     * The window names are how the guide gets back, and they are the same two
     * the Audit Log heading button already uses — so both routes share one
     * guide tab rather than opening a second.
     *
     * @test
     */
    public function the_script_reuses_the_window_names_the_audit_log_button_uses(): void
    {
        $html = $this->capture(fn () => $this->page->enqueueHelpTabScript());

        $this->assertStringContainsString("window.name = 'scrutiny-admin'", $html);
        $this->assertStringContainsString("window.open('', 'scrutiny-help')", $html);
        $this->assertStringContainsString("'?back=' + encodeURIComponent(window.location.href)", $html);
        $this->assertStringContainsString('assets/docs/scrutiny.html', $html);
    }

    /**
     * window.open() returns null when a popup blocker or an extension refuses
     * the window. preventDefault() has already run by then, so without an
     * explicit fallback the Help link would be inert — and the next line would
     * throw on the null handle rather than failing quietly.
     *
     * @test
     */
    public function the_script_falls_back_to_the_current_tab_when_the_window_is_blocked(): void
    {
        $html = $this->capture(fn () => $this->page->enqueueHelpTabScript());

        $this->assertStringContainsString('if (!existing) {', $html);
        $this->assertStringContainsString('window.location.href = helpUrl;', $html);
    }

    /**
     * preventDefault() is what stops WordPress navigating to the fallback page;
     * without it the named-tab trick never runs.
     *
     * @test
     */
    public function the_script_suppresses_the_default_navigation(): void
    {
        $html = $this->capture(fn () => $this->page->enqueueHelpTabScript());

        $this->assertStringContainsString('e.preventDefault()', $html);
        $this->assertStringContainsString("addEventListener('click'", $html);
    }
}
