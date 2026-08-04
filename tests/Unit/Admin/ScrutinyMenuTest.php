<?php

declare(strict_types=1);

namespace Scrutiny\Tests\Unit\Admin;

use BleedingDeacons\WpMocks\WpState;
use Scrutiny\Admin\ScrutinyMenu;
use Scrutiny\Tests\TestCase;

/**
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
 *
 * @covers \Scrutiny\Admin\ScrutinyMenu
 */
final class ScrutinyMenuTest extends TestCase
{
    /** @test */
    public function it_registers_one_top_level_menu(): void
    {
        ScrutinyMenu::registerMenu();

        $this->assertCount(1, WpState::$menus);
        $this->assertSame('menu', WpState::$menus[0]['type']);
        $this->assertSame(ScrutinyMenu::MENU_SLUG, WpState::$menus[0]['slug']);
        $this->assertSame('Scrutiny', WpState::$menus[0]['title']);
    }

    /**
     * The parent menu has to be visible to exactly the audience its child
     * pages are, or an admin sees a menu whose every page refuses them.
     *
     * @test
     */
    public function the_menu_capability_matches_the_pages_beneath_it(): void
    {
        ScrutinyMenu::registerMenu();

        $this->assertSame('manage_options', ScrutinyMenu::CAPABILITY);
        $this->assertSame(ScrutinyMenu::CAPABILITY, WpState::$menus[0]['cap']);
    }

    /** @test */
    public function it_removes_the_auto_generated_default_submenu(): void
    {
        ScrutinyMenu::removeDefaultSubmenu();

        $this->assertSame(
            [[ScrutinyMenu::MENU_SLUG, ScrutinyMenu::MENU_SLUG]],
            WpState::$removedSubmenus
        );
    }

    /**
     * WordPress keys the auto-generated item on the parent slug, so the
     * removal only matches while both halves name the same slug.
     *
     * @test
     */
    public function the_removal_targets_the_menu_that_was_registered(): void
    {
        ScrutinyMenu::registerMenu();
        ScrutinyMenu::removeDefaultSubmenu();

        [$parent, $slug] = WpState::$removedSubmenus[0];

        $this->assertSame(WpState::$menus[0]['slug'], $parent);
        $this->assertSame($parent, $slug, 'the default item points at the parent slug');
    }
}
