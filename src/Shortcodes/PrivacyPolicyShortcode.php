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
 * The output renders the policy body as WordPress WYSIWYG markup
 * — the already-formatted HTML produced by ACF's `wpautop` and
 * shortcode resolution pipeline, so paragraphs, lists, and inline
 * formatting authored in the editor render as the editor intended.
 * Two scalar fields (version and GMT-modified timestamp) are
 * rendered as a `<dl>` block and appended to the end of the body —
 * the natural reading position for the "small print" tail of a
 * privacy notice.
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

        // The two scalar fields render as plain text — escape them
        // aggressively so a malformed version string can never inject
        // markup. The policy body is the exception: it is the WYSIWYG
        // output the editor authored, so it goes through a layered
        // pipeline — explicit removal of <style> and <script> blocks
        // (which can leak verbatim into the rendered page from a
        // careless paste), then wp_kses_post() to strip any remaining
        // dangerous tags while preserving the formatting paragraphs,
        // lists, links and inline styles the editor expects to see.
        $version  = esc_html((string) $shape['version']);
        $modified = esc_html((string) $shape['modified']);

        // Build the metadata block as a <dl>. Each pair sits on
        // its own line (a fresh <dt>/<dd> per row); the layout is
        // dictated by CSS, not inline styles, so themes can
        // override or restyle the block via the
        // scrutiny-privacy-policy__meta class without having to
        // outweigh inline declarations.
        $meta = ''
            . '<dl class="scrutiny-privacy-policy__meta">'
            .   '<dt>Version</dt><dd>' . $version . '</dd>'
            .   '<dt>Updated</dt><dd>' . $modified . '</dd>'
            . '</dl>';

        // Strip any <style>…</style> blocks before the kses pass.
        // wp_kses_post() allows <style> through (WP uses it for
        // editor-injected inline styles), but in the privacy-policy
        // context a leaked <style> block is almost always an
        // accidental copy-paste from a styled source — and depending
        // on where the rendered shortcode lands in the DOM, the
        // browser may treat the contents as visible text rather than
        // as a stylesheet, which is exactly what produced the "body
        // { font-family: … }" rendering bug. We also drop <script>
        // explicitly even though kses removes it, so that callers
        // reading this code don't have to verify the kses behaviour
        // to know dangerous tags are gone.
        $rawBody = (string) $shape['policy'];
        $rawBody = preg_replace('#<style\b[^>]*>.*?</style>#is', '', $rawBody) ?? $rawBody;
        $rawBody = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $rawBody) ?? $rawBody;

        // Append the metadata block to the end of the body. The
        // metadata is the "small print" tail of a privacy notice
        // — which version is in force, when it last changed — so
        // the natural reading position is after the policy text
        // rather than woven into it. Appending also sidesteps the
        // heading-detection logic the earlier injection-based
        // approach needed: a body without a recognisable <h1>/<h2>
        // structure is now handled by the same code path as a
        // fully-structured one.
        //
        // The metadata is concatenated before the kses pass so
        // the <dl>/<dt>/<dd> markup runs through the same
        // sanitiser as the authored body, which means a future
        // tightening of the allowed-tags list applies uniformly
        // to both.
        $rawBody = $rawBody . $meta;

        $body = wp_kses_post($rawBody);

        // The container element carries a stable class so themes
        // can target the rendered shortcode without depending on
        // internal markup.
        return '<div class="scrutiny-privacy-policy">' . $body . '</div>';
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
