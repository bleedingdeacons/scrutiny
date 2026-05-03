<?php

declare(strict_types=1);

namespace Scrutiny\Shortcodes;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Scrutiny\Rest\PrivacyPolicyController;
use function add_shortcode;
use function esc_html;
use function get_posts;
use function wp_kses_post;

/**
 * Privacy Policy Shortcode
 *
 * Renders the currently-active privacy policy inline in any page,
 * post, or widget that supports shortcodes:
 *
 *     [scrutiny_privacy_policy]
 *
 * The output contains four scalar fields rendered as plain text —
 * contact (the "member" responsible for the policy), contact email,
 * version, and the GMT-modified timestamp — followed by the policy
 * body as WordPress WYSIWYG markup. The body is the
 * already-formatted HTML produced by ACF's `wpautop` + shortcode
 * resolution pipeline, so paragraphs, lists, and inline formatting
 * authored in the editor render as the editor intended.
 *
 * Selection rule: the most-recent published policy with the
 * `gdpr-policy-active` flag set wins. This mirrors
 * {@see PrivacyPolicyController::getActive()} so the shortcode and
 * the REST endpoint can never disagree about which policy is
 * "active" on the same page load.
 *
 * If no active policy is published, the shortcode emits an empty
 * string. The deliberate silence avoids dropping a visible
 * "no policy configured" placeholder onto a public page during the
 * window between deploying the plugin and publishing the first
 * policy — exactly the moment when an admin is least likely to want
 * a user-facing error.
 *
 * The class is intentionally thin: the heavy lifting (post lookup,
 * field projection, active-flag coercion, ISO-8601 timestamping)
 * lives on the controller's `formatPolicy()` method. Reusing it
 * keeps the two surfaces in lock-step and means a future change to
 * the policy shape only has to be made in one place.
 */
final class PrivacyPolicyShortcode
{
    public const TAG = 'scrutiny_privacy_policy';

    /**
     * The controller is reused as a stateless formatter, not as a
     * REST router. Holding a reference (rather than `new`-ing one
     * per shortcode call) lets the container manage its lifecycle
     * and lets tests inject a stub formatter when they need to.
     */
    public function __construct(private readonly PrivacyPolicyController $controller)
    {
    }

    /**
     * Wire the shortcode into WordPress.
     *
     * Registers on `init`-time via the caller — `add_shortcode` is
     * safe to call at any point before the first `do_shortcode`
     * runs, and the plugin's bootstrap already runs early enough on
     * `unity/loaded` to satisfy that ordering for both admin and
     * frontend requests.
     */
    public function register(): void
    {
        add_shortcode(self::TAG, [$this, 'render']);
    }

    /**
     * Render the active policy as an HTML block.
     *
     * Shortcode handlers receive an `$atts` array even when the
     * shortcode is invoked with no attributes; we deliberately
     * don't expose any attributes yet (there is only ever one
     * "active" policy, so there is nothing to parameterise) but the
     * signature must still match WordPress's contract.
     *
     * @param array<string, string>|string $atts Ignored; kept for
     *                                            the WP signature.
     */
    public function render($atts = []): string
    {
        $shape = $this->findActivePolicyShape();
        if ($shape === null) {
            return '';
        }

        // The four scalar fields render as plain text — escape them
        // aggressively so a malformed contact name or version
        // string can never inject markup. The policy body is the
        // exception: it is the WYSIWYG output the editor authored,
        // so it is run through wp_kses_post() to strip dangerous
        // tags while preserving the formatting paragraphs, lists,
        // links and inline styles the editor expects to see.
        $contact  = esc_html((string) $shape['contact']);
        $email    = esc_html((string) $shape['contact_email']);
        $version  = esc_html((string) $shape['version']);
        $modified = esc_html((string) $shape['modified']);
        $body     = wp_kses_post((string) $shape['policy']);

        // The container element carries a stable class so themes
        // can target it without depending on internal markup. The
        // dt/dd pairing for the metadata block keeps the field
        // labels semantically associated with their values without
        // pulling in any layout opinions — sites that want a
        // different shape can override the CSS or pre-process the
        // shortcode output via the standard WP filter chain.
        return ''
            . '<div class="scrutiny-privacy-policy">'
            .   '<dl class="scrutiny-privacy-policy__meta">'
            .     '<dt>Member</dt><dd>' . $contact . '</dd>'
            .     '<dt>Email</dt><dd>' . $email . '</dd>'
            .     '<dt>Version</dt><dd>' . $version . '</dd>'
            .     '<dt>Updated</dt><dd>' . $modified . '</dd>'
            .   '</dl>'
            .   '<div class="scrutiny-privacy-policy__body">' . $body . '</div>'
            . '</div>';
    }

    /**
     * Locate the most-recent published active policy and project
     * it into the shared response shape, or return null when none
     * exists.
     *
     * The query mirrors {@see PrivacyPolicyController::getActive()}
     * exactly: same post type, same status, same ordering. We
     * deliberately re-issue the query here rather than calling the
     * REST callback because the REST surface returns a
     * `WP_REST_Response` (status, headers, JSON-shaped data) and
     * the shortcode needs the raw projection.
     *
     * Returns the formatter output directly (not the WP_Post)
     * because the only consumer — render() — needs the shape, and
     * resolving here means we read each policy's ACF fields
     * exactly once per shortcode invocation.
     *
     * @return array<string, mixed>|null
     */
    private function findActivePolicyShape(): ?array
    {
        $posts = get_posts([
            'post_type'      => PrivacyPolicyController::POST_TYPE,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ]);

        foreach ($posts as $post) {
            // The formatter does the strict boolean cast on the
            // active flag — ACF's true_false field can return a
            // mixture of 1, '1', true, '' depending on the storage
            // version, and we want a consistent answer across the
            // REST surface and the shortcode.
            $shape = $this->controller->formatPolicy($post);
            if ($shape['active']) {
                return $shape;
            }
        }

        return null;
    }
}
