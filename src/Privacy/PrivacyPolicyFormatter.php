<?php

declare(strict_types=1);

namespace Scrutiny\Privacy;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Unity\PrivacyPolicies\Interfaces\PrivacyPolicy;
use function mysql2date;
use function wp_kses_post;

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

        // mysql2date() is string|false. A false here would be a stored value
        // that is neither empty nor parseable, and the same reasoning as the
        // empty case applies: project it away rather than fabricate a
        // timestamp the post never had.
        $modified = $updated === '' ? '' : mysql2date('c', $updated, false);

        return [
            'id'       => $policy->getId(),
            'title'    => $policy->getTitle(),
            'version'  => $policy->getVersion(),
            'active'   => $policy->isActive(),
            'policy'   => $this->sanitiseBody($policy->getPolicy()),
            'modified' => $modified === false ? '' : $modified,
        ];
    }

    /**
     * Filter the WYSIWYG body down to safe post-content markup.
     *
     * Every surface that renders the body gets it from here: the shortcode,
     * and REST consumers such as the Register app, which drops it verbatim
     * into the HTML acceptance email. An administrator with unfiltered_html
     * can store anything in the field, so kses has to run here rather than
     * only in the shortcode — it removes <script>, on* handlers and links
     * with schemes WordPress does not allow (javascript:, data:, vbscript:).
     *
     * <style> and <script> blocks are dropped first, contents and all. kses
     * lets <style> through, and a leaked one is almost always an accidental
     * paste from a styled source that can surface as visible CSS text; the
     * explicit <script> strip means a reader need not verify kses to know
     * scripts are gone.
     */
    private function sanitiseBody(string $body): string
    {
        $body = preg_replace('#<style\b[^>]*>.*?</style>#is', '', $body) ?? $body;
        $body = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $body) ?? $body;

        return wp_kses_post($body);
    }
}
