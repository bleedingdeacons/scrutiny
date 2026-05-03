<?php

declare(strict_types=1);

namespace Scrutiny\Tests\Unit\Rest;

use PHPUnit\Framework\TestCase;
use Scrutiny\Rest\PrivacyPolicyController;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Tests for the PrivacyPolicyController.
 *
 * The controller is the read-only REST surface for the privacy-policy
 * CPT. The post type and its ACF fields are stubbed via the bootstrap
 * (see tests/bootstrap.php for get_field / get_post / get_posts), so
 * every test can drive the full route callback path without a WP
 * harness.
 *
 * Coverage focuses on the contract guarantees promised in the
 * controller's class docblock: the response shape, the active-only
 * filtering, the 404 behaviours, and the route registration.
 */
class PrivacyPolicyControllerTest extends TestCase
{
    protected function setUp(): void
    {
        // Reset every in-memory store so one test's fixtures can't
        // bleed into the next.
        $GLOBALS['scrutiny_test_posts']       = [];
        $GLOBALS['scrutiny_test_acf_fields']  = [];
        $GLOBALS['scrutiny_test_rest_routes'] = [];
    }

    // ──────────────────────────────────────────────
    //  Route registration
    // ──────────────────────────────────────────────

    /** @test */
    public function it_registers_three_read_only_routes_under_the_scrutiny_v1_namespace(): void
    {
        // Regression guard: the class docblock and the README both
        // promise three routes under scrutiny/v1. Anything that drops
        // or renames one of them deserves to be caught here.
        $controller = new PrivacyPolicyController();
        $controller->registerRoutes();

        $routes = $GLOBALS['scrutiny_test_rest_routes'];
        $this->assertCount(3, $routes);

        $registered = array_map(
            fn(array $r) => $r['namespace'] . $r['route'],
            $routes
        );
        $this->assertContains('scrutiny/v1/privacy-policies', $registered);
        $this->assertContains('scrutiny/v1/privacy-policies/active', $registered);
        $this->assertContains('scrutiny/v1/privacy-policies/(?P<id>\d+)', $registered);
    }

    /** @test */
    public function every_route_is_publicly_readable(): void
    {
        // Privacy policies are explicitly public — the permission
        // callback should be a permissive one on every route. If a
        // future change tightens this without removing the docblock
        // guarantee, the test will fail.
        $controller = new PrivacyPolicyController();
        $controller->registerRoutes();

        foreach ($GLOBALS['scrutiny_test_rest_routes'] as $route) {
            $this->assertSame('GET', $route['args']['methods']);
            $this->assertSame('__return_true', $route['args']['permission_callback']);
        }
    }

    /** @test */
    public function active_route_is_registered_before_the_id_capture(): void
    {
        // WordPress matches routes in registration order. The literal
        // /active segment must come before /(?P<id>\d+) so a request
        // to …/privacy-policies/active hits the active handler rather
        // than failing the numeric regex first. Guarding the order
        // here prevents an accidental reshuffle from breaking the
        // route silently.
        $controller = new PrivacyPolicyController();
        $controller->registerRoutes();

        $order = array_map(fn(array $r) => $r['route'], $GLOBALS['scrutiny_test_rest_routes']);
        $activeIndex = array_search('/privacy-policies/active', $order, true);
        $idIndex     = array_search('/privacy-policies/(?P<id>\d+)', $order, true);

        $this->assertNotFalse($activeIndex);
        $this->assertNotFalse($idIndex);
        $this->assertLessThan($idIndex, $activeIndex);
    }

    // ──────────────────────────────────────────────
    //  Response shape (formatPolicy)
    // ──────────────────────────────────────────────

    /** @test */
    public function it_projects_a_policy_into_the_documented_response_shape(): void
    {
        // Pin the exact response shape the controller's docblock
        // promises to consumers. Field names are converted from the
        // ACF kebab-case convention to snake_case, the redundant
        // "gdpr-" prefix is stripped, and the modified timestamp is
        // ISO 8601.
        $post = new WP_Post([
            'ID'                => 42,
            'post_title'        => 'Privacy Policy',
            'post_type'         => PrivacyPolicyController::POST_TYPE,
            'post_status'       => 'publish',
            'post_modified_gmt' => '2026-04-15 09:30:00',
            'post_date_gmt'     => '2026-04-15 09:30:00',
        ]);
        $GLOBALS['scrutiny_test_acf_fields'][42] = [
            'gdpr-policy-contact' => 'Data Protection Officer',
            'gdpr-contact-email'  => 'dpo@example.org',
            'gdpr-policy'         => '<p>The full policy text.</p>',
            'gdpr-policy-version' => '2.1',
            'gdpr-policy-active'  => true,
        ];

        $controller = new PrivacyPolicyController();
        $shape      = $controller->formatPolicy($post);

        $this->assertSame([
            'id'            => 42,
            'title'         => 'Privacy Policy',
            'version'       => '2.1',
            'active'        => true,
            'contact'       => 'Data Protection Officer',
            'contact_email' => 'dpo@example.org',
            'policy'        => '<p>The full policy text.</p>',
            'modified'      => '2026-04-15T09:30:00+00:00',
        ], $shape);
    }

    /** @test */
    public function active_field_is_coerced_to_a_strict_boolean(): void
    {
        // ACF's true_false field can return 1, 0, '1', '' depending
        // on the storage backend version. The response must always
        // be a real boolean so JSON consumers can rely on
        // typeof === "boolean".
        $post = $this->makePost(7);

        foreach ([1, '1', true, 'yes'] as $truthy) {
            $GLOBALS['scrutiny_test_acf_fields'][7] = ['gdpr-policy-active' => $truthy];
            $shape = (new PrivacyPolicyController())->formatPolicy($post);
            $this->assertTrue($shape['active'], 'Expected true for ' . var_export($truthy, true));
        }

        foreach ([0, '0', false, ''] as $falsy) {
            $GLOBALS['scrutiny_test_acf_fields'][7] = ['gdpr-policy-active' => $falsy];
            $shape = (new PrivacyPolicyController())->formatPolicy($post);
            $this->assertFalse($shape['active'], 'Expected false for ' . var_export($falsy, true));
        }
    }

    /** @test */
    public function missing_acf_fields_become_safe_empty_strings(): void
    {
        // A draft policy or a buggy ACF state must not crash the
        // formatter. Every absent field reads back as '' (or false
        // for the boolean), so consumers see a well-formed payload
        // they can render through.
        $post = $this->makePost(99);
        // Deliberately register no ACF fields for post 99.

        $shape = (new PrivacyPolicyController())->formatPolicy($post);

        $this->assertSame('', $shape['contact']);
        $this->assertSame('', $shape['contact_email']);
        $this->assertSame('', $shape['policy']);
        $this->assertSame('', $shape['version']);
        $this->assertFalse($shape['active']);
    }

    // ──────────────────────────────────────────────
    //  GET /privacy-policies (collection)
    // ──────────────────────────────────────────────

    /** @test */
    public function the_collection_route_returns_every_published_policy(): void
    {
        $this->seedPolicy(1, '2026-01-01 00:00:00', active: false);
        $this->seedPolicy(2, '2026-02-01 00:00:00', active: true);
        $this->seedPolicy(3, '2026-03-01 00:00:00', active: false);

        $response = (new PrivacyPolicyController())
            ->getCollection(new WP_REST_Request());

        $this->assertSame(200, $response->get_status());
        $items = $response->get_data();
        $this->assertIsArray($items);
        $this->assertCount(3, $items);
    }

    /** @test */
    public function the_collection_route_orders_newest_first(): void
    {
        // Documented contract: the collection comes back newest-first
        // so a frontend can render "policy history" without an extra
        // sort pass.
        $this->seedPolicy(1, '2026-01-01 00:00:00');
        $this->seedPolicy(2, '2026-03-01 00:00:00');
        $this->seedPolicy(3, '2026-02-01 00:00:00');

        $items = (new PrivacyPolicyController())
            ->getCollection(new WP_REST_Request())
            ->get_data();

        $this->assertSame([2, 3, 1], array_map(fn(array $i) => $i['id'], $items));
    }

    /** @test */
    public function active_query_param_filters_to_only_the_active_policies(): void
    {
        $this->seedPolicy(1, '2026-01-01 00:00:00', active: false);
        $this->seedPolicy(2, '2026-02-01 00:00:00', active: true);
        $this->seedPolicy(3, '2026-03-01 00:00:00', active: false);

        $request = new WP_REST_Request(['active' => true]);
        $items = (new PrivacyPolicyController())
            ->getCollection($request)
            ->get_data();

        $this->assertCount(1, $items);
        $this->assertSame(2, $items[0]['id']);
    }

    /** @test */
    public function the_collection_route_returns_an_empty_array_when_no_policies_exist(): void
    {
        // No 404 here — an empty array is a perfectly valid answer
        // for "list everything"; only the /active convenience route
        // 404s on absence.
        $response = (new PrivacyPolicyController())
            ->getCollection(new WP_REST_Request());

        $this->assertSame(200, $response->get_status());
        $this->assertSame([], $response->get_data());
    }

    // ──────────────────────────────────────────────
    //  GET /privacy-policies/active
    // ──────────────────────────────────────────────

    /** @test */
    public function the_active_route_returns_the_single_active_policy(): void
    {
        $this->seedPolicy(1, '2026-01-01 00:00:00', active: false);
        $this->seedPolicy(2, '2026-02-01 00:00:00', active: true);
        $this->seedPolicy(3, '2026-03-01 00:00:00', active: false);

        $response = (new PrivacyPolicyController())->getActive();

        $this->assertSame(200, $response->get_status());
        $this->assertSame(2, $response->get_data()['id']);
    }

    /** @test */
    public function the_active_route_picks_the_newest_when_multiple_are_active(): void
    {
        // The schema doesn't enforce a single-active invariant — if
        // two posts are both flagged active (a config error), the
        // newer one wins. This pins the documented tiebreaker.
        $this->seedPolicy(1, '2026-01-01 00:00:00', active: true);
        $this->seedPolicy(2, '2026-02-01 00:00:00', active: true);
        $this->seedPolicy(3, '2026-03-01 00:00:00', active: true);

        $response = (new PrivacyPolicyController())->getActive();

        $this->assertSame(3, $response->get_data()['id']);
    }

    /** @test */
    public function the_active_route_returns_404_when_no_policy_is_active(): void
    {
        $this->seedPolicy(1, '2026-01-01 00:00:00', active: false);

        $response = (new PrivacyPolicyController())->getActive();

        $this->assertSame(404, $response->get_status());
        $this->assertSame('scrutiny_no_active_policy', $response->get_data()['code']);
    }

    /** @test */
    public function the_active_route_returns_404_when_no_policies_exist_at_all(): void
    {
        $response = (new PrivacyPolicyController())->getActive();

        $this->assertSame(404, $response->get_status());
    }

    // ──────────────────────────────────────────────
    //  GET /privacy-policies/{id}
    // ──────────────────────────────────────────────

    /** @test */
    public function the_item_route_returns_the_named_policy(): void
    {
        $this->seedPolicy(7, '2026-02-01 00:00:00', active: true);

        $response = (new PrivacyPolicyController())
            ->getItem(new WP_REST_Request(['id' => 7]));

        $this->assertSame(200, $response->get_status());
        $this->assertSame(7, $response->get_data()['id']);
    }

    /** @test */
    public function the_item_route_returns_404_for_a_missing_post(): void
    {
        $response = (new PrivacyPolicyController())
            ->getItem(new WP_REST_Request(['id' => 999]));

        $this->assertSame(404, $response->get_status());
        $this->assertSame('scrutiny_policy_not_found', $response->get_data()['code']);
    }

    /** @test */
    public function the_item_route_returns_404_for_the_wrong_post_type(): void
    {
        // Defence in depth: even if a caller knows a real post ID
        // for some other CPT, the endpoint must refuse to leak it
        // through this surface.
        $GLOBALS['scrutiny_test_posts'][50] = new WP_Post([
            'ID'          => 50,
            'post_type'   => 'page',
            'post_status' => 'publish',
        ]);

        $response = (new PrivacyPolicyController())
            ->getItem(new WP_REST_Request(['id' => 50]));

        $this->assertSame(404, $response->get_status());
    }

    /** @test */
    public function the_item_route_returns_404_for_unpublished_policies(): void
    {
        // A draft or trashed policy must never escape via the public
        // endpoint — privacy text in flight is exactly the kind of
        // thing that shouldn't be readable until the editor hits
        // publish.
        foreach (['draft', 'trash', 'private', 'pending'] as $status) {
            $GLOBALS['scrutiny_test_posts'] = [];
            $GLOBALS['scrutiny_test_posts'][12] = new WP_Post([
                'ID'          => 12,
                'post_type'   => PrivacyPolicyController::POST_TYPE,
                'post_status' => $status,
            ]);

            $response = (new PrivacyPolicyController())
                ->getItem(new WP_REST_Request(['id' => 12]));

            $this->assertSame(
                404,
                $response->get_status(),
                "Expected 404 for post_status={$status}"
            );
        }
    }

    // ──────────────────────────────────────────────
    //  Helpers
    // ──────────────────────────────────────────────

    private function makePost(int $id, string $gmt = '2026-01-01 00:00:00'): WP_Post
    {
        return new WP_Post([
            'ID'                => $id,
            'post_title'        => "Policy {$id}",
            'post_type'         => PrivacyPolicyController::POST_TYPE,
            'post_status'       => 'publish',
            'post_modified_gmt' => $gmt,
            'post_date_gmt'     => $gmt,
        ]);
    }

    /**
     * Seed a published policy fixture in both the post store and
     * the ACF store, with sensible defaults for the fields that
     * tests don't otherwise care about.
     */
    private function seedPolicy(int $id, string $gmt, bool $active = false): void
    {
        $GLOBALS['scrutiny_test_posts'][$id] = $this->makePost($id, $gmt);
        $GLOBALS['scrutiny_test_acf_fields'][$id] = [
            'gdpr-policy-contact' => "Contact {$id}",
            'gdpr-contact-email'  => "contact{$id}@example.org",
            'gdpr-policy'         => "<p>Policy {$id} body.</p>",
            'gdpr-policy-version' => "1.{$id}",
            'gdpr-policy-active'  => $active,
        ];
    }
}
