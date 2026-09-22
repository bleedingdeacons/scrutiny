<?php

declare(strict_types=1);

namespace Scrutiny\Tests\Unit\Admin;

use BleedingDeacons\WpMocks\WpState;
use Scrutiny\Admin\ScrutinyMenu;

/*
 * Tests for the top-level Scrutiny menu registrar.
 *
 * Two static, hook-only methods, both driven for real: the shared stubs record
 * add_menu_page() into WpState::$menus and remove_submenu_page() into
 * WpState::$removedSubmenus, so there is nothing here that needs mocking.
 *
 * The pairing is the point. add_menu_page() creates a submenu item mirroring
 * the parent title, and the parent slug has no callback, so that item leads
 * nowhere — removeDefaultSubmenu() strips it. If the slug constant were ever
 * changed on one side only, the removal would silently stop matching and the
 * dead item would come back; the last test here pins the two together.
 */

covers(ScrutinyMenu::class);

it('registers one top-level menu', function () {
    ScrutinyMenu::registerMenu();

    expect(WpState::$menus)->toHaveCount(1)
        ->and(WpState::$menus[0])
        ->type->toBe('menu')
        ->slug->toBe(ScrutinyMenu::MENU_SLUG)
        ->title->toBe('Scrutiny');
});

// The parent menu has to be visible to exactly the audience its child pages
// are, or an admin sees a menu whose every page refuses them.
it('gives the menu the same capability as the pages beneath it', function () {
    ScrutinyMenu::registerMenu();

    expect(ScrutinyMenu::CAPABILITY)->toBe('manage_options')
        ->and(WpState::$menus[0]['cap'])->toBe(ScrutinyMenu::CAPABILITY);
});

it('removes the auto-generated default submenu', function () {
    ScrutinyMenu::removeDefaultSubmenu();

    expect(WpState::$removedSubmenus)->toBe([[ScrutinyMenu::MENU_SLUG, ScrutinyMenu::MENU_SLUG]]);
});

// WordPress keys the auto-generated item on the parent slug, so the removal
// only matches while both halves name the same slug.
it('targets the removal at the menu that was registered', function () {
    ScrutinyMenu::registerMenu();
    ScrutinyMenu::removeDefaultSubmenu();

    [$parent, $slug] = WpState::$removedSubmenus[0];

    expect($parent)->toBe(WpState::$menus[0]['slug'])
        ->and($slug)->toBe($parent, 'the default item points at the parent slug');
});
