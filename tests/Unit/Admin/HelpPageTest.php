<?php

declare(strict_types=1);

namespace Scrutiny\Tests\Unit\Admin;

use BleedingDeacons\WpMocks\WpState;
use Scrutiny\Admin\HelpPage;
use Scrutiny\Admin\ScrutinyMenu;

/*
 * Tests for the Help submenu and the footer script that hijacks its click.
 *
 * register() runs for real against WpState's menu recorder and this plugin's
 * own recording add_action() — see MemberPrunerAdminTest's header for why
 * hooks are read from $GLOBALS['scrutiny_test_actions'] here rather than
 * through assertActionAdded(), while WpState::$menus still works.
 *
 * Both render paths emit markup and are captured in an output buffer:
 * render() is the no-JavaScript fallback, and enqueueHelpTabScript() prints an
 * inline <script> whose selectors and window names are the contract that lets
 * the guide's back button refocus the admin tab instead of reloading it.
 */

covers(HelpPage::class);

beforeEach(function () {
    $GLOBALS['scrutiny_test_actions'] = [];

    $this->page = new HelpPage();
});

// ── registration ──────────────────────────────────────────────────
describe('register', function () {
    it('registers a Help submenu under the Scrutiny menu', function () {
        $this->page->register();

        expect(WpState::$menus)->toHaveCount(1)
            ->and(WpState::$menus[0])->toBe([
                'type'   => 'submenu',
                'parent' => ScrutinyMenu::MENU_SLUG,
                'slug'   => HelpPage::SLUG,
                'title'  => 'Help',
                'cap'    => HelpPage::CAPABILITY,
            ]);
    });

    // Help documents both screens, so it must not be visible to anyone who
    // cannot reach them — and must not be hidden from anyone who can.
    it('sits behind the same capability as the rest of the menu', function () {
        expect(HelpPage::CAPABILITY)->toBe(ScrutinyMenu::CAPABILITY);
    });

    // The click interceptor has to be printed on every admin screen, not just
    // this one — the Help link lives in the sidebar and is clicked from
    // wherever the user happens to be.
    it('also hooks the footer script', function () {
        $this->page->register();

        $hooks = array_column($GLOBALS['scrutiny_test_actions'], 'callback', 'hook');

        expect($hooks)->toHaveKey('admin_footer')
            ->and($hooks['admin_footer'])->toBe([$this->page, 'enqueueHelpTabScript']);
    });
});

// ── the no-JavaScript fallback ────────────────────────────────────
describe('render', function () {
    it('links straight to the bundled guide', function () {
        expect(captureOutput(fn () => $this->page->render()))
            ->toContain('<h1>Scrutiny Help</h1>', 'assets/docs/scrutiny.html', 'Open the guide');
    });

    // The fallback opens a new tab, so it needs rel="noopener" — without it
    // the guide gets a handle on wp-admin through window.opener.
    it('opens the guide safely in a new tab', function () {
        expect(captureOutput(fn () => $this->page->render()))
            ->toContain('target="_blank"', 'rel="noopener"');
    });
});

// ── the click interceptor ─────────────────────────────────────────
describe('enqueueHelpTabScript', function () {
    beforeEach(function () {
        $this->script = captureOutput(fn () => $this->page->enqueueHelpTabScript());
    });

    it('emits an inline script block', function () {
        expect($this->script)->toContain('<script>', '</script>');
    });

    // The script finds the Help link by its exact admin URL and falls back to
    // a slug match if WordPress rendered the href differently — both
    // selectors are load-bearing.
    it('matches the Help link by URL and by slug', function () {
        expect($this->script)->toContain(
            'admin.php?page=' . HelpPage::SLUG . '"]',
            'a[href*="page=' . HelpPage::SLUG . '"]',
        );
    });

    // The window names are how the guide gets back, and they are the same two
    // the Audit Log heading button already uses — so both routes share one
    // guide tab rather than opening a second.
    it('reuses the window names the Audit Log button uses', function () {
        expect($this->script)->toContain(
            "window.name = 'scrutiny-admin'",
            "window.open('', 'scrutiny-help')",
            "'?back=' + encodeURIComponent(window.location.href)",
            'assets/docs/scrutiny.html',
        );
    });

    // window.open() returns null when a popup blocker or an extension refuses
    // the window. preventDefault() has already run by then, so without an
    // explicit fallback the Help link would be inert — and the next line
    // would throw on the null handle rather than failing quietly.
    it('falls back to the current tab when the window is blocked', function () {
        expect($this->script)->toContain('if (!existing) {', 'window.location.href = helpUrl;');
    });

    // preventDefault() is what stops WordPress navigating to the fallback
    // page; without it the named-tab trick never runs.
    it('suppresses the default navigation', function () {
        expect($this->script)->toContain('e.preventDefault()', "addEventListener('click'");
    });
});
