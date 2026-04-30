<?php

declare(strict_types=1);

/**
 * Standalone bootstrap for the Cleanup test suite.
 *
 * The main Scrutiny suite uses WP_Mock and Composer's autoloader. The
 * Cleanup tests deliberately avoid both: the pruner is constructed
 * with a hand-rolled fake MemberRepository and overrides trashMember()
 * in a test subclass, so no WordPress functions are touched.
 *
 * That keeps these tests runnable in a vanilla PHP environment, with
 * just an autoloader for the Scrutiny and Unity namespaces.
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', '/var/www/html/');
}

if (!defined('SCRUTINY_PLUGIN_DIR')) {
    define('SCRUTINY_PLUGIN_DIR', dirname(__DIR__, 3) . '/');
}

if (!defined('SCRUTINY_VERSION')) {
    define('SCRUTINY_VERSION', '0.0.0-test');
}

// Minimal autoloader covering the Scrutiny source tree and the Unity
// interfaces the pruner depends on. Mirrors the structure of the
// production autoloader but rooted at the local checkout paths.
spl_autoload_register(function (string $class): void {
    // Unity is expected to live as a sibling plugin checkout. In the
    // CI/dev layout that ships these plugins together, both repos sit
    // under the same parent folder, so deriving Unity's path from
    // SCRUTINY_PLUGIN_DIR keeps the bootstrap robust to absolute path
    // changes.
    $scrutinyParent = dirname(rtrim(SCRUTINY_PLUGIN_DIR, '/'));

    $map = [
        'Scrutiny\\' => SCRUTINY_PLUGIN_DIR . 'src/',
        'Unity\\'    => $scrutinyParent . '/unity/src/',
    ];

    foreach ($map as $prefix => $baseDir) {
        if (strncmp($prefix, $class, strlen($prefix)) === 0) {
            $relative = substr($class, strlen($prefix));
            $file     = $baseDir . str_replace('\\', '/', $relative) . '.php';
            if (file_exists($file)) {
                require $file;
            }
            return;
        }
    }
});

// ──────────────────────────────────────────────
//  WordPress option stubs
//
//  PrunerSettings is a thin wrapper over get_option / update_option.
//  Rather than pulling in a full WP test harness, the suite stubs
//  these two functions with an in-memory store. Tests can reset the
//  store between cases via $GLOBALS['scrutiny_test_options'] = [].
// ──────────────────────────────────────────────

$GLOBALS['scrutiny_test_options'] = [];

if (!function_exists('get_option')) {
    function get_option(string $key, mixed $default = false): mixed
    {
        return $GLOBALS['scrutiny_test_options'][$key] ?? $default;
    }
}

if (!function_exists('update_option')) {
    function update_option(string $key, mixed $value): bool
    {
        $GLOBALS['scrutiny_test_options'][$key] = $value;
        return true;
    }
}
