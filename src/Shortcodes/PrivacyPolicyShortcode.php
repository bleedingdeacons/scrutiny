<?php

declare(strict_types=1);

namespace Scrutiny\Shortcodes;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Scrutiny\Privacy\PrivacyPolicyFormatter;
use Unity\PrivacyPolicies\Interfaces\PrivacyPolicyRepository;
use function add_shortcode;
use function esc_html;
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
 * Selection rule: the repository's
 * {@see PrivacyPolicyRepository::findActive()} decides which policy
 * is "active". The REST controller's `getActive()` route uses the
 * same call, so the shortcode and the REST endpoint cannot disagree
 * about which policy is in force on the same page load.
 *
 * If no active policy is published, the shortcode emits an empty
 * string. The deliberate silence avoids dropping a visible
 * "no policy configured" placeholder onto a public page during the
 * window between deploying the plugin and publishing the first
 * policy — exactly the moment when an admin is least likely to want
 * a user-facing error.
 *
 * The class is intentionally thin. Two collaborators do the work:
 * the repository finds the active policy, and the formatter
 * projects it into the shared shape. The same formatter backs the
 * REST controller, so a future change to the policy shape only has
 * to be made in one place — and the shortcode no longer reaches
 * across the layering boundary into the REST package to borrow a
 * projection.
 */
final class PrivacyPolicyShortcode
{
    public const TAG = 'scrutiny_privacy_policy';

    /**
     * The repository sources the active policy; the formatter
     * projects it into the shared shape used by the REST endpoints
     * too. Holding both as references — rather than `new`-ing them
     * per shortcode call — lets the container manage their
     * lifecycles and lets tests inject stubs when they need to.
     */
    public function __construct(
        private readonly PrivacyPolicyRepository $repository,
        private readonly PrivacyPolicyFormatter $formatter,
    ) {
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
        $policy = $this->repository->findActive();
        if ($policy === null) {
            return '';
        }

        $shape = $this->formatter->format($policy);

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
}
