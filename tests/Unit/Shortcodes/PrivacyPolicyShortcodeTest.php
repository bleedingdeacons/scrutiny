<?php

declare(strict_types=1);

namespace Scrutiny\Tests\Unit\Shortcodes;

use PHPUnit\Framework\TestCase;
use Scrutiny\Rest\PrivacyPolicyController;
use Scrutiny\Shortcodes\PrivacyPolicyShortcode;
use WP_Post;

/**
 * Tests for the PrivacyPolicyShortcode.
 *
 * The shortcode is the frontend twin of the REST controller. It
 * locates the active privacy policy via the same query the
 * controller's `getActive()` route uses, projects it through the
 * shared `formatPolicy()` formatter, and emits an HTML block with
 * four escaped scalar fields (contact, email, version, modified)
 * plus the WYSIWYG body filtered through wp_kses_post().
 *
 * Coverage focuses on:
 *   - Registration of the shortcode tag.
 *   - The active-selection rule (newest active wins; absent → empty).
 *   - The escaping contract (scalars are HTML-escaped; the policy
 *     body keeps safe markup but loses dangerous tags).
 *   - The output structure (so themes that target the documented
 *     class names don't break silently).
 */
class PrivacyPolicyShortcodeTest extends TestCase
{
    protected function setUp(): void
    {
        // Reset every in-memory store so one test's fixtures can't
        // bleed into the next.
        $GLOBALS['scrutiny_test_posts']       = [];
        $GLOBALS['scrutiny_test_acf_fields']  = [];
        $GLOBALS['scrutiny_test_shortcodes']  = [];
    }

    private function makeShortcode(): PrivacyPolicyShortcode
    {
        // The shortcode is just a thin wrapper over the controller's
        // formatter, so we can resolve it directly with a real
        // controller instance — no test double needed.
        return new PrivacyPolicyShortcode(new PrivacyPolicyController());
    }

    // ──────────────────────────────────────────────
    //  Registration
    // ──────────────────────────────────────────────

    /** @test */
    public function it_registers_the_documented_shortcode_tag(): void
    {
        // Regression guard: the class docblock and any user-facing
        // documentation pin the tag to `scrutiny_privacy_policy`.
        // Renaming it without an explicit deprecation cycle would
        // silently break every page that already embeds it.
        $this->makeShortcode()->register();

        $this->assertArrayHasKey(
            'scrutiny_privacy_policy',
            $GLOBALS['scrutiny_test_shortcodes']
        );
        $this->assertSame(
            PrivacyPolicyShortcode::TAG,
            'scrutiny_privacy_policy'
        );
    }

    /** @test */
    public function the_registered_callback_is_the_render_method(): void
    {
        // The handler must be the bound render() method, not a
        // static or a closure. WP invokes the callback with the
        // shortcode atts; if the registration ever drifts to a
        // different signature, the integration with WP breaks
        // without a clear failure mode.
        $shortcode = $this->makeShortcode();
        $shortcode->register();

        $callback = $GLOBALS['scrutiny_test_shortcodes']['scrutiny_privacy_policy'];
        $this->assertIsArray($callback);
        $this->assertSame($shortcode, $callback[0]);
        $this->assertSame('render', $callback[1]);
    }

    // ──────────────────────────────────────────────
    //  Selection rule (which policy wins)
    // ──────────────────────────────────────────────

    /** @test */
    public function it_renders_the_active_policy_when_one_is_published(): void
    {
        $this->seedPolicy(1, '2026-01-01 00:00:00', active: true);

        $output = $this->makeShortcode()->render();

        // Both the metadata block and the body container are
        // present, and the contact name authored on the fixture
        // appears in the output. Specific class names are checked
        // separately; here we just want to know the happy path
        // produces visible content.
        $this->assertStringContainsString('scrutiny-privacy-policy', $output);
        $this->assertStringContainsString('Contact 1', $output);
    }

    /** @test */
    public function it_emits_an_empty_string_when_no_policy_is_active(): void
    {
        // An admin who has installed the plugin but not yet
        // published a policy — or has unflagged the active one
        // mid-revision — should not see a user-facing error on
        // their public pages. Silence is the documented behaviour.
        $this->seedPolicy(1, '2026-01-01 00:00:00', active: false);
        $this->seedPolicy(2, '2026-02-01 00:00:00', active: false);

        $this->assertSame('', $this->makeShortcode()->render());
    }

    /** @test */
    public function it_emits_an_empty_string_when_no_policies_exist_at_all(): void
    {
        // The first-deploy state: the CPT is registered but no
        // posts have been authored. Same silent behaviour as the
        // "none active" case.
        $this->assertSame('', $this->makeShortcode()->render());
    }

    /** @test */
    public function the_newest_active_policy_wins_when_multiple_are_flagged(): void
    {
        // The schema doesn't strictly prevent two policies from
        // both having the active flag set — a configuration error,
        // but one a busy admin can plausibly create. The shortcode
        // must mirror the REST controller's `getActive()` rule:
        // newest wins, deterministically.
        $this->seedPolicy(1, '2026-01-01 00:00:00', active: true, contact: 'Older Officer');
        $this->seedPolicy(2, '2026-06-01 00:00:00', active: true, contact: 'Newer Officer');

        $output = $this->makeShortcode()->render();

        $this->assertStringContainsString('Newer Officer', $output);
        $this->assertStringNotContainsString('Older Officer', $output);
    }

    /** @test */
    public function it_skips_unpublished_policies_even_when_they_are_flagged_active(): void
    {
        // A draft or trashed policy that still has the active flag
        // ticked must never leak through the shortcode. The query
        // in findActivePolicyShape() asks for post_status=publish,
        // so the draft should be invisible. If a refactor relaxes
        // that filter, this test catches it.
        $GLOBALS['scrutiny_test_posts'][7] = new WP_Post([
            'ID'                => 7,
            'post_title'        => 'Draft Policy',
            'post_type'         => PrivacyPolicyController::POST_TYPE,
            'post_status'       => 'draft',
            'post_modified_gmt' => '2026-06-01 00:00:00',
            'post_date_gmt'     => '2026-06-01 00:00:00',
        ]);
        $GLOBALS['scrutiny_test_acf_fields'][7] = [
            'gdpr-policy-active'  => true,
            'gdpr-policy-contact' => 'Should Not Appear',
            'gdpr-contact-email'  => 'draft@example.org',
            'gdpr-policy'         => '<p>Draft body.</p>',
            'gdpr-policy-version' => '1.0',
        ];

        $this->assertSame('', $this->makeShortcode()->render());
    }

    // ──────────────────────────────────────────────
    //  Output shape and escaping
    // ──────────────────────────────────────────────

    /** @test */
    public function it_renders_all_four_metadata_fields_with_their_labels(): void
    {
        // The four metadata fields the user explicitly asked for —
        // member (contact), email, version, updated (modified) —
        // must each appear under a recognisable label so a reader
        // can tell which value is which. The dt/dd structure is a
        // documented contract; themes target the labels and class
        // names directly.
        $this->seedPolicy(
            1,
            '2026-04-15 09:30:00',
            active: true,
            contact: 'Data Protection Officer',
            email: 'dpo@example.org',
            version: '2.1',
            body: '<p>Body text.</p>',
        );

        $output = $this->makeShortcode()->render();

        $this->assertStringContainsString('<dt>Contact</dt>', $output);
        $this->assertStringContainsString('<dd>Data Protection Officer</dd>', $output);

        $this->assertStringContainsString('<dt>Contact Email</dt>', $output);
        $this->assertStringContainsString('<dd>dpo@example.org</dd>', $output);

        $this->assertStringContainsString('<dt>Version</dt>', $output);
        $this->assertStringContainsString('<dd>2.1</dd>', $output);

        $this->assertStringContainsString('<dt>Updated</dt>', $output);
        // The modified date is the ISO-8601 GMT projection from
        // the controller's formatter — pinning the literal value
        // here also pins the upstream date contract.
        $this->assertStringContainsString(
            '<dd>2026-04-15T09:30:00+00:00</dd>',
            $output
        );
    }

    /** @test */
    public function it_renders_the_policy_body_inside_a_dedicated_container(): void
    {
        // The WYSIWYG body sits in its own block so theme CSS can
        // distinguish "metadata pair" from "rendered policy text".
        // If the wrapper class drifts, every theme overriding it
        // breaks silently.
        $this->seedPolicy(
            1,
            '2026-01-01 00:00:00',
            active: true,
            body: '<p>Section one.</p><p>Section two.</p>',
        );

        $output = $this->makeShortcode()->render();

        $this->assertStringContainsString(
            '<div class="scrutiny-privacy-policy__body">',
            $output
        );
        $this->assertStringContainsString('<p>Section one.</p>', $output);
        $this->assertStringContainsString('<p>Section two.</p>', $output);
    }

    /** @test */
    public function it_html_escapes_the_scalar_metadata_fields(): void
    {
        // Defence in depth: ACF should never store HTML in these
        // fields, but a misconfigured input or a future field that
        // accepts free text shouldn't be able to inject markup
        // into the rendered output. Each scalar is run through
        // esc_html(); a stray angle bracket in any of them must
        // come back encoded.
        $this->seedPolicy(
            1,
            '2026-01-01 00:00:00',
            active: true,
            contact: 'Officer <script>',
            email: 'a@b.com" onmouseover="x',
            version: '1.0 & 2.0',
        );

        $output = $this->makeShortcode()->render();

        $this->assertStringNotContainsString('Officer <script>', $output);
        $this->assertStringContainsString('Officer &lt;script&gt;', $output);
        $this->assertStringNotContainsString('onmouseover="x', $output);
        $this->assertStringContainsString('1.0 &amp; 2.0', $output);
    }

    /** @test */
    public function it_preserves_safe_wysiwyg_markup_in_the_policy_body(): void
    {
        // The whole point of the policy field being a WYSIWYG is
        // that authored formatting — paragraphs, lists, links,
        // emphasis — round-trips to the rendered page. Strip any
        // of that and the editor's intent is lost.
        $body = '<p>We <strong>store</strong> data.</p>'
              . '<ul><li>Email</li><li>Phone</li></ul>'
              . '<p>See <a href="https://example.org/privacy">our notice</a>.</p>';

        $this->seedPolicy(1, '2026-01-01 00:00:00', active: true, body: $body);

        $output = $this->makeShortcode()->render();

        $this->assertStringContainsString('<strong>store</strong>', $output);
        $this->assertStringContainsString('<ul><li>Email</li><li>Phone</li></ul>', $output);
        $this->assertStringContainsString(
            '<a href="https://example.org/privacy">our notice</a>',
            $output
        );
    }

    /** @test */
    public function it_strips_dangerous_markup_from_the_policy_body(): void
    {
        // The policy body is stored as-authored and rendered as
        // HTML, so the kses pass is the only line of defence
        // against a compromised editor account leaving an XSS
        // payload in a privacy notice that every visitor renders.
        // Both <script> blocks and inline event handlers must go.
        $body = '<p>Safe.</p>'
              . '<script>alert(1)</script>'
              . '<p onclick="alert(2)">Click</p>';

        $this->seedPolicy(1, '2026-01-01 00:00:00', active: true, body: $body);

        $output = $this->makeShortcode()->render();

        $this->assertStringContainsString('<p>Safe.</p>', $output);
        $this->assertStringNotContainsString('<script>', $output);
        $this->assertStringNotContainsString('alert(1)', $output);
        $this->assertStringNotContainsString('onclick=', $output);
        // The "Click" text itself stays — only the dangerous
        // attribute is stripped, not the tag containing it.
        $this->assertStringContainsString('Click', $output);
    }

    /** @test */
    public function it_strips_style_blocks_from_the_policy_body(): void
    {
        // Regression guard: an early version rendered <style>
        // blocks pasted into the WYSIWYG verbatim. Depending on
        // where the shortcode lands in the page DOM, browsers can
        // surface those rules as visible CSS-source text rather
        // than applying them as a stylesheet — the author who
        // pasted "body { font-family: … }" into their policy
        // expected neither outcome. Stripping <style> entirely is
        // safer than trusting either rendering path.
        $body = '<style>body { color: red; }</style>'
              . '<p>Section one.</p>'
              . '<style type="text/css">.x { display: none }</style>'
              . '<p>Section two.</p>';

        $this->seedPolicy(1, '2026-01-01 00:00:00', active: true, body: $body);

        $output = $this->makeShortcode()->render();

        $this->assertStringNotContainsString('<style', $output);
        $this->assertStringNotContainsString('font-family', $output);
        $this->assertStringNotContainsString('color: red', $output);
        $this->assertStringNotContainsString('display: none', $output);
        // Surrounding paragraphs survive — the strip is scoped to
        // the <style> tags, not to neighbouring content.
        $this->assertStringContainsString('<p>Section one.</p>', $output);
        $this->assertStringContainsString('<p>Section two.</p>', $output);
    }

    /** @test */
    public function it_accepts_the_atts_argument_wordpress_always_supplies(): void
    {
        // WordPress invokes shortcode handlers with the parsed
        // attributes as the first positional argument, even when
        // none are written in the source. The render() method
        // must not blow up if WP passes an empty array, a
        // non-empty array, or — for some legacy callers — an
        // empty string.
        $this->seedPolicy(1, '2026-01-01 00:00:00', active: true);

        $shortcode = $this->makeShortcode();

        $this->assertNotSame('', $shortcode->render([]));
        $this->assertNotSame('', $shortcode->render(['ignored' => 'yes']));
        $this->assertNotSame('', $shortcode->render(''));
    }

    // ──────────────────────────────────────────────
    //  Helpers
    // ──────────────────────────────────────────────

    /**
     * Seed a published policy fixture. Mirrors the helper in
     * PrivacyPolicyControllerTest so both suites describe the
     * fixture surface in the same vocabulary; the named-arg
     * overrides cover the four scalar fields and the body the
     * shortcode actually surfaces in its output.
     */
    private function seedPolicy(
        int $id,
        string $gmt,
        bool $active = false,
        string $contact = '',
        string $email = '',
        string $version = '',
        string $body = '',
    ): void {
        $GLOBALS['scrutiny_test_posts'][$id] = new WP_Post([
            'ID'                => $id,
            'post_title'        => "Policy {$id}",
            'post_type'         => PrivacyPolicyController::POST_TYPE,
            'post_status'       => 'publish',
            'post_modified_gmt' => $gmt,
            'post_date_gmt'     => $gmt,
        ]);

        $GLOBALS['scrutiny_test_acf_fields'][$id] = [
            'gdpr-policy-contact' => $contact !== '' ? $contact : "Contact {$id}",
            'gdpr-contact-email'  => $email   !== '' ? $email   : "contact{$id}@example.org",
            'gdpr-policy'         => $body    !== '' ? $body    : "<p>Policy {$id} body.</p>",
            'gdpr-policy-version' => $version !== '' ? $version : "1.{$id}",
            'gdpr-policy-active'  => $active,
        ];
    }
}
