<?php

declare(strict_types=1);

/**
 * Plugin Name: Scrutiny
 * Description: GDPR-compliant audit logging and personal data obscuring for Unity.
 * Version: 1.1.0
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Author: The Bleeding Deacons
 * Author URI: thebleedingdeacons@gmail.com
 * License: MIT (Modified)
 */

if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
if (!function_exists('get_plugin_data')) {
    require_once(ABSPATH . 'wp-admin/includes/plugin.php');
}
$scrutiny_plugin_data = get_plugin_data(__FILE__, false, false);
define('SCRUTINY_VERSION', $scrutiny_plugin_data['Version']);
define('SCRUTINY_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SCRUTINY_PLUGIN_URL', plugin_dir_url(__FILE__));

// Autoloader for Scrutiny namespace
spl_autoload_register(function ($class) {
    try {
        $prefix = 'Scrutiny\\';
        $base_dir = SCRUTINY_PLUGIN_DIR . 'src/';

        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            return;
        }

        $relative_class = substr($class, $len);
        $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

        if (file_exists($file)) {
            require $file;
        }
    } catch (\Exception $e) {
        error_log('Scrutiny Autoloader Error: ' . $e->getMessage());
    } catch (\Throwable $e) {
        error_log('Scrutiny Autoloader Fatal Error: ' . $e->getMessage());
    }
});

/**
 * Get the Scrutiny dependency container (Unity's container)
 *
 * @return \Unity\Core\DependencyContainer
 * @throws \RuntimeException If Scrutiny is not initialized
 */
function scrutiny(): \Unity\Core\DependencyContainer {
    return \Scrutiny\Plugin::getContainer();
}

// Initialize the plugin after Amber is loaded (so we can intercept its UI)
add_action('amber_loaded', function($container) {
    try {
        if (!class_exists('Scrutiny\Plugin')) {
            throw new \Exception('Scrutiny\Plugin class not found. Check that Plugin.php exists in the src/ directory.');
        }

        \Scrutiny\Plugin::init($container);

        do_action('scrutiny_loaded', \Scrutiny\Plugin::getContainer());

    } catch (\Exception $e) {
        error_log('Scrutiny Plugin Initialization Error: ' . $e->getMessage());
        error_log('Scrutiny Plugin Stack Trace: ' . $e->getTraceAsString());

        if (is_admin()) {
            add_action('admin_notices', function() use ($e) {
                $message = sprintf(
                    '<strong>Scrutiny Plugin Error:</strong> %s',
                    esc_html($e->getMessage())
                );
                echo '<div class="notice notice-error is-dismissible"><p>' . $message . '</p></div>';
            });
        }

        return;

    } catch (\Throwable $e) {
        error_log('Scrutiny Plugin Fatal Error: ' . $e->getMessage());
        error_log('Scrutiny Plugin Stack Trace: ' . $e->getTraceAsString());

        if (is_admin()) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error is-dismissible"><p><strong>Scrutiny Plugin Fatal Error:</strong> Plugin failed to load. Check error logs.</p></div>';
            });
        }

        return;
    }
}, 10);

// Show admin notice if Unity/Amber are not available
add_action('admin_notices', function() {
    if (!function_exists('unity') && !did_action('unity_loaded')) {
        echo '<div class="notice notice-warning is-dismissible"><p><strong>Scrutiny:</strong> This plugin requires the Unity and Amber plugins to be installed and activated.</p></div>';
    }
});

// Plugin activation hook - create database table
register_activation_hook(__FILE__, function () {
    if (!class_exists('Scrutiny\Plugin')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            esc_html__('Scrutiny requires the Unity and Amber plugins to be installed and activated.', 'scrutiny'),
            esc_html__('Plugin Activation Error', 'scrutiny'),
            ['back_link' => true]
        );
    }

    \Scrutiny\Plugin::activate();
});

// Plugin deactivation hook
register_deactivation_hook(__FILE__, function () {
    // Cleanup code here if needed
});
