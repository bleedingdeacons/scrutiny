<?php

declare(strict_types=1);

namespace Scrutiny\Rest;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use function add_action;
use function get_field;
use function get_post;
use function get_posts;
use function register_rest_route;
use function rest_ensure_response;

/**
 * Privacy Policy REST Controller
 *
 * Exposes the `privacy-policy` custom post type and its `Gdpr` ACF field
 * group as a read-only REST resource. The CPT itself is registered with
 * `show_in_rest = false` (it is not part of the default editorial
 * surface), so this controller provides a deliberate, narrow window
 * onto the published policy text for frontends and other services that
 * need to display it.
 *
 * Routes:
 *
 *   GET /scrutiny/v1/privacy-policies
 *       List all published privacy policies, newest first. Supports
 *       ?active=true to return only those flagged as the currently
 *       active policy.
 *
 *   GET /scrutiny/v1/privacy-policies/active
 *       Convenience route returning the single most-recent active
 *       policy (404 if none is marked active). This is the route a
 *       frontend that just wants to render "the" privacy notice
 *       should hit.
 *
 *   GET /scrutiny/v1/privacy-policies/{id}
 *       Fetch a single policy by post ID.
 *
 * All routes are public — privacy policies are by their nature meant
 * to be readable by anyone visiting the site. Read-only: no POST,
 * PUT, PATCH, or DELETE is registered.
 *
 * The response shape strips the redundant `gdpr-` prefix from ACF
 * field names and converts kebab-case to snake_case so the JSON
 * follows REST conventions:
 *
 *   {
 *     "id":       123,
 *     "title":    "Privacy Policy",
 *     "version":  "2.1",
 *     "active":   true,
 *     "policy":   "<p>… formatted HTML …</p>",
 *     "modified": "2026-05-03T10:15:00+00:00"
 *   }
 *
 * The `policy` field is the WYSIWYG content already passed through
 * ACF's default formatting (wpautop and shortcode resolution), so the
 * client can drop it straight into a rendered page.
 */
final class PrivacyPolicyController
{
    public const NAMESPACE = 'scrutiny/v1';
    public const POST_TYPE = 'privacy-policy';

    private const FIELD_POLICY        = 'gdpr-policy';
    private const FIELD_VERSION       = 'gdpr-policy-version';
    private const FIELD_ACTIVE        = 'gdpr-policy-active';

    /**
     * Wire the controller into WordPress.
     *
     * `rest_api_init` is the canonical hook for route registration —
     * registering earlier (e.g. on `init`) would miss requests that
     * bypass the early bootstrap, and registering later would be too
     * late for WP_REST_Server to discover the routes.
     */
    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    /**
     * Register the three read-only routes.
     *
     * Order matters: the literal `/active` segment is registered
     * before the `/(?P<id>\d+)` capture so a request to
     * `…/privacy-policies/active` is matched by the literal route
     * rather than failing the numeric regex on the catch-all. The
     * regex would reject "active" anyway, but registering the
     * specific route first keeps the routing table easy to reason
     * about.
     */
    public function registerRoutes(): void
    {
        register_rest_route(
            self::NAMESPACE,
            '/privacy-policies',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'getCollection'],
                'permission_callback' => '__return_true',
                'args'                => [
                    'active' => [
                        'description'       => 'When true, only return policies flagged as currently active.',
                        'type'              => 'boolean',
                        'required'          => false,
                        'sanitize_callback' => 'rest_sanitize_boolean',
                    ],
                ],
            ]
        );

        register_rest_route(
            self::NAMESPACE,
            '/privacy-policies/active',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'getActive'],
                'permission_callback' => '__return_true',
            ]
        );

        register_rest_route(
            self::NAMESPACE,
            '/privacy-policies/(?P<id>\d+)',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'getItem'],
                'permission_callback' => '__return_true',
                'args'                => [
                    'id' => [
                        'description'       => 'Post ID of the privacy policy.',
                        'type'              => 'integer',
                        'required'          => true,
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ]
        );
    }

    /**
     * GET /scrutiny/v1/privacy-policies
     *
     * Returns the full collection of published policies, newest
     * first. The `active` query param narrows the result to the
     * currently-active subset — useful for frontends that don't want
     * to filter client-side.
     */
    public function getCollection(WP_REST_Request $request): WP_REST_Response
    {
        $activeOnly = (bool) $request->get_param('active');

        $posts = get_posts([
            'post_type'      => self::POST_TYPE,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ]);

        $items = [];
        foreach ($posts as $post) {
            $formatted = $this->formatPolicy($post);
            if ($activeOnly && !$formatted['active']) {
                continue;
            }
            $items[] = $formatted;
        }

        return rest_ensure_response($items);
    }

    /**
     * GET /scrutiny/v1/privacy-policies/active
     *
     * Returns the single most-recent active policy. If two policies
     * are flagged active simultaneously (a configuration error, but
     * the schema doesn't prevent it), the newer one wins. If none
     * are active, returns a 404 — callers should treat the absence
     * of an active policy as a deployment problem rather than an
     * empty-but-OK state.
     */
    public function getActive(): WP_REST_Response
    {
        $posts = get_posts([
            'post_type'      => self::POST_TYPE,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ]);

        foreach ($posts as $post) {
            $formatted = $this->formatPolicy($post);
            if ($formatted['active']) {
                return rest_ensure_response($formatted);
            }
        }

        return new WP_REST_Response(
            [
                'code'    => 'scrutiny_no_active_policy',
                'message' => 'No active privacy policy is published.',
                'data'    => ['status' => 404],
            ],
            404
        );
    }

    /**
     * GET /scrutiny/v1/privacy-policies/{id}
     *
     * Fetches a single policy by ID. Returns 404 if the post does
     * not exist, is the wrong post type, or is not published —
     * unpublished policies must not leak through this endpoint.
     */
    public function getItem(WP_REST_Request $request): WP_REST_Response
    {
        $id   = (int) $request->get_param('id');
        $post = get_post($id);

        if (
            !$post instanceof WP_Post
            || $post->post_type !== self::POST_TYPE
            || $post->post_status !== 'publish'
        ) {
            return new WP_REST_Response(
                [
                    'code'    => 'scrutiny_policy_not_found',
                    'message' => 'Privacy policy not found.',
                    'data'    => ['status' => 404],
                ],
                404
            );
        }

        return rest_ensure_response($this->formatPolicy($post));
    }

    /**
     * Project a {@see WP_Post} plus its ACF fields into the
     * response shape documented on the class docblock.
     *
     * Pulled out as its own method (and made internally testable)
     * because all three route callbacks share it. Field reads go
     * through {@see self::readField()} which is a thin shim around
     * `get_field()` so unit tests can supply field values without a
     * WP runtime.
     *
     * @return array{
     *     id: int,
     *     title: string,
     *     version: string,
     *     active: bool,
     *     policy: string,
     *     modified: string
     * }
     */
    public function formatPolicy(WP_Post $post): array
    {
        return [
            'id'       => (int) $post->ID,
            'title'    => (string) $post->post_title,
            'version'  => (string) $this->readField(self::FIELD_VERSION, (int) $post->ID),
            'active'   => (bool) $this->readField(self::FIELD_ACTIVE, (int) $post->ID),
            'policy'   => (string) $this->readField(self::FIELD_POLICY, (int) $post->ID),
            'modified' => mysql2date('c', $post->post_modified_gmt, false),
        ];
    }

    /**
     * Read an ACF field for a post, with a function-exists guard so
     * the controller doesn't fatal if ACF is absent (the plugin
     * already lists ACF as a soft dependency rather than hard-failing
     * activation when it's missing).
     *
     * Tests stub `get_field` directly via the bootstrap, so this
     * indirection costs nothing at runtime and earns full coverage of
     * {@see self::formatPolicy()} without a WP harness.
     */
    protected function readField(string $name, int $postId): mixed
    {
        if (!function_exists('get_field')) {
            return '';
        }
        return get_field($name, $postId);
    }
}
