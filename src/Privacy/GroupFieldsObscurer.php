<?php

declare(strict_types=1);

namespace Scrutiny\Privacy;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Scrutiny\Privacy\Interfaces\DataObscurer;
use WP_Post;
use function __;
use function add_action;
use function get_post_meta;
use function get_post_type;
use function is_admin;
use function wp_is_post_autosave;
use function wp_is_post_revision;
use function wp_json_encode;

/**
 * Group Fields Obscurer
 *
 * Obscures and write-protects TSML's nine named-contact fields
 * (contact_1_name … contact_3_phone) on the meeting and group edit
 * screens.
 *
 * Unlike the member ACF fields, these are plain WordPress postmeta
 * rendered by TSML's own meta box. The obscurer therefore works in
 * two layers:
 *
 *  1. Admin UI (admin_footer-post{,-new}.php) — adjusts each input
 *     based on the current user's tier:
 *
 *       EDIT → no changes, full access.
 *       VIEW → mark inputs readonly and show a "read-only" banner.
 *       NONE → replace each input's value with a masked placeholder
 *              AND mark readonly, so users see obscured previews
 *              but cannot edit them.
 *
 *     Masked values are injected from PHP at render time, late enough
 *     that TSML's meta box has already rendered.
 *
 *  2. Save hook (save_post_{tsml_meeting,tsml_group}) — strips the
 *     protected fields from $_POST before TSML's own save handler
 *     runs at priority 1, so DOM tampering (editing masked values,
 *     re-enabling readonly inputs, forging a hidden field) cannot
 *     commit changes.
 *
 * The save hook must register in every request (admin, REST, WP-CLI)
 * because save_post_* fires wherever a post is saved. The admin UI
 * hook only fires on admin page loads, so it's cheap to register
 * there unconditionally.
 */
final class GroupFieldsObscurer implements DataObscurer
{
    /**
     * Post types whose edit screen exposes the protected contact
     * fields. TSML renders the same contact meta box on both the
     * meeting and group edit screens.
     */
    private const SUPPORTED_POST_TYPES = ['tsml_meeting', 'tsml_group'];

    public function __construct(
        private readonly PersonalDataPolicy $policy,
    ) {
    }

    public function register(): void
    {
        // Save-time $_POST strip: always active.
        foreach (self::SUPPORTED_POST_TYPES as $postType) {
            add_action("save_post_{$postType}", [$this, 'stripProtectedFields'], 1, 2);
        }

        // Admin UI mask/lock: admin-only.
        if (is_admin()) {
            add_action('admin_footer-post.php', [$this, 'emitAdminUi']);
            add_action('admin_footer-post-new.php', [$this, 'emitAdminUi']);
        }
    }

    // ─── Save hook ────────────────────────────────────────────────────

    public function stripProtectedFields(int $postId, WP_Post $post): void
    {
        if (wp_is_post_autosave($postId) || wp_is_post_revision($postId)) {
            return;
        }
        if ($this->policy->currentUserCanEdit()) {
            return;
        }
        foreach (PersonalDataFields::protectedContactFields() as $field) {
            if (isset($_POST[$field])) {
                unset($_POST[$field]);
            }
        }
    }

    // ─── Admin UI hook ────────────────────────────────────────────────

    public function emitAdminUi(): void
    {
        // EDIT users have full access — nothing to lock, nothing to mask.
        if ($this->policy->currentUserCanEdit()) {
            return;
        }

        global $post_type;
        if (!in_array($post_type, self::SUPPORTED_POST_TYPES, true)) {
            return;
        }

        $protectedFields = PersonalDataFields::protectedContactFields();
        if (empty($protectedFields)) {
            return;
        }

        // Users who can view but not edit see readonly inputs with their
        // real values. Users who can do neither see readonly inputs with
        // masked placeholders computed server-side.
        //
        // IMPORTANT: TSML stores the nine named-contact fields on the
        // linked *group* post (pointed to by the meeting's `group_id`
        // meta), not on the meeting itself. Fall back to the meeting
        // post for standalone meetings that aren't part of a group.
        $canView = $this->policy->currentUserCanView();
        $maskedValues = [];
        if (!$canView) {
            $postId = $this->resolvePostId();
            if ($postId > 0) {
                $sourceId = $this->resolveContactSourceId($postId);
                foreach ($protectedFields as $field) {
                    $raw = $sourceId > 0
                        ? (string) get_post_meta($sourceId, $field, true)
                        : '';
                    $maskedValues[$field] = $this->policy->maskContactField($raw);
                }
            }
        }

        $bannerText = $canView
            ? __('Named contact fields are read-only.', 'scrutiny')
            : __('Named contact fields are hidden. Contact an administrator for access.', 'scrutiny');

        $this->renderCss();
        $this->renderJs($protectedFields, $maskedValues, $bannerText, !$canView);
    }

    /**
     * Resolve the post being edited. $GLOBALS['post'] isn't always
     * set by the time admin_footer fires on every WP version, so we
     * also fall back to $_GET['post'] (post.php).
     */
    private function resolvePostId(): int
    {
        global $post;
        if ($post instanceof WP_Post && $post->ID > 0) {
            return (int) $post->ID;
        }
        if (isset($_GET['post'])) {
            $id = (int) $_GET['post'];
            if ($id > 0) {
                return $id;
            }
        }
        return 0;
    }

    /**
     * Resolve where contact meta actually lives for the post being
     * edited.
     *
     *   Meeting post: read from its linked group (group_id meta). If
     *                 the meeting isn't part of a group, read from the
     *                 meeting itself as a fallback.
     *   Group post:   read from the group itself (it IS the source).
     */
    private function resolveContactSourceId(int $postId): int
    {
        $postType = get_post_type($postId);
        if ($postType === 'tsml_group') {
            return $postId;
        }
        // tsml_meeting (or anything else that reached us)
        $groupId = (int) get_post_meta($postId, 'group_id', true);
        return $groupId > 0 ? $groupId : $postId;
    }

    private function renderCss(): void
    {
        echo <<<'HTML'
            <style id="scrutiny-tsml-style">
                .scrutiny-tsml-locked input[type="text"],
                .scrutiny-tsml-locked input[type="email"],
                .scrutiny-tsml-locked input[type="tel"],
                .scrutiny-tsml-locked input:not([type]) {
                    background-color: #f0f0f1 !important;
                    color: #50575e !important;
                    cursor: not-allowed;
                }
                .scrutiny-tsml-banner {
                    margin: 0 0 10px;
                    padding: 6px 10px;
                    background: #fff8e5;
                    border-left: 3px solid #dba617;
                    font-style: italic;
                    color: #50575e;
                }
            </style>
        HTML;
    }

    /**
     * @param string[]              $fields
     * @param array<string, string> $masked field => masked preview
     */
    private function renderJs(array $fields, array $masked, string $banner, bool $applyMask): void
    {
        $fieldsJson = wp_json_encode($fields);
        $maskedJson = wp_json_encode((object) $masked);
        $bannerJson = wp_json_encode($banner);
        $applyMaskJs = $applyMask ? 'true' : 'false';

        echo <<<HTML
            <script id="scrutiny-tsml-script">
            (function () {
                var FIELDS = {$fieldsJson};
                var MASKED = {$maskedJson};
                var APPLY_MASK = {$applyMaskJs};
                var BANNER = {$bannerJson};

                function findInput(name) {
                    var box = document.getElementById("group");
                    var el = box ? box.querySelector('input[name="' + name + '"]') : null;
                    if (el) { return el; }
                    return document.querySelector('input[name="' + name + '"]');
                }

                function applyMaskTo(el, name) {
                    if (!APPLY_MASK) { return; }
                    if (!Object.prototype.hasOwnProperty.call(MASKED, name)) { return; }
                    var target = MASKED[name];
                    if (el.value !== target) {
                        el.value = target;
                        // Mirror onto the attribute so browsers that
                        // snapshot `defaultValue` reflect the masked state.
                        el.setAttribute("value", target);
                    }
                }

                function lockField(el, name) {
                    applyMaskTo(el, name);
                    el.readOnly = true;
                    el.setAttribute("aria-readonly", "true");
                    el.classList.add("scrutiny-tsml-locked");
                    if (el.parentNode) {
                        el.parentNode.classList.add("scrutiny-tsml-locked");
                    }

                    // Sticky mask: if anything else (TSML's own JS, an
                    // ajax hydrator, autocomplete, the user pasting)
                    // changes the value, force it back.
                    if (APPLY_MASK && !el.__scrutinyTsmlBound) {
                        el.__scrutinyTsmlBound = true;
                        var reapply = function () { applyMaskTo(el, name); };
                        el.addEventListener("input", reapply);
                        el.addEventListener("change", reapply);
                        el.addEventListener("focus", reapply);
                        // MutationObserver catches JS-driven .value = "…" changes.
                        try {
                            var mo = new MutationObserver(function () { reapply(); });
                            mo.observe(el, { attributes: true, attributeFilter: ["value"] });
                        } catch (e) { /* no-op */ }
                    }
                }

                function lockAll() {
                    FIELDS.forEach(function (name) {
                        var el = findInput(name);
                        if (!el) { return; }
                        lockField(el, name);
                    });

                    var box = document.getElementById("group");
                    var inside = box ? box.querySelector(".inside") : null;
                    if (inside && BANNER && !inside.querySelector(".scrutiny-tsml-banner")) {
                        var note = document.createElement("p");
                        note.className = "scrutiny-tsml-banner";
                        note.textContent = BANNER;
                        inside.insertBefore(note, inside.firstChild);
                    }
                }

                if (document.readyState === "loading") {
                    document.addEventListener("DOMContentLoaded", lockAll);
                } else {
                    lockAll();
                }
                // Run again after a short delay to beat any late
                // hydration that might populate inputs after
                // DOMContentLoaded.
                setTimeout(lockAll, 250);
                setTimeout(lockAll, 1000);
            })();
            </script>
        HTML;
    }
}
