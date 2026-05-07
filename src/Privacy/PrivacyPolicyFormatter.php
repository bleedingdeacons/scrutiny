<?php

declare(strict_types=1);

namespace Scrutiny\Privacy;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Unity\PrivacyPolicies\Interfaces\PrivacyPolicy;
use function mysql2date;

/**
 * Privacy Policy Formatter
 *
 * Projects a {@see PrivacyPolicy} domain object into the flat,
 * REST-shaped array used by both the JSON endpoints
 * (see {@see \Scrutiny\Rest\PrivacyPolicyController}) and the
 * frontend shortcode
 * (see {@see \Scrutiny\Shortcodes\PrivacyPolicyShortcode}).
 *
 * Lives as its own class — rather than as a method on the controller
 * — because the shortcode is a frontend renderer and shouldn't
 * depend on a REST controller just to reuse a projection. Both
 * surfaces now hold the formatter directly, which keeps the
 * shortcode → formatter and controller → formatter edges inside
 * the same Privacy/Rest layering and avoids the layering inversion
 * the previous arrangement carried.
 *
 * The shape is documented at {@see self::format()}.
 *
 * Stateless — no constructor, no fields, safe to share or rebuild.
 * Registered in the container as a singleton purely so the same
 * instance backs both surfaces (the formatter doesn't itself care).
 */
final class PrivacyPolicyFormatter
{
    /**
     * Project a {@see PrivacyPolicy} into the shared response shape.
     *
     * The interface returns `getUpdated()` in WordPress'
     * `Y-m-d H:i:s` GMT format (the post_modified_gmt convention);
     * mysql2date('c', …, false) converts that to ISO-8601 with a
     * +00:00 offset, which is the format REST consumers expect and
     * which the shortcode also renders verbatim into the metadata
     * block. An empty `getUpdated()` (no modification timestamp on
     * the post) projects to an empty string rather than running
     * mysql2date on an empty input — mysql2date returns the current
     * time for an empty input, which would silently fabricate a
     * timestamp the post never had.
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
    public function format(PrivacyPolicy $policy): array
    {
        $updated = $policy->getUpdated();

        return [
            'id'       => $policy->getId(),
            'title'    => $policy->getTitle(),
            'version'  => $policy->getVersion(),
            'active'   => $policy->isActive(),
            'policy'   => $policy->getPolicy(),
            'modified' => $updated === '' ? '' : mysql2date('c', $updated, false),
        ];
    }
}
