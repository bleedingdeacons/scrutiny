<?php

declare(strict_types=1);

/**
 * Plugin Name: Scrutiny
 * Description: GDPR-compliant audit logging and personal data obscuring for Unity. Required by Amber.
 * Version: 1.18.0
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * GitHub Plugin URI: https://github.com/thebleedingdeacons/scrutiny
 * GitHub Branch: main
 * Author: The Bleeding Deacons
 * Author URI: https://github.com/bleedingdeacons/scrutiny
 * Contact: thebleedingdeacons@gmail.com
 * License: MIT (Modified)
 */

if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
if (!function_exists('get_plugin_data')) {
    if (file_exists(ABSPATH . 'wp-admin/includes/plugin.php')) {
        require_once(ABSPATH . 'wp-admin/includes/plugin.php');
    }
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
        function_exists('wp_log')
            ? wp_log('scrutiny')->error('Scrutiny Autoloader Error: ' . $e->getMessage(), ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()])
            : error_log('Scrutiny Autoloader Error: ' . $e->getMessage());
    } catch (\Throwable $e) {
        function_exists('wp_log')
            ? wp_log('scrutiny')->critical('Scrutiny Autoloader Fatal Error: ' . $e->getMessage(), ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()])
            : error_log('Scrutiny Autoloader Fatal Error: ' . $e->getMessage());
    }
});

/**
 * Get the Scrutiny dependency container (Unity's container)
 *
 * @return \Psr\Container\ContainerInterface
 * @throws \RuntimeException If Scrutiny is not initialized
 */
function scrutiny(): \Psr\Container\ContainerInterface {
    return \Scrutiny\Plugin::getContainer();
}

// Initialize the plugin after Unity is loaded (BEFORE Amber, so filters are ready)
// This ensures data obscuring filters are in place before any ACF fields are rendered
add_action('unity/loaded', function($unityContainer) {
    try {
        if (!class_exists('Scrutiny\Plugin')) {
            throw new \Exception('Scrutiny\Plugin class not found. Check that Plugin.php exists in the src/ directory.');
        }

        \Scrutiny\Plugin::init($unityContainer);

        do_action('scrutiny_loaded', \Scrutiny\Plugin::getContainer());

    } catch (\Exception $e) {
        function_exists('wp_log')
            ? wp_log('scrutiny')->error('Scrutiny Plugin Initialization Error: ' . $e->getMessage(), ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()])
            : error_log('Scrutiny Plugin Initialization Error: ' . $e->getMessage());

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
        function_exists('wp_log')
            ? wp_log('scrutiny')->critical('Scrutiny Plugin Fatal Error: ' . $e->getMessage(), ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()])
            : error_log('Scrutiny Plugin Fatal Error: ' . $e->getMessage());

        if (is_admin()) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error is-dismissible"><p><strong>Scrutiny Plugin Fatal Error:</strong> Plugin failed to load. Check error logs.</p></div>';
            });
        }

        return;
    }
}, 5); // Priority 5 - before Amber (which uses priority 10)

// Show admin notice if Unity is not available
add_action('admin_notices', function() {
    if (!function_exists('unity') && !did_action('unity/loaded')) {
        echo '<div class="notice notice-warning is-dismissible"><p><strong>Scrutiny:</strong> This plugin requires the Unity plugin to be installed and activated.</p></div>';
    }
});

// Plugin activation hook - create database table
register_activation_hook(__FILE__, function () {
    if (!class_exists('Scrutiny\Plugin')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            esc_html__('Scrutiny requires the Unity plugin to be installed and activated.', 'scrutiny'),
            esc_html__('Plugin Activation Error', 'scrutiny'),
            ['back_link' => true]
        );
    }

    \Scrutiny\Plugin::activate();
});

// Plugin deactivation hook
register_deactivation_hook(__FILE__, function () {
    if (class_exists('Scrutiny\Plugin')) {
        \Scrutiny\Plugin::deactivate();
    }
});