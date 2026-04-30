<?php

declare(strict_types=1);

namespace Scrutiny\Admin;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Scrutiny\Cleanup\PrunerSettings;
use function add_action;
use function add_query_arg;
use function add_submenu_page;
use function admin_url;
use function check_admin_referer;
use function current_user_can;
use function esc_attr;
use function esc_html;
use function sanitize_text_field;
use function wp_nonce_field;
use function wp_safe_redirect;
use function wp_unslash;

/**
 * Member Pruner Settings Page
 *
 * Provides an admin page under the Intergroup menu for configuring
 * the two thresholds that drive Scrutiny\Cleanup\MemberPruner:
 *
 *   - Rotation grace months — how long after an officer's rotation
 *     date they remain protected from the officer pass.
 *   - Inactivity months — how long a home-group non-GSR member can
 *     go un-touched before the home-group pass treats them as stale.
 *
 * The page only updates settings; it does not run the pruner. That
 * keeps a config edit and a destructive sweep as two separate,
 * deliberate clicks. Sweep triggering belongs in a future "Run
 * pruner" button that reads these values via PrunerSettings.
 *
 * Mirrors the AuditLogAdmin conventions in Scrutiny: parent menu
 * 'intergroup', manage_options capability, admin_menu priority 20,
 * form handling on admin_init so a successful save can wp_safe_redirect
 * (post/redirect/get) before any output is sent.
 */
class MemberPrunerAdmin
{
    public const MENU_SLUG = 'scrutiny-pruner-settings';
    public const CAPABILITY = 'manage_options';
    public const NONCE_ACTION = 'scrutiny_pruner_settings_save';
    public const NONCE_FIELD = '_scrutiny_pruner_nonce';

    /**
     * Reasonable upper bound to stop accidental three-digit values
     * from being persisted. Twelve years (144 months) is far beyond
     * any realistic intergroup rotation or inactivity period and
     * still sits comfortably inside int range arithmetic.
     */
    private const MAX_MONTHS = 144;

    private PrunerSettings $settings;

    public function __construct(PrunerSettings $settings)
    {
        $this->settings = $settings;

        add_action('admin_menu', [$this, 'registerMenu'], 20);
        add_action('admin_init', [$this, 'handleSave']);
    }

    /**
     * Register the Pruner Settings submenu page under the Intergroup menu.
     */
    public function registerMenu(): void
    {
        add_submenu_page(
            'intergroup',
            'Pruner Settings',
            'Pruner Settings',
            self::CAPABILITY,
            self::MENU_SLUG,
            [$this, 'renderPage']
        );
    }

    /**
     * Handle the settings form submission.
     *
     * Runs on admin_init so the redirect on success happens before any
     * output is sent. Bails early if the request isn't ours, so we
     * don't accidentally consume nonces or read POST values from
     * unrelated admin pages.
     */
    public function handleSave(): void
    {
        // Not our page → nothing to do.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (!isset($_POST[self::NONCE_FIELD])) {
            return;
        }

        check_admin_referer(self::NONCE_ACTION, self::NONCE_FIELD);

        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'scrutiny'));
        }

        $rotation = $this->readMonthsField('rotation_grace_months');
        $inactivity = $this->readMonthsField('inactivity_months');

        $this->settings->setRotationGraceMonths($rotation);
        $this->settings->setInactivityMonths($inactivity);

        // Post/redirect/get so refreshing the page doesn't re-submit.
        $redirectUrl = add_query_arg(
            [
                'page'    => self::MENU_SLUG,
                'updated' => '1',
            ],
            admin_url('admin.php')
        );

        wp_safe_redirect($redirectUrl);
        exit;
    }

    /**
     * Render the settings page.
     */
    public function renderPage(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to access this page.', 'scrutiny'));
            return;
        }

        $rotation   = $this->settings->getRotationGraceMonths();
        $inactivity = $this->settings->getInactivityMonths();

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $justUpdated = isset($_GET['updated']) && $_GET['updated'] === '1';

        ?>
        <div class="wrap">
            <h1>Scrutiny – Pruner Settings</h1>

            <p class="description">
                Thresholds used by the Scrutiny member pruner. The pruner trashes
                rotated officers (when a successor exists) and home-group members
                who are not the GSR and have been inactive for the configured
                period. Trashing is recoverable from the standard WordPress
                Trash; this page only configures the cut-off values, it does not
                run the pruner.
            </p>

            <?php if ($justUpdated) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><strong>Settings saved.</strong></p>
                </div>
            <?php endif; ?>

            <form method="post" action="">
                <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD); ?>

                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row">
                                <label for="scrutiny-rotation-grace-months">
                                    Rotation grace (months)
                                </label>
                            </th>
                            <td>
                                <input
                                    type="number"
                                    min="0"
                                    max="<?php echo esc_attr((string) self::MAX_MONTHS); ?>"
                                    step="1"
                                    id="scrutiny-rotation-grace-months"
                                    name="rotation_grace_months"
                                    value="<?php echo esc_attr((string) $rotation); ?>"
                                    class="small-text"
                                >
                                <p class="description">
                                    An officer becomes a candidate for trashing once their
                                    rotation date is older than this many months — but only
                                    if another member holds the same position with a later
                                    rotation date. Lone incumbents are never trashed.
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="scrutiny-inactivity-months">
                                    Inactivity threshold (months)
                                </label>
                            </th>
                            <td>
                                <input
                                    type="number"
                                    min="0"
                                    max="<?php echo esc_attr((string) self::MAX_MONTHS); ?>"
                                    step="1"
                                    id="scrutiny-inactivity-months"
                                    name="inactivity_months"
                                    value="<?php echo esc_attr((string) $inactivity); ?>"
                                    class="small-text"
                                >
                                <p class="description">
                                    A home-group member who is not the GSR and whose record
                                    has not been updated for this many months becomes a
                                    candidate for trashing. Officers are excluded from this
                                    rule — they are governed by the rotation grace above.
                                </p>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <p class="submit">
                    <button type="submit" class="button button-primary">
                        Save Settings
                    </button>
                </p>
            </form>
        </div>

        <style>
            /* Scope styles to this admin page only. */
            .wrap .form-table th { width: 240px; }
            .wrap .form-table input.small-text { width: 6em; }
        </style>
        <?php
    }

    /**
     * Pull a months value from $_POST, parse it as an integer, and
     * clamp into the [0, MAX_MONTHS] range.
     *
     * Returns 0 if the field is missing or non-numeric. We deliberately
     * accept missing-field as zero rather than rejecting the submission:
     * a user who wipes the input and saves is expressing "no grace
     * period", which the pruner already handles correctly.
     */
    private function readMonthsField(string $name): int
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified by handleSave()
        if (!isset($_POST[$name])) {
            return 0;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified by handleSave()
        $raw = sanitize_text_field(wp_unslash($_POST[$name]));
        $value = (int) $raw;

        if ($value < 0) {
            return 0;
        }
        if ($value > self::MAX_MONTHS) {
            return self::MAX_MONTHS;
        }

        return $value;
    }
}
