<?php

declare(strict_types=1);

/**
 * PHPUnit Bootstrap File for Scrutiny
 */

// Composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// Initialize WP_Mock
WP_Mock::bootstrap();

// Define WordPress constants if not already defined
if (!defined('ABSPATH')) {
    define('ABSPATH', '/var/www/html/');
}

if (!defined('SCRUTINY_PLUGIN_DIR')) {
    define('SCRUTINY_PLUGIN_DIR', dirname(__DIR__) . '/');
}

if (!defined('SCRUTINY_PLUGIN_URL')) {
    define('SCRUTINY_PLUGIN_URL', 'http://example.com/wp-content/plugins/scrutiny/');
}

if (!defined('SCRUTINY_VERSION')) {
    define('SCRUTINY_VERSION', '1.0.0');
}
