<?php

declare(strict_types=1);

namespace Scrutiny\Admin;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use function add_menu_page;
use function remove_submenu_page;

/**
 * Scrutiny Top-Level Menu
 *
 * Registers a single top-level "Scrutiny" admin menu under which all
 * Scrutiny configuration pages live. Currently that's just Pruner
 * Settings; future pages (audit-retention configuration, "Run pruner
 * now" button, etc.) attach to this same menu via add_submenu_page
 * with the slug exposed as ScrutinyMenu::MENU_SLUG.
 *
 * Why a dedicated class:
 *   The menu is a Scrutiny-wide concern, not a property of any one
 *   page. Putting registration here means a new admin screen only
 *   has to know the parent slug — it doesn't have to coordinate
 *   ordering, the menu icon, or the visible default-child label
 *   with every other screen. This mirrors the pattern Amber uses
 *   for its own Intergroup menu registrar.
 *
 * Why static:
 *   The class is hook-only and holds no state. A static method
 *   sidesteps an unnecessary container registration; Plugin.php
 *   wires it into admin_menu directly.
 *
 * Note on the default submenu label:
 *   WordPress automatically creates a submenu item that mirrors the
 *   top-level menu title ("Scrutiny") and points at the parent
 *   slug. Because the parent slug has no callback of its own, that
 *   default item is removed in registerMenu() once page classes
 *   have had a chance to attach their own submenus on the same
 *   admin_menu hook (priority 999, which fires after the page
 *   classes' priority-20 registrations).
 */
class ScrutinyMenu
{
    /**
     * Slug used as the parent for all Scrutiny submenu pages.
     *
     * Page classes register against this constant so renaming the
     * top-level menu later is a one-line change.
     */
    public const MENU_SLUG = 'scrutiny';

    /**
     * Capability required to see the Scrutiny menu.
     *
     * Matches the capability already used by every Scrutiny admin
     * page (AuditLogAdmin, MemberPrunerAdmin) so the visibility of
     * the parent menu stays in lock-step with its children.
     */
    public const CAPABILITY = 'manage_options';

    /**
     * Register the top-level Scrutiny admin menu.
     *
     * Intended to be called on the 'admin_menu' hook at the default
     * priority (10) so it runs before page classes register their
     * submenus at priority 20.
     */
    public static function registerMenu(): void
    {
        add_menu_page(
            'Scrutiny',
            'Scrutiny',
            self::CAPABILITY,
            self::MENU_SLUG,
            // No callback: the parent slug exists only to anchor
            // submenus. Any direct navigation to the parent page
            // will be handled by WordPress redirecting to the first
            // submenu, which is the desired behaviour.
            '',
            'dashicons-visibility',
            80
        );
    }

    /**
     * Remove the auto-generated default submenu item that mirrors
     * the parent menu's title.
     *
     * WordPress adds a "Scrutiny" submenu pointing at the parent
     * slug whenever you call add_menu_page(). With no callback on
     * that slug, the item leads nowhere useful, so we strip it
     * once submenus have been registered. This must run after
     * page classes' admin_menu callbacks (priority 20), so we hook
     * at priority 999.
     */
    public static function removeDefaultSubmenu(): void
    {
        remove_submenu_page(self::MENU_SLUG, self::MENU_SLUG);
    }
}
