<?php

declare(strict_types=1);

namespace Scrutiny\Admin\Members;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Unity\Core\Interfaces\Configuration;
use Unity\Members\Interfaces\Member;

use Scrutiny\Privacy\PersonalDataPolicy;

use function add_action;
use function current_user_can;
use function get_current_screen;
use function plugin_dir_url;
use function wp_enqueue_script;
use function wp_localize_script;

/**
 * Manages the personal data fields (Personal Email and Mobile Number)
 * on the member edit screen. Adds "Clear / Undo" buttons, enforces
 * edit-capability restrictions, and prevents accidental data loss.
 */
class PersonalDataMinder
{
    private readonly array $memberConfig;

    public function __construct(Configuration $configuration)
    {
        $this->memberConfig = $configuration->getConfig(Member::class);

        add_action('acf/input/admin_enqueue_scripts', [$this, 'enqueueScripts']);
    }

    /**
     * Enqueue the personal-data-manager JS only on the member edit screen.
     */
    public function enqueueScripts(): void
    {
        $screen = get_current_screen();

        if (!$screen || $screen->post_type !== $this->memberConfig['POST_TYPE']) {
            return;
        }

        wp_enqueue_script(
            'scrutiny-personal-data-minder',
            plugin_dir_url(dirname(__DIR__, 3) . '/scrutiny.php') . 'assets/js/personal-data-minder.js',
            ['jquery', 'acf-input'],
            defined('SCRUTINY_VERSION') ? SCRUTINY_VERSION : '1.0.0',
            true
        );

        wp_localize_script('scrutiny-personal-data-minder', 'scrutinyPersonalData', [
            'canEdit' => current_user_can(PersonalDataPolicy::EDIT_CAPABILITY),
            'canView' => current_user_can(PersonalDataPolicy::VIEW_CAPABILITY),
        ]);
    }
}
