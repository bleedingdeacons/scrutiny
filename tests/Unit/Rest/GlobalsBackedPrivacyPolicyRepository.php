<?php

declare(strict_types=1);

namespace Scrutiny\Tests\Unit\Rest;

use Scrutiny\Rest\PrivacyPolicyController;
use Unity\PrivacyPolicies\Interfaces\PrivacyPolicy;
use Unity\PrivacyPolicies\Interfaces\PrivacyPolicyRepository;
use WP_Post;

/**
 * Globals-backed test double for {@see PrivacyPolicyRepository}.
 *
 * Reads from the same in-memory stores the existing test fixtures
 * already populate:
 *
 *   $GLOBALS['scrutiny_test_posts']      → [post_id => WP_Post]
 *   $GLOBALS['scrutiny_test_acf_fields'] → [post_id => [field_name => value]]
 *
 * This means every test that uses `seedPolicy()` keeps working
 * unchanged after the controller and shortcode switched from
 * touching `get_posts()` / `get_field()` directly to going through
 * the repository. The fixture surface is identical; only the
 * collaborator the SUT receives has changed.
 *
 * Filtering rules mirror what the production TsmlPrivacyPolicyRepository
 * is documented to do:
 *
 *   - findAll() defaults to published posts of the privacy-policy
 *     post type, ordered by post_date_gmt DESC when args ask for it.
 *   - findById() returns null for missing posts, posts of a different
 *     type, and posts whose status is not 'publish' — the controller
 *     used to enforce these checks itself, and the repository has now
 *     taken over that responsibility.
 *   - findActive() returns the most-recently-modified active policy,
 *     matching the "newest active wins" tiebreaker the controller used
 *     to apply in PHP.
 *
 * Mutation methods throw — none of the surfaces under test write
 * through the repository, so a future change that accidentally
 * starts writing fails loudly here instead of silently appearing
 * to succeed.
 */
final class GlobalsBackedPrivacyPolicyRepository implements PrivacyPolicyRepository
{
    public function findById(int $id): ?PrivacyPolicy
    {
        $post = $GLOBALS['scrutiny_test_posts'][$id] ?? null;

        if (
            !$post instanceof WP_Post
            || $post->post_type !== PrivacyPolicyController::POST_TYPE
            || $post->post_status !== 'publish'
        ) {
            return null;
        }

        return new StubPrivacyPolicy(
            $post,
            $GLOBALS['scrutiny_test_acf_fields'][$id] ?? [],
        );
    }

    public function findActive(): ?PrivacyPolicy
    {
        // Walk the published privacy-policy posts newest-first by
        // post_modified_gmt — the interface docblock specifies "the
        // most recently modified one" as the tiebreaker when more
        // than one policy is flagged active.
        $candidates = $this->publishedPolicies();

        usort($candidates, function (WP_Post $a, WP_Post $b) {
            return strcmp(
                (string) $b->post_modified_gmt,
                (string) $a->post_modified_gmt,
            );
        });

        foreach ($candidates as $post) {
            $policy = new StubPrivacyPolicy(
                $post,
                $GLOBALS['scrutiny_test_acf_fields'][$post->ID] ?? [],
            );
            if ($policy->isActive()) {
                return $policy;
            }
        }

        return null;
    }

    public function findAll(array $args = []): array
    {
        $posts = $this->publishedPolicies();

        if (($args['orderby'] ?? '') === 'date') {
            $dir = (($args['order'] ?? 'DESC') === 'ASC') ? 1 : -1;
            usort($posts, function (WP_Post $a, WP_Post $b) use ($dir) {
                return $dir * strcmp(
                    (string) $a->post_date_gmt,
                    (string) $b->post_date_gmt,
                );
            });
        }

        return array_map(
            fn(WP_Post $p) => new StubPrivacyPolicy(
                $p,
                $GLOBALS['scrutiny_test_acf_fields'][$p->ID] ?? [],
            ),
            $posts,
        );
    }

    public function count(array $args = []): int
    {
        return count($this->publishedPolicies());
    }

    public function create(string $title): int
    {
        throw new \LogicException('Not implemented in test double');
    }

    public function save(PrivacyPolicy $policy): bool
    {
        throw new \LogicException('Not implemented in test double');
    }

    public function update(PrivacyPolicy $policy): bool
    {
        throw new \LogicException('Not implemented in test double');
    }

    public function delete(int $id): bool
    {
        throw new \LogicException('Not implemented in test double');
    }

    /**
     * Pull every published privacy-policy WP_Post out of the
     * fixture store. Order is the insertion order at this stage;
     * callers that care about a specific ordering apply it on top.
     *
     * @return array<WP_Post>
     */
    private function publishedPolicies(): array
    {
        $matches = [];
        foreach (($GLOBALS['scrutiny_test_posts'] ?? []) as $post) {
            if (!$post instanceof WP_Post) {
                continue;
            }
            if ($post->post_type !== PrivacyPolicyController::POST_TYPE) {
                continue;
            }
            if ($post->post_status !== 'publish') {
                continue;
            }
            $matches[] = $post;
        }
        return $matches;
    }
}
