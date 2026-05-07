<?php

declare(strict_types=1);

namespace Scrutiny\Tests\Unit\Rest;

use Unity\PrivacyPolicies\Interfaces\PrivacyPolicy;
use WP_Post;

/**
 * Test double implementing {@see PrivacyPolicy}.
 *
 * Wraps a stub WP_Post plus a flat ACF field map (keyed by ACF
 * field name, e.g. `gdpr-policy`) and projects them through the
 * interface methods. The mapping mirrors what TsmlPrivacyPolicy
 * would do at runtime — the post_title becomes getTitle(), the ACF
 * fields become getPolicy()/getVersion()/isActive(), and
 * post_modified_gmt becomes getUpdated().
 *
 * The active-flag coercion is deliberate: ACF's true_false field
 * can return 1, '1', true, '' depending on the storage backend
 * version, and the production interface promises a strict bool.
 * The cast to (bool) here matches that contract, which keeps the
 * existing controller tests (which feed mixed truthy/falsy values
 * through the seeded fixtures) working unchanged.
 *
 * Lives in the test tree rather than the production tree because
 * its only job is to let the existing $GLOBALS-driven fixtures
 * keep working after the controller switched from reading WP_Post
 * directly to reading PrivacyPolicy objects.
 */
final class StubPrivacyPolicy implements PrivacyPolicy
{
    /** @param array<string, mixed> $fields ACF field map for this post */
    public function __construct(
        private readonly WP_Post $post,
        private readonly array $fields = [],
    ) {
    }

    public function getId(): int
    {
        return (int) $this->post->ID;
    }

    public function getTitle(): string
    {
        return (string) $this->post->post_title;
    }

    public function getPolicy(): string
    {
        return (string) ($this->fields['gdpr-policy'] ?? '');
    }

    public function getVersion(): string
    {
        return (string) ($this->fields['gdpr-policy-version'] ?? '');
    }

    public function isActive(): bool
    {
        $raw = $this->fields['gdpr-policy-active'] ?? false;

        // ACF's true_false field comes back as 1 / '1' / true / 'yes'
        // for the active state; the strict bool below normalises every
        // truthy variant the controller fixtures feed in.
        if (is_string($raw)) {
            $lower = strtolower($raw);
            if (in_array($lower, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
            if (in_array($lower, ['', '0', 'false', 'no', 'off'], true)) {
                return false;
            }
        }

        return (bool) $raw;
    }

    public function getUpdated(): string
    {
        return (string) $this->post->post_modified_gmt;
    }
}
