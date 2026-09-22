<?php

declare(strict_types=1);

namespace Scrutiny\Tests\Unit\Shortcodes;

use Scrutiny\Privacy\PrivacyPolicyFormatter;
use Scrutiny\Rest\PrivacyPolicyController;
use Scrutiny\Shortcodes\PrivacyPolicyShortcode;
use Scrutiny\Tests\Unit\Rest\GlobalsBackedPrivacyPolicyRepository;
use WP_Post;

require_once __DIR__ . '/../Rest/StubPrivacyPolicy.php';
require_once __DIR__ . '/../Rest/GlobalsBackedPrivacyPolicyRepository.php';

/*
 * Tests for the PrivacyPolicyShortcode.
 *
 * The shortcode is the frontend twin of the REST controller. It locates the
 * active privacy policy via the same query the controller's `getActive()`
 * route uses, projects it through the shared
 * {@see \Scrutiny\Privacy\PrivacyPolicyFormatter}, and emits an HTML block
 * with two escaped scalar fields (version, modified) plus the WYSIWYG body
 * filtered through wp_kses_post().
 *
 * Coverage focuses on:
 *   - Registration of the shortcode tag.
 *   - The active-selection rule (newest active wins; absent → empty).
 *   - The escaping contract (scalars are HTML-escaped; the policy body keeps
 *     safe markup but loses dangerous tags).
 *   - The output structure (so themes that target the documented class names
 *     don't break silently).
 */

const META_BLOCK = '<dl class="scrutiny-privacy-policy__meta">';

function policyShortcode(): PrivacyPolicyShortcode
{
    // The shortcode delegates to the repository for active-policy lookup and
    // to the formatter for projection. Wiring real collaborators (rather than
    // mocks) keeps this suite an integration check between the three classes
    // — if either collaborator's contract drifts, the rendered output changes
    // here.
    return new PrivacyPolicyShortcode(new GlobalsBackedPrivacyPolicyRepository(), new PrivacyPolicyFormatter());
}

/**
 * Seed a published policy fixture. Mirrors the helper in
 * PrivacyPolicyControllerTest so both suites describe the fixture surface in
 * the same vocabulary; the named-arg overrides cover the two scalar fields and
 * the body the shortcode actually surfaces in its output.
 */
function seedShortcodePolicy(
    int $id,
    string $gmt,
    bool $active = false,
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
        'gdpr-policy'         => $body    !== '' ? $body    : "<p>Policy {$id} body.</p>",
        'gdpr-policy-version' => $version !== '' ? $version : "1.{$id}",
        'gdpr-policy-active'  => $active,
    ];
}

/** The active policy's rendered output, seeded with $body. */
function renderedPolicyWithBody(string $body): string
{
    seedShortcodePolicy(1, '2026-01-01 00:00:00', active: true, body: $body);

    return policyShortcode()->render();
}

beforeEach(function () {
    // Reset every in-memory store so one test's fixtures can't bleed into the
    // next.
    $GLOBALS['scrutiny_test_posts']      = [];
    $GLOBALS['scrutiny_test_acf_fields'] = [];
    $GLOBALS['scrutiny_test_shortcodes'] = [];
});

// ──────────────────────────────────────────────
//  Registration
// ──────────────────────────────────────────────
describe('registration', function () {
    it('registers the documented shortcode tag', function () {
        // Regression guard: the class docblock and any user-facing
        // documentation pin the tag to `scrutiny_privacy_policy`. Renaming it
        // without an explicit deprecation cycle would silently break every
        // page that already embeds it.
        policyShortcode()->register();

        expect($GLOBALS['scrutiny_test_shortcodes'])->toHaveKey('scrutiny_privacy_policy')
            ->and(PrivacyPolicyShortcode::TAG)->toBe('scrutiny_privacy_policy');
    });

    it('registers the render method as the callback', function () {
        // The handler must be the bound render() method, not a static or a
        // closure. WP invokes the callback with the shortcode atts; if the
        // registration ever drifts to a different signature, the integration
        // with WP breaks without a clear failure mode.
        $shortcode = policyShortcode();
        $shortcode->register();

        expect($GLOBALS['scrutiny_test_shortcodes']['scrutiny_privacy_policy'])->toBe([$shortcode, 'render']);
    });
});

// ──────────────────────────────────────────────
//  Selection rule (which policy wins)
// ──────────────────────────────────────────────
describe('which policy is shown', function () {
    it('renders the active policy when one is published', function () {
        seedShortcodePolicy(1, '2026-01-01 00:00:00', active: true);

        // Both the metadata block and the body container are present, and the
        // policy body authored on the fixture appears in the output. Specific
        // class names are checked separately; here we just want to know the
        // happy path produces visible content.
        expect(policyShortcode()->render())->toContain('scrutiny-privacy-policy', 'Policy 1 body');
    });

    it('emits an empty string when no policy is active', function () {
        // An admin who has installed the plugin but not yet published a
        // policy — or has unflagged the active one mid-revision — should not
        // see a user-facing error on their public pages. Silence is the
        // documented behaviour.
        seedShortcodePolicy(1, '2026-01-01 00:00:00', active: false);
        seedShortcodePolicy(2, '2026-02-01 00:00:00', active: false);

        expect(policyShortcode()->render())->toBe('');
    });

    it('emits an empty string when no policies exist at all', function () {
        // The first-deploy state: the CPT is registered but no posts have been
        // authored. Same silent behaviour as the "none active" case.
        expect(policyShortcode()->render())->toBe('');
    });

    it('shows the newest active policy when several are flagged', function () {
        // The schema doesn't strictly prevent two policies from both having
        // the active flag set — a configuration error, but one a busy admin
        // can plausibly create. The shortcode must mirror the REST
        // controller's `getActive()` rule: newest wins, deterministically.
        seedShortcodePolicy(1, '2026-01-01 00:00:00', active: true, version: '1.0-old');
        seedShortcodePolicy(2, '2026-06-01 00:00:00', active: true, version: '2.0-new');

        expect(policyShortcode()->render())
            ->toContain('2.0-new')
            ->not->toContain('1.0-old');
    });

    it('skips unpublished policies even when they are flagged active', function () {
        // A draft or trashed policy that still has the active flag ticked must
        // never leak through the shortcode. The query in
        // findActivePolicyShape() asks for post_status=publish, so the draft
        // should be invisible. If a refactor relaxes that filter, this test
        // catches it.
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
            'gdpr-policy'         => '<p>Draft body.</p>',
            'gdpr-policy-version' => '1.0',
        ];

        expect(policyShortcode()->render())->toBe('');
    });
});

// ──────────────────────────────────────────────
//  Output shape and escaping
// ──────────────────────────────────────────────
describe('output shape', function () {
    it('renders both metadata fields with their labels', function () {
        // The two metadata fields the shortcode surfaces — version and updated
        // (modified) — must each appear under a recognisable label so a reader
        // can tell which value is which. The dt/dd structure is a documented
        // contract; themes target the labels and class names directly. This
        // test also pins the absence of the old contact/email rows so a
        // regression that re-adds them is caught.
        seedShortcodePolicy(1, '2026-04-15 09:30:00', active: true, version: '2.1', body: '<p>Body text.</p>');

        expect(policyShortcode()->render())
            ->toContain('<dt>Version</dt>', '<dd>2.1</dd>', '<dt>Updated</dt>')
            // The modified date is the ISO-8601 GMT projection from the
            // controller's formatter — pinning the literal value here also
            // pins the upstream date contract.
            ->toContain('<dd>2026-04-15T09:30:00+00:00</dd>')
            // The contact and contact-email rows were removed from the
            // shortcode's output; they must not reappear under either their
            // label or their underlying ACF values.
            ->not->toContain('<dt>Contact</dt>')
            ->not->toContain('<dt>Contact Email</dt>');
    });

    it('renders the policy body in full', function () {
        // The WYSIWYG body must reach the rendered output intact — every
        // authored paragraph, in order, with no truncation from the
        // metadata-injection pass. Previous renderings wrapped the body in its
        // own container; the current shortcode flattens it into the outer
        // wrapper instead, so the test pins the visible-content guarantee
        // rather than the (now-removed) inner div.
        expect(renderedPolicyWithBody('<p>Section one.</p><p>Section two.</p>'))->toContain(
            '<div class="scrutiny-privacy-policy">',
            '<p>Section one.</p>',
            '<p>Section two.</p>',
        );
    });
});

// ──────────────────────────────────────────────
//  Metadata placement
// ──────────────────────────────────────────────
describe('metadata placement', function () {
    it('appends the metadata after the policy body', function () {
        // The metadata is the small-print tail of the policy — contact,
        // version, last-modified — so the rendered page reads "policy text
        // first, metadata last". Every body element must appear before the
        // <dl>; pinning the order with strpos catches any future refactor that
        // puts the block somewhere else.
        $output = renderedPolicyWithBody(
            '<h1>AA Intergroup Privacy Policy</h1>'
            . '<h2>1. Purpose</h2>'
            . '<p>This intergroup keeps limited contact details.</p>'
            . '<h2>2. What we hold</h2>'
            . '<p>Name, email, role.</p>'
        );

        $positions = array_map(static fn (string $needle) => strpos($output, $needle), [
            '<h1>AA Intergroup Privacy Policy</h1>',
            '<h2>1. Purpose</h2>',
            '<h2>2. What we hold</h2>',
            '<p>Name, email, role.</p>',
            META_BLOCK,
        ]);

        // Document order: h1, first h2, second h2, last paragraph, then the
        // metadata block. The metadata trails everything the editor authored.
        expect($positions)->each->not->toBeFalse();

        $sorted = $positions;
        sort($sorted);

        expect($positions)->toBe($sorted);
    });

    it('appends the metadata when the body has no headings', function () {
        // The degenerate case: a single block of prose with no headings at
        // all. The metadata still trails the body — the placement rule is
        // uniform across body shapes, so a policy authored as one flat
        // paragraph and a policy authored with full sectioning land their
        // metadata in the same relative position.
        $output = renderedPolicyWithBody('<p>One long paragraph of policy text.</p>');

        $bodyPos = strpos($output, '<p>One long paragraph');
        $metaPos = strpos($output, META_BLOCK);

        expect($bodyPos)->not->toBeFalse()
            ->and($metaPos)->not->toBeFalse()
            ->and($bodyPos)->toBeLessThan($metaPos);
    });

    it('shows the metadata block exactly once', function () {
        // Defence in depth: the append is a single string concatenation, so
        // duplication is unlikely — but a future refactor might reintroduce a
        // regex-based placement, and the "exactly one block" guarantee is what
        // keeps the rendered page from showing the small-print twice on a
        // multi-section policy.
        $output = renderedPolicyWithBody(
            '<h1>Title</h1>'
            . '<h2>One</h2><p>a</p>'
            . '<h2>Two</h2><p>b</p>'
            . '<h2>Three</h2><p>c</p>'
        );

        expect(substr_count($output, META_BLOCK))->toBe(1);
    });
});

describe('escaping', function () {
    it('HTML-escapes the scalar metadata fields', function () {
        // Defence in depth: ACF should never store HTML in these fields, but a
        // misconfigured input or a future field that accepts free text
        // shouldn't be able to inject markup into the rendered output. Each
        // scalar is run through esc_html(); a stray angle bracket or ampersand
        // in any of them must come back encoded.
        seedShortcodePolicy(1, '2026-01-01 00:00:00', active: true, version: '1.0 & 2.0 <script>');

        expect(policyShortcode()->render())
            ->not->toContain('1.0 & 2.0 <script>')
            ->toContain('1.0 &amp; 2.0 &lt;script&gt;');
    });

    it('preserves safe WYSIWYG markup in the policy body', function () {
        // The whole point of the policy field being a WYSIWYG is that authored
        // formatting — paragraphs, lists, links, emphasis — round-trips to the
        // rendered page. Strip any of that and the editor's intent is lost.
        expect(renderedPolicyWithBody(
            '<p>We <strong>store</strong> data.</p>'
            . '<ul><li>Email</li><li>Phone</li></ul>'
            . '<p>See <a href="https://example.org/privacy">our notice</a>.</p>'
        ))->toContain(
            '<strong>store</strong>',
            '<ul><li>Email</li><li>Phone</li></ul>',
            '<a href="https://example.org/privacy">our notice</a>',
        );
    });

    it('strips dangerous markup from the policy body', function () {
        // The policy body is stored as-authored and rendered as HTML, so the
        // kses pass is the only line of defence against a compromised editor
        // account leaving an XSS payload in a privacy notice that every
        // visitor renders. Both <script> blocks and inline event handlers must
        // go.
        expect(renderedPolicyWithBody(
            '<p>Safe.</p>'
            . '<script>alert(1)</script>'
            . '<p onclick="alert(2)">Click</p>'
        ))
            ->toContain('<p>Safe.</p>')
            ->not->toContain('<script>')
            ->not->toContain('alert(1)')
            ->not->toContain('onclick=')
            // The "Click" text itself stays — only the dangerous attribute is
            // stripped, not the tag containing it.
            ->toContain('Click');
    });

    it('strips style blocks from the policy body', function () {
        // Regression guard: an early version rendered <style> blocks pasted
        // into the WYSIWYG verbatim. Depending on where the shortcode lands in
        // the page DOM, browsers can surface those rules as visible CSS-source
        // text rather than applying them as a stylesheet — the author who
        // pasted "body { font-family: … }" into their policy expected neither
        // outcome. Stripping <style> entirely is safer than trusting either
        // rendering path.
        expect(renderedPolicyWithBody(
            '<style>body { color: red; }</style>'
            . '<p>Section one.</p>'
            . '<style type="text/css">.x { display: none }</style>'
            . '<p>Section two.</p>'
        ))
            ->not->toContain('<style')
            ->not->toContain('font-family')
            ->not->toContain('color: red')
            ->not->toContain('display: none')
            // Surrounding paragraphs survive — the strip is scoped to the
            // <style> tags, not to neighbouring content.
            ->toContain('<p>Section one.</p>', '<p>Section two.</p>');
    });
});

// WordPress invokes shortcode handlers with the parsed attributes as the first
// positional argument, even when none are written in the source. The render()
// method must not blow up if WP passes an empty array, a non-empty array, or —
// for some legacy callers — an empty string.
it('accepts the atts argument WordPress always supplies', function (array|string $atts) {
    seedShortcodePolicy(1, '2026-01-01 00:00:00', active: true);

    expect(policyShortcode()->render($atts))->not->toBe('');
})->with([
    'an empty array'    => [[]],
    'a non-empty array' => [['ignored' => 'yes']],
    'an empty string'   => [''],
]);
