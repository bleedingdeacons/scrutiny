<?php

declare(strict_types=1);

/**
 * Standalone bootstrap for the Cleanup test suite.
 *
 * Provides minimal stand-ins for the WordPress and Sentinel-logger
 * functions / classes the Cleanup code touches, so tests run in a
 * vanilla PHP environment without Composer or a full WP harness:
 *
 *   - Autoloader for the Scrutiny and Unity namespaces.
 *   - get_option / update_option backed by an in-memory store.
 *   - wp_log() + Sentinel_Log_Channel test double that records
 *     every log call into a shared global so tests can assert on
 *     the emitted log stream.
 *   - sanitize_key() — a minimal stand-in for the WP function the
 *     HasLogger trait uses when deriving a default channel name.
 *
 * Tests that mutate the option store or read the log stream reset
 * the relevant globals in setUp() to avoid cross-test contamination.
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', '/var/www/html/');
}

if (!defined('SCRUTINY_PLUGIN_DIR')) {
    // One level up from tests/, i.e. the plugin root. This was dirname(..., 3),
    // which lands on wp-content: the Scrutiny mappings below happened to be
    // covered by composer's autoloader, so the only visible symptom was that
    // Unity resolved to a directory that does not exist.
    define('SCRUTINY_PLUGIN_DIR', dirname(__DIR__) . '/');
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
        // The Tests namespace must be checked before the Scrutiny
        // umbrella below — strncmp matches the longer prefix only
        // when it appears first in the iteration order. Test
        // doubles (InMemoryMemberRepository, MemberPrunerForTest)
        // live alongside the test files under tests/Unit/, in their
        // own per-class files so multiple test files can share them.
        'Scrutiny\\Tests\\' => SCRUTINY_PLUGIN_DIR . 'tests/',
        'Scrutiny\\'        => SCRUTINY_PLUGIN_DIR . 'src/',
        'Unity\\'           => $scrutinyParent . '/unity/src/',
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

// ──────────────────────────────────────────────
//  wp_log + Sentinel_Log_Channel test doubles
//
//  Scrutiny's HasLogger trait calls wp_log() lazily and stores the
//  returned channel in a typed property of class
//  Sentinel_Log_Channel. To exercise the logging code path in
//  isolation, both the function and the class are stubbed here:
//
//   - A Sentinel_Log_Channel test double records every call into a
//     shared global $GLOBALS['scrutiny_test_log_entries']. Tests can
//     read that array to assert which log messages were emitted, at
//     what level, with what context, and in what order. Tests
//     reset the array in setUp() so cross-test contamination is
//     avoided. A separate clear() method on the channel is provided
//     for the same purpose.
//
//   - wp_log() returns a singleton instance keyed by channel name,
//     mirroring how Sentinel itself memoises real channels and
//     letting the trait's own $loggerChannel cache hold a stable
//     reference across log calls within a single test.
// ──────────────────────────────────────────────

$GLOBALS['scrutiny_test_log_entries'] = [];

if (!class_exists('Sentinel_Log_Channel')) {
    /**
     * Test double for the Sentinel logger channel.
     *
     * Implements only the level methods HasLogger actually invokes
     * (info, warning, etc.) — not a full PSR-3 surface. Each call
     * appends an associative array to the shared global so tests
     * can do simple array assertions on the log stream.
     */
    class Sentinel_Log_Channel
    {
        public function __construct(public readonly string $channel) {}

        private function record(string $level, string $message, array $context): void
        {
            $GLOBALS['scrutiny_test_log_entries'][] = [
                'channel' => $this->channel,
                'level'   => $level,
                'message' => $message,
                'context' => $context,
            ];
        }

        public function emergency(string $message, array $context = []): void { $this->record('emergency', $message, $context); }
        public function alert(string $message, array $context = []): void     { $this->record('alert', $message, $context); }
        public function critical(string $message, array $context = []): void  { $this->record('critical', $message, $context); }
        public function error(string $message, array $context = []): void     { $this->record('error', $message, $context); }
        public function warning(string $message, array $context = []): void   { $this->record('warning', $message, $context); }
        public function notice(string $message, array $context = []): void    { $this->record('notice', $message, $context); }
        public function info(string $message, array $context = []): void      { $this->record('info', $message, $context); }
        public function debug(string $message, array $context = []): void     { $this->record('debug', $message, $context); }
    }
}

if (!function_exists('wp_log')) {
    /**
     * Returns a memoised channel per name so the trait's private
     * static cache holds a stable reference across log calls.
     */
    function wp_log(string $channel): Sentinel_Log_Channel
    {
        static $channels = [];
        if (!isset($channels[$channel])) {
            $channels[$channel] = new Sentinel_Log_Channel($channel);
        }
        return $channels[$channel];
    }
}

if (!function_exists('sanitize_key')) {
    /**
     * Matches WordPress's sanitize_key well enough for the trait's
     * default channel-name derivation (it lowercases and strips
     * anything other than alphanumerics, underscores, and hyphens).
     * Scrutiny classes override logChannel() with literal strings
     * so this stub is rarely hit, but it has to exist to keep
     * static analysers and lazy initialisations happy.
     */
    function sanitize_key(string $key): string
    {
        $key = strtolower($key);
        return preg_replace('/[^a-z0-9_\-]/', '', $key) ?? '';
    }
}

// ──────────────────────────────────────────────
//  WP-Cron + add_action stubs
//
//  PrunerCron interacts with WordPress's cron API. Rather than
//  depend on a WP test harness, this bootstrap provides an in-memory
//  cron queue and a recording add_action() so tests can:
//
//    - Verify wp_schedule_event was called with the right args.
//    - Verify wp_next_scheduled returns the scheduled timestamp.
//    - Verify wp_clear_scheduled_hook empties the queue.
//    - Verify add_action wired up the right callbacks.
//
//  Keys: $GLOBALS['scrutiny_test_cron_queue']  → [hook => timestamp]
//        $GLOBALS['scrutiny_test_actions']     → [[hook, callback, prio]]
//
//  Tests reset both globals in setUp() to keep cases isolated.
// ──────────────────────────────────────────────

if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}

$GLOBALS['scrutiny_test_cron_queue'] = [];
$GLOBALS['scrutiny_test_actions']    = [];

if (!function_exists('wp_schedule_event')) {
    /**
     * Records the scheduled event in the in-memory queue. Returns
     * true to indicate success — the real WP function returns
     * false on failure, but the stub never fails.
     */
    function wp_schedule_event(int $timestamp, string $recurrence, string $hook): bool
    {
        $GLOBALS['scrutiny_test_cron_queue'][$hook] = [
            'timestamp'  => $timestamp,
            'recurrence' => $recurrence,
        ];
        return true;
    }
}

if (!function_exists('wp_next_scheduled')) {
    /**
     * Returns the timestamp for the named hook, or false if it
     * isn't scheduled. Mirrors the WP signature.
     *
     * @return int|false
     */
    function wp_next_scheduled(string $hook)
    {
        return $GLOBALS['scrutiny_test_cron_queue'][$hook]['timestamp'] ?? false;
    }
}

if (!function_exists('wp_clear_scheduled_hook')) {
    function wp_clear_scheduled_hook(string $hook): void
    {
        unset($GLOBALS['scrutiny_test_cron_queue'][$hook]);
    }
}

if (!function_exists('add_action')) {
    /**
     * Records the action registration so tests can assert which
     * hooks PrunerCron::register() wired up. The real return value
     * is bool true; we match that.
     */
    function add_action(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): bool
    {
        $GLOBALS['scrutiny_test_actions'][] = [
            'hook'     => $hook,
            'callback' => $callback,
            'priority' => $priority,
        ];
        return true;
    }
}

// ──────────────────────────────────────────────
//  Post meta + wp_delete_post stubs
//
//  MemberTrashCleaner reads _wp_trash_meta_time via get_post_meta
//  and calls wp_delete_post for permanent removal. Both are stubbed
//  with simple in-memory state so tests can simulate trashed posts
//  without a WP runtime.
//
//  Keys: $GLOBALS['scrutiny_test_post_meta']  → [post_id => [meta_key => value]]
//        $GLOBALS['scrutiny_test_deleted_posts'] → [post_id, ...]
// ──────────────────────────────────────────────

$GLOBALS['scrutiny_test_post_meta']     = [];
$GLOBALS['scrutiny_test_deleted_posts'] = [];

if (!function_exists('get_post_meta')) {
    /**
     * Mirrors get_post_meta($id, $key, true) — single-value form.
     * Returns '' when the meta is absent (matching WP behaviour).
     *
     * The stub ignores the array form ($single=false) because the
     * cleaner only uses the single-value form.
     */
    function get_post_meta(int $postId, string $key = '', bool $single = false): mixed
    {
        if ($key === '') {
            return $GLOBALS['scrutiny_test_post_meta'][$postId] ?? [];
        }
        return $GLOBALS['scrutiny_test_post_meta'][$postId][$key] ?? '';
    }
}

if (!function_exists('wp_delete_post')) {
    /**
     * Records the deletion in the in-memory list. Returns true on
     * success — the real WP function returns the deleted post on
     * success or false on failure, but the cleaner only checks
     * truthiness so true / false is enough.
     *
     * Tests can flip $GLOBALS['scrutiny_test_delete_returns_false']
     * to true to simulate a failure (used by the SKIP_DELETE_FAILED
     * test).
     */
    function wp_delete_post(int $postId, bool $forceDelete = false): bool
    {
        if (!empty($GLOBALS['scrutiny_test_delete_returns_false'])) {
            return false;
        }
        $GLOBALS['scrutiny_test_deleted_posts'][] = $postId;
        return true;
    }
}

// ──────────────────────────────────────────────
//  ACF + REST stubs
//
//  The PrivacyPolicyController formatter reads ACF fields via
//  get_field() and emits its response shape from a WP_Post object
//  plus those field values. The route callbacks themselves are
//  exercised by passing a stub WP_REST_Request and asserting on the
//  returned WP_REST_Response payload — there's no need for a full
//  WP REST harness.
//
//  Keys: $GLOBALS['scrutiny_test_acf_fields']  → [post_id => [field_name => value]]
//        $GLOBALS['scrutiny_test_posts']       → [post_id => WP_Post]
// ──────────────────────────────────────────────

$GLOBALS['scrutiny_test_acf_fields'] = [];
$GLOBALS['scrutiny_test_posts']      = [];

if (!function_exists('get_field')) {
    /**
     * Returns the stored ACF value for a (field_name, post_id)
     * pair, or '' if the field has no test value set. Mirrors
     * ACF's get_field() return shape closely enough for the
     * controller's formatter, which only reads scalars.
     */
    function get_field(string $name, int $postId): mixed
    {
        return $GLOBALS['scrutiny_test_acf_fields'][$postId][$name] ?? '';
    }
}

if (!function_exists('get_post')) {
    /**
     * Returns the stub WP_Post for the given ID, or null when the
     * test hasn't registered one — matching WP's behaviour of
     * returning null for missing posts.
     */
    function get_post(int $postId)
    {
        return $GLOBALS['scrutiny_test_posts'][$postId] ?? null;
    }
}

if (!function_exists('get_posts')) {
    /**
     * Returns every post in the in-memory store, filtered by
     * post_type and (optionally) post_status. Tests register posts
     * via $GLOBALS['scrutiny_test_posts']; this stub respects the
     * insertion order, which the controller's `orderby=date` /
     * `order=DESC` then re-sorts via post_date_gmt.
     */
    function get_posts(array $args = []): array
    {
        $type   = $args['post_type']   ?? 'post';
        $status = $args['post_status'] ?? 'publish';

        $matches = [];
        foreach ($GLOBALS['scrutiny_test_posts'] as $post) {
            if ($post->post_type !== $type) {
                continue;
            }
            if ($status !== 'any' && $post->post_status !== $status) {
                continue;
            }
            $matches[] = $post;
        }

        // The controller asks for orderby=date / order=DESC; honour it
        // so test fixtures can rely on the same ordering the real WP
        // call would produce.
        if (($args['orderby'] ?? '') === 'date') {
            usort($matches, function ($a, $b) use ($args) {
                $dir = (($args['order'] ?? 'DESC') === 'ASC') ? 1 : -1;
                return $dir * strcmp((string) $a->post_date_gmt, (string) $b->post_date_gmt);
            });
        }

        return $matches;
    }
}

if (!function_exists('mysql2date')) {
    /**
     * Minimal stand-in for mysql2date('c', $gmt, false) — the only
     * form the controller calls. Returns the given GMT timestamp
     * formatted as ISO 8601 with a +00:00 offset, matching what WP
     * produces for the 'c' format with $translate=false.
     */
    function mysql2date(string $format, string $date, bool $translate = true): string
    {
        if ($date === '') {
            return '';
        }
        $ts = strtotime($date . ' UTC');
        if ($ts === false) {
            return '';
        }
        // Force UTC offset to +00:00 to mirror the GMT input.
        return gmdate('Y-m-d\TH:i:s', $ts) . '+00:00';
    }
}

if (!function_exists('rest_ensure_response')) {
    /**
     * Wraps a payload in a WP_REST_Response if it isn't one
     * already — same contract as the WP function.
     */
    function rest_ensure_response(mixed $data): \WP_REST_Response
    {
        if ($data instanceof \WP_REST_Response) {
            return $data;
        }
        return new \WP_REST_Response($data, 200);
    }
}

if (!function_exists('rest_sanitize_boolean')) {
    function rest_sanitize_boolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            $lower = strtolower($value);
            if (in_array($lower, ['true', '1', 'yes', 'on'], true)) {
                return true;
            }
            if (in_array($lower, ['false', '0', 'no', 'off', ''], true)) {
                return false;
            }
        }
        return (bool) $value;
    }
}

if (!function_exists('absint')) {
    function absint(mixed $value): int
    {
        return abs((int) $value);
    }
}

if (!function_exists('register_rest_route')) {
    /**
     * Records each route registration in a global so tests can
     * assert which routes the controller wired up and with what
     * options (methods, permission_callback, args, etc.).
     */
    function register_rest_route(string $namespace, string $route, array $args = [], bool $override = false): bool
    {
        $GLOBALS['scrutiny_test_rest_routes'][] = [
            'namespace' => $namespace,
            'route'     => $route,
            'args'      => $args,
        ];
        return true;
    }
}

if (!function_exists('__return_true')) {
    function __return_true(): bool
    {
        return true;
    }
}

$GLOBALS['scrutiny_test_rest_routes'] = [];

// ──────────────────────────────────────────────
//  Shortcode + escaping stubs
//
//  PrivacyPolicyShortcode registers a tag via add_shortcode() and
//  passes its rendered fields through esc_html() / wp_kses_post().
//  Tests assert on the registered tag and on the rendered HTML, so
//  each stub records exactly what the production function would
//  produce — minus the WP-specific filter chain we don't have a
//  harness for.
//
//  Keys: $GLOBALS['scrutiny_test_shortcodes'] → [tag => callable]
// ──────────────────────────────────────────────

$GLOBALS['scrutiny_test_shortcodes'] = [];

if (!function_exists('add_shortcode')) {
    /**
     * Records the (tag, callback) pair so tests can assert on the
     * registration. Mirrors WP's behaviour of overwriting an
     * earlier registration with the same tag.
     */
    function add_shortcode(string $tag, callable $callback): void
    {
        $GLOBALS['scrutiny_test_shortcodes'][$tag] = $callback;
    }
}

if (!function_exists('esc_html')) {
    /**
     * Minimal stand-in for esc_html() — escapes the four HTML
     * specials the rendered scalar fields might contain. WP's real
     * implementation also does encoding-aware UTF-8 sanitisation,
     * but for the value space the shortcode emits (contact name,
     * email, version, ISO timestamp) htmlspecialchars() is a faithful
     * approximation.
     */
    function esc_html(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}

if (!function_exists('wp_kses_post')) {
    /**
     * Stand-in for wp_kses_post(), which strips dangerous tags
     * while preserving the standard "post content" tag set. The
     * real implementation runs a full whitelist; for the shortcode
     * tests we only need to verify (a) safe markup passes through
     * intact, and (b) clearly-dangerous markup (script/onerror) is
     * removed. A minimal regex-based filter covers both.
     */
    function wp_kses_post(string $html): string
    {
        // Drop <script>…</script> blocks entirely.
        $html = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html) ?? $html;
        // Drop inline event handlers like onclick="…" / onerror='…'.
        $html = preg_replace('#\son\w+\s*=\s*("[^"]*"|\'[^\']*\')#i', '', $html) ?? $html;
        return $html;
    }
}

if (!class_exists('WP_Post')) {
    /**
     * Test double for the WP_Post class. Only the properties the
     * controller actually reads are typed; everything else is a
     * loose public field so individual tests can attach extras
     * without ceremony.
     */
    class WP_Post
    {
        public int    $ID = 0;
        public string $post_title = '';
        public string $post_type = '';
        public string $post_status = 'publish';
        public string $post_modified_gmt = '';
        public string $post_date_gmt = '';

        public function __construct(array $props = [])
        {
            foreach ($props as $key => $value) {
                $this->{$key} = $value;
            }
        }
    }
}

if (!class_exists('WP_REST_Request')) {
    /**
     * Test double for WP_REST_Request. Stores a route-param map
     * passed to the constructor and exposes get_param() — the only
     * method the controller calls.
     */
    class WP_REST_Request
    {
        public function __construct(private array $params = []) {}

        public function get_param(string $key): mixed
        {
            return $this->params[$key] ?? null;
        }

        public function set_param(string $key, mixed $value): void
        {
            $this->params[$key] = $value;
        }
    }
}

if (!class_exists('WP_REST_Response')) {
    /**
     * Test double for WP_REST_Response. Records the payload and
     * status passed in; tests assert on these fields directly.
     */
    class WP_REST_Response
    {
        public function __construct(
            public mixed $data = null,
            public int $status = 200
        ) {}

        public function get_status(): int
        {
            return $this->status;
        }

        public function get_data(): mixed
        {
            return $this->data;
        }
    }
}

if (!class_exists('WP_REST_Server')) {
    /**
     * Stand-in for the routing-method constants the controller uses.
     * The real class exposes these as class constants; tests only
     * need the literal strings.
     */
    class WP_REST_Server
    {
        public const READABLE = 'GET';
    }
}

