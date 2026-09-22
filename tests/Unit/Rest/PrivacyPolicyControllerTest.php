<?php

declare(strict_types=1);

namespace Scrutiny\Tests\Unit\Rest;

use Scrutiny\Privacy\PrivacyPolicyFormatter;
use Scrutiny\Rest\PrivacyPolicyController;
use WP_Post;
use WP_REST_Request;

require_once __DIR__ . '/StubPrivacyPolicy.php';
require_once __DIR__ . '/GlobalsBackedPrivacyPolicyRepository.php';

/*
 * Tests for the PrivacyPolicyController.
 *
 * The controller is the read-only REST surface for the privacy-policy CPT.
 * The post type and its ACF fields are stubbed via the bootstrap (see
 * tests/bootstrap.php for get_field / get_post / get_posts), so every test can
 * drive the full route callback path without a WP harness.
 *
 * Coverage focuses on the contract guarantees promised in the controller's
 * class docblock: the response shape, the active-only filtering, the 404
 * behaviours, and the route registration.
 */

/**
 * Build a controller wired to the globals-backed repository stub. Every test
 * resolves through this helper so the collaborator stays in one place — if the
 * controller's dependencies change again, only this function needs to update.
 */
function policyController(): PrivacyPolicyController
{
    return new PrivacyPolicyController(
        new GlobalsBackedPrivacyPolicyRepository(),
        new PrivacyPolicyFormatter()
    );
}

function policyPost(int $id, string $gmt = '2026-01-01 00:00:00'): WP_Post
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
 * Seed a published policy fixture in both the post store and the ACF store,
 * with sensible defaults for the fields that tests don't otherwise care about.
 */
function seedPolicy(int $id, string $gmt, bool $active = false): void
{
    $GLOBALS['scrutiny_test_posts'][$id] = policyPost($id, $gmt);
    $GLOBALS['scrutiny_test_acf_fields'][$id] = [
        'gdpr-policy'         => "<p>Policy {$id} body.</p>",
        'gdpr-policy-version' => "1.{$id}",
        'gdpr-policy-active'  => $active,
    ];
}

beforeEach(function () {
    // Reset every in-memory store so one test's fixtures can't bleed into the
    // next.
    $GLOBALS['scrutiny_test_posts']       = [];
    $GLOBALS['scrutiny_test_acf_fields']  = [];
    $GLOBALS['scrutiny_test_rest_routes'] = [];
});

// ──────────────────────────────────────────────
//  Route registration
// ──────────────────────────────────────────────
describe('route registration', function () {
    it('registers three read-only routes under the scrutiny/v1 namespace', function () {
        // Regression guard: the class docblock and the README both promise
        // three routes under scrutiny/v1. Anything that drops or renames one
        // of them deserves to be caught here.
        policyController()->registerRoutes();

        $routes = $GLOBALS['scrutiny_test_rest_routes'];

        expect($routes)->toHaveCount(3)
            ->and(array_map(fn (array $r) => $r['namespace'] . $r['route'], $routes))->toContain(
                'scrutiny/v1/privacy-policies',
                'scrutiny/v1/privacy-policies/active',
                'scrutiny/v1/privacy-policies/(?P<id>\d+)',
            );
    });

    it('makes every route publicly readable', function () {
        // Privacy policies are explicitly public — the permission callback
        // should be a permissive one on every route. If a future change
        // tightens this without removing the docblock guarantee, the test
        // will fail.
        policyController()->registerRoutes();

        foreach ($GLOBALS['scrutiny_test_rest_routes'] as $route) {
            expect($route['args']['methods'])->toBe('GET')
                ->and($route['args']['permission_callback'])->toBe('__return_true');
        }
    });

    it('registers the active route before the id capture', function () {
        // WordPress matches routes in registration order. The literal /active
        // segment must come before /(?P<id>\d+) so a request to
        // …/privacy-policies/active hits the active handler rather than
        // failing the numeric regex first. Guarding the order here prevents
        // an accidental reshuffle from breaking the route silently.
        policyController()->registerRoutes();

        $order = array_map(fn (array $r) => $r['route'], $GLOBALS['scrutiny_test_rest_routes']);
        $activeIndex = array_search('/privacy-policies/active', $order, true);
        $idIndex     = array_search('/privacy-policies/(?P<id>\d+)', $order, true);

        expect($activeIndex)->not->toBeFalse()
            ->and($idIndex)->not->toBeFalse()
            ->and($activeIndex)->toBeLessThan($idIndex);
    });
});

// ──────────────────────────────────────────────
//  Response shape (formatPolicy)
// ──────────────────────────────────────────────
describe('the response shape', function () {
    it('projects a policy into the documented response shape', function () {
        // Pin the exact response shape the controller's docblock promises to
        // consumers. Field names are converted from the ACF kebab-case
        // convention to snake_case, the redundant "gdpr-" prefix is stripped,
        // and the modified timestamp is ISO 8601.
        //
        // The formatter now reads from a PrivacyPolicy domain object rather
        // than a WP_Post + ACF map, but the projection it emits is identical —
        // that's the contract the REST clients already depend on.
        $policy = new StubPrivacyPolicy(
            new WP_Post([
                'ID'                => 42,
                'post_title'        => 'Privacy Policy',
                'post_type'         => PrivacyPolicyController::POST_TYPE,
                'post_status'       => 'publish',
                'post_modified_gmt' => '2026-04-15 09:30:00',
                'post_date_gmt'     => '2026-04-15 09:30:00',
            ]),
            [
                'gdpr-policy'         => '<p>The full policy text.</p>',
                'gdpr-policy-version' => '2.1',
                'gdpr-policy-active'  => true,
            ],
        );

        expect(policyController()->formatPolicy($policy))->toBe([
            'id'       => 42,
            'title'    => 'Privacy Policy',
            'version'  => '2.1',
            'active'   => true,
            'policy'   => '<p>The full policy text.</p>',
            'modified' => '2026-04-15T09:30:00+00:00',
        ]);
    });

    // ACF's true_false field can return 1, 0, '1', '' depending on the
    // storage backend version. The response must always be a real boolean so
    // JSON consumers can rely on typeof === "boolean". The coercion now lives
    // on the PrivacyPolicy implementation rather than in the formatter, but
    // the contract the controller exposes is unchanged.
    it('coerces the active field to a strict boolean', function (mixed $stored, bool $expected) {
        $policy = new StubPrivacyPolicy(policyPost(7), ['gdpr-policy-active' => $stored]);

        expect(policyController()->formatPolicy($policy)['active'])->toBe($expected);
    })->with([
        'integer 1'    => [1, true],
        "string '1'"   => ['1', true],
        'true'         => [true, true],
        "string 'yes'" => ['yes', true],
        'integer 0'    => [0, false],
        "string '0'"   => ['0', false],
        'false'        => [false, false],
        'empty string' => ['', false],
    ]);

    it('turns missing ACF fields into safe empty values', function () {
        // A draft policy or a buggy ACF state must not crash the formatter.
        // Every absent field reads back as '' (or false for the boolean), so
        // consumers see a well-formed payload they can render through.
        expect(policyController()->formatPolicy(new StubPrivacyPolicy(policyPost(99), [])))
            ->policy->toBe('')
            ->version->toBe('')
            ->active->toBeFalse();
    });
});

// ──────────────────────────────────────────────
//  GET /privacy-policies (collection)
// ──────────────────────────────────────────────
describe('GET /privacy-policies', function () {
    it('returns every published policy', function () {
        seedPolicy(1, '2026-01-01 00:00:00', active: false);
        seedPolicy(2, '2026-02-01 00:00:00', active: true);
        seedPolicy(3, '2026-03-01 00:00:00', active: false);

        $response = policyController()->getCollection(new WP_REST_Request());

        expect($response->get_status())->toBe(200)
            ->and($response->get_data())->toBeArray()->toHaveCount(3);
    });

    it('orders the policies newest first', function () {
        // Documented contract: the collection comes back newest-first so a
        // frontend can render "policy history" without an extra sort pass.
        seedPolicy(1, '2026-01-01 00:00:00');
        seedPolicy(2, '2026-03-01 00:00:00');
        seedPolicy(3, '2026-02-01 00:00:00');

        $items = policyController()->getCollection(new WP_REST_Request())->get_data();

        expect(array_map(fn (array $i) => $i['id'], $items))->toBe([2, 3, 1]);
    });

    it('filters to only the active policies with the active query param', function () {
        seedPolicy(1, '2026-01-01 00:00:00', active: false);
        seedPolicy(2, '2026-02-01 00:00:00', active: true);
        seedPolicy(3, '2026-03-01 00:00:00', active: false);

        $items = policyController()->getCollection(new WP_REST_Request(['active' => true]))->get_data();

        expect($items)->toHaveCount(1)
            ->and($items[0]['id'])->toBe(2);
    });

    it('returns an empty array when no policies exist', function () {
        // No 404 here — an empty array is a perfectly valid answer for "list
        // everything"; only the /active convenience route 404s on absence.
        $response = policyController()->getCollection(new WP_REST_Request());

        expect($response->get_status())->toBe(200)
            ->and($response->get_data())->toBe([]);
    });
});

// ──────────────────────────────────────────────
//  GET /privacy-policies/active
// ──────────────────────────────────────────────
describe('GET /privacy-policies/active', function () {
    it('returns the single active policy', function () {
        seedPolicy(1, '2026-01-01 00:00:00', active: false);
        seedPolicy(2, '2026-02-01 00:00:00', active: true);
        seedPolicy(3, '2026-03-01 00:00:00', active: false);

        $response = policyController()->getActive();

        expect($response->get_status())->toBe(200)
            ->and($response->get_data()['id'])->toBe(2);
    });

    it('picks the newest when several are active', function () {
        // The schema doesn't enforce a single-active invariant — if two posts
        // are both flagged active (a config error), the newer one wins. This
        // pins the documented tiebreaker.
        seedPolicy(1, '2026-01-01 00:00:00', active: true);
        seedPolicy(2, '2026-02-01 00:00:00', active: true);
        seedPolicy(3, '2026-03-01 00:00:00', active: true);

        expect(policyController()->getActive()->get_data()['id'])->toBe(3);
    });

    it('returns 404 when no policy is active', function () {
        seedPolicy(1, '2026-01-01 00:00:00', active: false);

        $response = policyController()->getActive();

        expect($response->get_status())->toBe(404)
            ->and($response->get_data()['code'])->toBe('scrutiny_no_active_policy');
    });

    it('returns 404 when no policies exist at all', function () {
        expect(policyController()->getActive()->get_status())->toBe(404);
    });
});

// ──────────────────────────────────────────────
//  GET /privacy-policies/{id}
// ──────────────────────────────────────────────
describe('GET /privacy-policies/{id}', function () {
    it('returns the named policy', function () {
        seedPolicy(7, '2026-02-01 00:00:00', active: true);

        $response = policyController()->getItem(new WP_REST_Request(['id' => 7]));

        expect($response->get_status())->toBe(200)
            ->and($response->get_data()['id'])->toBe(7);
    });

    it('returns 404 for a missing post', function () {
        $response = policyController()->getItem(new WP_REST_Request(['id' => 999]));

        expect($response->get_status())->toBe(404)
            ->and($response->get_data()['code'])->toBe('scrutiny_policy_not_found');
    });

    it('returns 404 for the wrong post type', function () {
        // Defence in depth: even if a caller knows a real post ID for some
        // other CPT, the endpoint must refuse to leak it through this surface.
        $GLOBALS['scrutiny_test_posts'][50] = new WP_Post([
            'ID'          => 50,
            'post_type'   => 'page',
            'post_status' => 'publish',
        ]);

        expect(policyController()->getItem(new WP_REST_Request(['id' => 50]))->get_status())->toBe(404);
    });

    // A draft or trashed policy must never escape via the public endpoint —
    // privacy text in flight is exactly the kind of thing that shouldn't be
    // readable until the editor hits publish.
    it('returns 404 for an unpublished policy', function (string $status) {
        $GLOBALS['scrutiny_test_posts'][12] = new WP_Post([
            'ID'          => 12,
            'post_type'   => PrivacyPolicyController::POST_TYPE,
            'post_status' => $status,
        ]);

        expect(policyController()->getItem(new WP_REST_Request(['id' => 12]))->get_status())
            ->toBe(404, "Expected 404 for post_status={$status}");
    })->with(['draft', 'trash', 'private', 'pending']);
});
