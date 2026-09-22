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

/*
 * Tests for the Pruner Settings screen.
 *
 * Three kinds of method, three techniques — the pattern Amber established and
 * Integrity's SettingsPageTest documents:
 *
 *   - Registration (the constructor's hooks, registerMenu) runs for real and
 *     is asserted against the recorded state.
 *   - The capability guards call wp_die(), which the shared stubs turn into a
 *     WpDieException, so each refusal is a plain ->throws().
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
 */

covers(MemberPrunerAdmin::class);

/** Grant the capability the screen and the save both check. */
function grantPrunerCapability(): void
{
    $GLOBALS['scrutiny_test_capabilities'][MemberPrunerAdmin::CAPABILITY] = true;
}

/**
 * @return array<string, int> each recorded action hook's priority
 */
function recordedActionPriorities(): array
{
    return array_column($GLOBALS['scrutiny_test_actions'], 'priority', 'hook');
}

/**
 * Put the next pruner cron run $offset seconds from now.
 */
function scheduleNextPrune(int $offset): int
{
    $timestamp = time() + $offset;
    $GLOBALS['scrutiny_test_cron_queue'][PrunerCron::HOOK] = [
        'timestamp'  => $timestamp,
        'recurrence' => 'weekly',
    ];

    return $timestamp;
}

beforeEach(function () {
    $GLOBALS['scrutiny_test_actions']      = [];
    $GLOBALS['scrutiny_test_capabilities'] = [];
    $GLOBALS['scrutiny_test_options']      = [];
    $GLOBALS['scrutiny_test_cron_queue']   = [];

    $_GET  = [];
    $_POST = [];

    $this->settings = new PrunerSettings();
    $this->page     = new MemberPrunerAdmin($this->settings);

    // Render the screen and hand back its markup with line endings normalised
    // — the template is a heredoc-style PHP block, so on Windows every
    // attribute is separated by "\r\n" and an assertion written against "\n"
    // would pass on CI and fail locally. captureOutput() does the normalising.
    $this->render = fn (): string => captureOutput(fn () => $this->page->renderPage());

    // persistPostedSettings() is reached through reflection: its live caller,
    // handleSave(), exits.
    $this->persist = function (array $post): void {
        $_POST = $post;

        (new ReflectionMethod(MemberPrunerAdmin::class, 'persistPostedSettings'))->invoke($this->page);
    };
});

afterEach(function () {
    $_GET  = [];
    $_POST = [];
});

// ── registration ──────────────────────────────────────────────────
describe('registration', function () {
    it('hooks the menu and the save handler on construction', function () {
        expect(recordedActionPriorities())->toHaveKeys(['admin_menu', 'admin_init']);
    });

    // ScrutinyMenu registers the parent at the default priority 10 and strips
    // the auto-generated child at 999. This page has to land between the two,
    // or it attaches to a menu that does not exist yet.
    it('registers the menu after the parent menu is created', function () {
        expect(recordedActionPriorities()['admin_menu'])->toBe(20);
    });

    it('registers a submenu under the Scrutiny menu', function () {
        $this->page->registerMenu();

        expect(WpState::$menus)->toHaveCount(1)
            ->and(WpState::$menus[0])->toBe([
                'type'   => 'submenu',
                'parent' => ScrutinyMenu::MENU_SLUG,
                'slug'   => MemberPrunerAdmin::MENU_SLUG,
                'title'  => 'Pruner Settings',
                'cap'    => MemberPrunerAdmin::CAPABILITY,
            ]);
    });
});

// ── save: guards ──────────────────────────────────────────────────
describe('the save guards', function () {
    // admin_init fires on every admin request, so the handler has to leave
    // unrelated screens alone rather than consuming their nonces or reading
    // their POST values.
    it('ignores a request that is not its own form', function () {
        $_POST = ['rotation_grace_months' => '99', 'enabled' => '1'];

        $this->page->handleSave();

        expect($GLOBALS['scrutiny_test_options'])->toBe([], 'nothing should have been written')
            ->and(WpState::$redirects)->toBe([], 'and no redirect should have been issued');
    });

    // The nonce proves the request came from the form; it does not prove the
    // submitter is allowed to change the settings, so the capability is
    // checked separately.
    it('refuses a user without the capability', function () {
        $_POST = [MemberPrunerAdmin::NONCE_FIELD => 'nonce-' . MemberPrunerAdmin::NONCE_ACTION];

        $this->page->handleSave();
    })->throws(WpDieException::class);

    it('writes nothing when the capability check fails', function () {
        $_POST = [
            MemberPrunerAdmin::NONCE_FIELD => 'nonce-' . MemberPrunerAdmin::NONCE_ACTION,
            'rotation_grace_months'        => '99',
        ];

        expect(fn () => $this->page->handleSave())->toThrow(WpDieException::class)
            ->and($GLOBALS['scrutiny_test_options'])->toBe([]);
    });
});

// ── save: persistence (reflection: the live caller exits) ─────────
describe('persisting a submission', function () {
    it('writes a full submission through to the settings', function () {
        ($this->persist)([
            'rotation_grace_months' => '6',
            'inactivity_months'     => '18',
            'trash_retention_days'  => '30',
            'enabled'               => '1',
        ]);

        expect($this->settings->getRotationGraceMonths())->toBe(6)
            ->and($this->settings->getInactivityMonths())->toBe(18)
            ->and($this->settings->getTrashRetentionDays())->toBe(30)
            ->and($this->settings->isEnabled())->toBeTrue();
    });

    // An unticked checkbox is not posted at all, so "field absent" has to mean
    // disabled — otherwise the pruner could never be turned off from the form.
    it('disables the pruner when the checkbox is absent', function () {
        $this->settings->setEnabled(true);

        ($this->persist)(['rotation_grace_months' => '3']);

        expect($this->settings->isEnabled())->toBeFalse();
    });

    it('disables the pruner when the checkbox is posted as zero', function () {
        $this->settings->setEnabled(true);

        ($this->persist)(['enabled' => '0']);

        expect($this->settings->isEnabled())->toBeFalse();
    });

    // Every field runs through the same clamp, so the boundaries are asserted
    // once per field rather than once per case. Months clamp at 144, days at
    // 365.
    it('clamps posted values into range', function (string $posted, int $expectedMonths, int $expectedDays) {
        ($this->persist)([
            'rotation_grace_months' => $posted,
            'inactivity_months'     => $posted,
            'trash_retention_days'  => $posted,
        ]);

        expect($this->settings->getRotationGraceMonths())->toBe($expectedMonths)
            ->and($this->settings->getInactivityMonths())->toBe($expectedMonths)
            ->and($this->settings->getTrashRetentionDays())->toBe($expectedDays);
    })->with([
        'zero'                  => ['0', 0, 0],
        'in range'              => ['12', 12, 12],
        'negative becomes zero' => ['-5', 0, 0],
        'non-numeric is zero'   => ['nonsense', 0, 0],
        'empty string is zero'  => ['', 0, 0],
        'at the months ceiling' => ['144', 144, 144],
        'over the months ceiling, under the days one' => ['200', 144, 200],
        'over both ceilings'    => ['9999', 144, 365],
    ]);

    // A wiped input posts an empty string and a missing one posts nothing;
    // both mean "no grace period" rather than "reject the submission".
    it('saves a missing field as zero rather than leaving it alone', function () {
        $this->settings->setRotationGraceMonths(9);

        ($this->persist)(['inactivity_months' => '12']);

        expect($this->settings->getRotationGraceMonths())->toBe(0);
    });

    it('redirects back to this page with the updated flag on success', function () {
        $url = (new ReflectionMethod(MemberPrunerAdmin::class, 'savedRedirectUrl'))->invoke($this->page);

        expect($url)->toBeString()->toContain(
            'admin.php',
            'page=' . MemberPrunerAdmin::MENU_SLUG,
            'updated=1',
        );
    });
});

// ── render: guard ─────────────────────────────────────────────────
it('refuses to render the screen for a user without the capability', function () {
    $this->page->renderPage();
})->throws(WpDieException::class);

// ── render: output ────────────────────────────────────────────────
describe('the rendered screen', function () {
    beforeEach(function () {
        grantPrunerCapability();
    });

    it('renders a form with a nonce and the three fields', function () {
        expect(($this->render)())->toContain(
            'Scrutiny – Pruner Settings',
            MemberPrunerAdmin::NONCE_FIELD,
            'name="rotation_grace_months"',
            'name="inactivity_months"',
            'name="trash_retention_days"',
            'name="enabled"',
        );
    });

    it('renders the stored values into the inputs', function () {
        $this->settings->setRotationGraceMonths(4);
        $this->settings->setInactivityMonths(24);
        $this->settings->setTrashRetentionDays(14);

        expect(($this->render)())
            ->toMatch('/name="rotation_grace_months"\s+value="4"/')
            ->toMatch('/name="inactivity_months"\s+value="24"/')
            ->toMatch('/name="trash_retention_days"\s+value="14"/');
    });

    // The maxima are rendered as the inputs' max attribute, so the browser
    // enforces the same bound the save clamps to.
    it('advertises the same ceilings the save clamps to', function () {
        $html = ($this->render)();

        expect(substr_count($html, 'max="144"'))->toBe(2, 'both month fields')
            ->and($html)->toContain('max="365"');
    });

    // Matched against the input element rather than the whole page: the
    // field's own description reads "When unchecked, …", so a bare search for
    // "checked" passes in both states.
    it('reflects the stored state in the enabled checkbox', function () {
        $this->settings->setEnabled(true);

        expect(($this->render)())->toMatch('/name="enabled"\s+value="1"\s+checked\s*>/');
    });

    it('leaves the checkbox unchecked when the pruner is disabled', function () {
        $this->settings->setEnabled(false);

        expect(($this->render)())
            ->toMatch('/name="enabled"\s+value="1"\s*>/')
            ->not->toMatch('/name="enabled"\s+value="1"\s+checked/');
    });

    // The banner is the at-a-glance answer to "is this about to do
    // something?", so the two states have to be distinguishable.
    it('gives an enabled pruner a warning banner', function () {
        $this->settings->setEnabled(true);

        expect(($this->render)())
            ->toContain('The pruner is currently enabled.', 'notice-warning')
            ->not->toContain('currently disabled');
    });

    it('gives a disabled pruner an info banner', function () {
        expect(($this->render)())
            ->toContain('The pruner is currently disabled.', 'notice-info')
            ->not->toContain('currently enabled');
    });

    it('shows the saved notice only after a save', function () {
        expect(($this->render)())->not->toContain('Settings saved.');

        $_GET['updated'] = '1';

        expect(($this->render)())->toContain('Settings saved.');
    });

    // The flag is compared strictly against '1', so a truthy-but-different
    // value in the query string does not fake a save.
    it('does not show the saved notice for an unrecognised updated flag', function () {
        $_GET['updated'] = 'yes';

        expect(($this->render)())->not->toContain('Settings saved.');
    });
});

// ── render: the next-run line ─────────────────────────────────────
// The line is shown whether or not the pruner is enabled, because the cron
// schedule is independent of the flag — an admin re-enabling the pruner
// needs to know when the next run will land.
describe('the next-run line', function () {
    beforeEach(function () {
        grantPrunerCapability();
        $GLOBALS['scrutiny_test_options']['date_format'] = 'Y-m-d';
        $GLOBALS['scrutiny_test_options']['time_format'] = 'H:i';
    });

    it('reports an unscheduled cron event as such', function () {
        expect(($this->render)())->toContain('Cron event is not scheduled');
    });

    it('reports a future run with its formatted timestamp', function () {
        $future = scheduleNextPrune(3600);

        expect(($this->render)())
            ->toContain('Next scheduled run: ' . date('Y-m-d H:i', $future))
            ->not->toContain('overdue');
    });

    // A timestamp in the past means WP-Cron has not fired — common on a quiet
    // site — rather than that the event is missing, so it gets its own
    // wording.
    it('reports a past timestamp as overdue', function () {
        scheduleNextPrune(-3600);

        expect(($this->render)())->toContain('overdue — will fire on the next site visit');
    });

    it('is shown when the pruner is enabled too', function () {
        $this->settings->setEnabled(true);
        scheduleNextPrune(3600);

        expect(($this->render)())->toContain('Next scheduled run:', 'The pruner is currently enabled.');
    });
});
