<?php

declare(strict_types=1);

namespace Scrutiny\Admin;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use function add_action;
use function add_submenu_page;
use function admin_url;
use function esc_js;
use function esc_url;
use function plugins_url;

/**
 * Adds a "Help" submenu under the Scrutiny menu that opens the standalone
 * Scrutiny user guide (assets/docs/scrutiny.html).
 *
 * The guide was previously reachable only from the Help button in the Audit
 * Log page heading — which meant you had to already be on that screen to find
 * the documentation, and the Pruner Settings screen had no route to it at all.
 *
 * Mirrors Amber's and Trusted's HelpPage: clicking Help is intercepted and the
 * guide is opened in a named browser tab, with the current admin URL passed as
 * `?back=`. The guide's back button then refocuses that same tab via its window
 * name rather than reloading it, so the admin page keeps its scroll position.
 * The window names match the ones the Audit Log heading button already uses, so
 * both routes share a single guide tab.
 */
final class HelpPage
{
    /** Submenu page slug. */
    public const SLUG = 'scrutiny-help';

    /**
     * Capability required to see the Help submenu.
     *
     * Matches ScrutinyMenu and both admin pages, so Help is visible to exactly
     * the people who can reach the screens it documents.
     */
    public const CAPABILITY = ScrutinyMenu::CAPABILITY;

    /** Window name given to the admin tab so the guide can refocus it. */
    private const ADMIN_WINDOW = 'scrutiny-admin';

    /** Window name the guide tab opens under, so repeat clicks reuse it. */
    private const HELP_WINDOW = 'scrutiny-help';

    private function helpUrl(): string
    {
        return plugins_url('assets/docs/scrutiny.html', dirname(__DIR__, 2) . '/scrutiny.php');
    }

    /**
     * Register the Help submenu and the footer script that intercepts its
     * click. Hooked on `admin_menu` at a late priority so Help sits last in
     * the Scrutiny submenu — but still ahead of ScrutinyMenu's priority-999
     * cleanup, which strips the default child item.
     */
    public function register(): void
    {
        add_submenu_page(
            ScrutinyMenu::MENU_SLUG,
            'Help',
            'Help',
            self::CAPABILITY,
            self::SLUG,
            [$this, 'render']
        );

        add_action('admin_footer', [$this, 'enqueueHelpTabScript']);
    }

    /**
     * Fallback page, shown only if the footer script does not intercept the
     * click (e.g. JavaScript disabled). Offers a direct link to the guide.
     */
    public function render(): void
    {
        echo '<div class="wrap">';
        echo '<h1>Scrutiny Help</h1>';
        echo '<p>Open the Scrutiny user guide:</p>';
        echo '<p><a class="button button-primary" target="_blank" rel="noopener" href="'
            . esc_url($this->helpUrl()) . '">Open the guide</a></p>';
        echo '</div>';
    }

    /**
     * Intercept the Help submenu click and open the guide in a named tab,
     * passing the current admin URL as `?back=`. Naming the admin tab lets the
     * guide refocus it on "back" without a reload. window.open() inside a click
     * handler is a user gesture, so browsers don't treat it as a popup.
     */
    public function enqueueHelpTabScript(): void
    {
        $adminUrl = admin_url('admin.php?page=' . self::SLUG);
        ?>
        <script>
            (function () {
                var link = document.querySelector('a[href="<?php echo esc_js($adminUrl); ?>"]');
                if (!link) {
                    link = document.querySelector('a[href*="page=<?php echo esc_js(self::SLUG); ?>"]');
                }
                if (!link) return;
                link.addEventListener('click', function (e) {
                    e.preventDefault();
                    window.name = '<?php echo esc_js(self::ADMIN_WINDOW); ?>';
                    var helpUrl = '<?php echo esc_js($this->helpUrl()); ?>' + '?back=' + encodeURIComponent(window.location.href);
                    var existing = window.open('', '<?php echo esc_js(self::HELP_WINDOW); ?>');
                    if (!existing) {
                        // A popup blocker or extension refused the window.
                        // preventDefault() has already run, so without this the
                        // Help link would do nothing at all — not even reach the
                        // fallback page. Open the guide in place instead.
                        window.location.href = helpUrl;
                        return;
                    }
                    try {
                        if (existing && existing.location && existing.location.href && existing.location.href !== 'about:blank') {
                            existing.focus();
                            return;
                        }
                    } catch (ex) {}
                    existing.location.href = helpUrl;
                });
            })();
        </script>
        <?php
    }
}
