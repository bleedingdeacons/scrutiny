<?php

declare(strict_types=1);

namespace Scrutiny\Rest;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Scrutiny\Privacy\PrivacyPolicyFormatter;
use Unity\PrivacyPolicies\Interfaces\PrivacyPolicy;
use Unity\PrivacyPolicies\Interfaces\PrivacyPolicyRepository;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use function add_action;
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
 * Storage access goes through {@see PrivacyPolicyRepository} rather
 * than touching `get_posts()` / `get_field()` directly. The repository
 * owns the post-type query, the published-status filter, and the
 * single-active-policy invariant; this controller is responsible only
 * for HTTP-shaped concerns (route registration, query-param handling,
 * status codes) and for projecting the domain object into the REST
 * response shape.
 *
 * The response shape strips the redundant `gdpr-` prefix from the
 * domain field names and uses snake_case so the JSON follows REST
 * conventions:
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
 * ACF's default formatting (wpautop and shortcode resolution) by the
 * repository's factory, so the client can drop it straight into a
 * rendered page.
 */
final class PrivacyPolicyController
{
    public const NAMESPACE = 'scrutiny/v1';
    public const POST_TYPE = 'privacy-policy';

    /**
     * The repository is held as a stateful collaborator (rather than
     * resolved per-call) so the container's binding remains the
     * single source of truth for storage and tests can inject a stub
     * without a WP harness. The formatter is held the same way and
     * for the same reason — it is shared with the shortcode so that
     * a single binding in the container governs the projection used
     * by both surfaces.
     */
    public function __construct(
        private readonly PrivacyPolicyRepository $repository,
        private readonly PrivacyPolicyFormatter $formatter,
    ) {
    }

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
     *
     * The repository's findAll() defaults already apply the
     * "published only" filter; the date/order args layer the
     * documented "newest first" contract on top, so the controller
     * doesn't depend on the storage default for ordering.
     */
    public function getCollection(WP_REST_Request $request): WP_REST_Response
    {
        $activeOnly = (bool) $request->get_param('active');

        $policies = $this->repository->findAll([
            'orderby' => 'date',
            'order'   => 'DESC',
        ]);

        $items = [];
        foreach ($policies as $policy) {
            if ($activeOnly && !$policy->isActive()) {
                continue;
            }
            $items[] = $this->formatPolicy($policy);
        }

        return rest_ensure_response($items);
    }

    /**
     * GET /scrutiny/v1/privacy-policies/active
     *
     * Returns the single most-recent active policy. The
     * "newest-active wins" tie-breaker that used to live in this
     * controller now sits in the repository's findActive()
     * implementation — keeping the rule in one place means the
     * shortcode and the REST endpoint can never disagree about
     * which policy is "active" on the same page load.
     *
     * If no active policy is published, returns a 404 — callers
     * should treat the absence of an active policy as a deployment
     * problem rather than an empty-but-OK state.
     */
    public function getActive(): WP_REST_Response
    {
        $policy = $this->repository->findActive();

        if ($policy === null) {
            return new WP_REST_Response(
                [
                    'code'    => 'scrutiny_no_active_policy',
                    'message' => 'No active privacy policy is published.',
                    'data'    => ['status' => 404],
                ],
                404
            );
        }

        return rest_ensure_response($this->formatPolicy($policy));
    }

    /**
     * GET /scrutiny/v1/privacy-policies/{id}
     *
     * Fetches a single policy by ID. Returns 404 if the repository
     * declines to return one — covering the "no such post",
     * "wrong post type", and "not published" cases uniformly.
     * Pushing those checks into the repository keeps unpublished
     * policy text from leaking through this endpoint without the
     * controller having to know which storage column governs each
     * exclusion.
     */
    public function getItem(WP_REST_Request $request): WP_REST_Response
    {
        $id     = (int) $request->get_param('id');
        $policy = $this->repository->findById($id);

        if ($policy === null) {
            return new WP_REST_Response(
                [
                    'code'    => 'scrutiny_policy_not_found',
                    'message' => 'Privacy policy not found.',
                    'data'    => ['status' => 404],
                ],
                404
            );
        }

        return rest_ensure_response($this->formatPolicy($policy));
    }

    /**
     * Project a {@see PrivacyPolicy} into the response shape
     * documented on the class docblock.
     *
     * The projection logic itself lives on
     * {@see PrivacyPolicyFormatter::format()} — this method is a
     * thin delegator, retained as a public surface because the
     * controller's three route callbacks call it directly and
     * removing it would touch four call sites for a no-op rename.
     * Tests that exercise the projection through this method are
     * still valid: they observe the same output, just produced by
     * the collaborator the controller was given.
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
    public function formatPolicy(PrivacyPolicy $policy): array
    {
        return $this->formatter->format($policy);
    }
}
