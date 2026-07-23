<?php

declare(strict_types=1);

namespace Scrutiny\Privacy;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Unity\Core\Interfaces\Configuration;
use Unity\Members\Interfaces\Member;

use function add_action;
use function add_filter;
use function current_user_can;
use function get_current_screen;
use function get_field;
use function wp_add_inline_style;
use function wp_enqueue_style;
use function wp_register_style;

/**
 * Responder Certification Guard
 *
 * Protects the member `responder-certification` ACF field so that its
 * value stays visible to everyone who can see the member edit screen, but
 * is only changeable by users holding {@see self::EDIT_CAPABILITY}.
 *
 * This mirrors the edit-side protection {@see MemberFieldsObscurer} applies
 * to the personal-email and mobile-number fields, minus the view/obscuring
 * tier: a certification stage is not personal data, so it is never masked —
 * it is simply read-only for users without the capability.
 *
 * Two layers enforce this:
 *
 *   acf/prepare_field – renders the radio disabled on the edit form, so the
 *                       current stage is shown but cannot be changed.
 *   acf/update_value  – preserves the stored value on save for users without
 *                       the capability. This is the authoritative gate: a
 *                       disabled radio submits nothing (which would otherwise
 *                       leave the value untouched anyway), but a hand-crafted
 *                       POST could re-enable the input, so the save path — not
 *                       the DOM attribute — is where the rule is enforced.
 *
 * A small stylesheet ({@see self::enqueueReadOnlyStyle()}) additionally greys
 * the locked field, since ACF disables the radio inputs but does not otherwise
 * make a read-only field look any different from an editable one.
 *
 * Key-based filter variants are used throughout. For sub-fields inside ACF
 * groups (the certification field lives in `service-layout-group`) name-based
 * filters resolve inconsistently, whereas the key-based prepare_field and
 * update_value variants fire exactly once with the correct field.
 */
final class ResponderCertificationGuard
{
    /**
     * Capability that grants the right to change a member's responder
     * certification stage. Assigned to administrators on activation.
     */
    public const EDIT_CAPABILITY = 'scrutiny_edit_responder_certification';

    /**
     * CSS class added to the field wrapper when it is locked, targeted by the
     * read-only stylesheet enqueued in {@see self::enqueueReadOnlyStyle()}.
     */
    private const READONLY_CLASS = 'scrutiny-cert-readonly';

    private readonly array $member_config;

    public function __construct(Configuration $configuration)
    {
        $this->member_config = $configuration->getConfig(Member::class);
    }

    public function register(): void
    {
        $key = $this->member_config['KEY_RESPONDER_CERTIFICATION'] ?? '';

        if ($key === '') {
            return;
        }

        // Admin edit form: show the current stage but disable the input for
        // users who cannot edit, so the value is visible but not editable.
        add_filter('acf/prepare_field/key=' . $key, [$this, 'disableForReadOnlyUser']);

        // Save guard: preserve the stored value for users without the edit
        // capability. This is the real check — the disabled attribute above
        // is only a UI affordance.
        add_filter('acf/update_value/key=' . $key, [$this, 'preserveCertification'], 10, 3);

        // Read-only styling: ACF disables the radio inputs but does not grey
        // the field, so a locked field can look identical to an editable one.
        // Add a small stylesheet on the member edit screen that dims the field
        // and shows a not-allowed cursor when the current user cannot edit.
        add_action('acf/input/admin_enqueue_scripts', [$this, 'enqueueReadOnlyStyle']);
    }

    /**
     * Whether the current user may change the responder-certification value.
     */
    public function currentUserCanEdit(): bool
    {
        return current_user_can(self::EDIT_CAPABILITY);
    }

    /**
     * ACF prepare_field: disable the certification radio for users who lack
     * the edit capability, so the value is visible but not editable.
     *
     * @param array|false $field The ACF field array, or false if already hidden
     * @return array|false The modified field array
     */
    public function disableForReadOnlyUser(array|false $field): array|false
    {
        if ($field === false) {
            return $field;
        }

        if ($this->currentUserCanEdit()) {
            return $field;
        }

        // ACF's radio field does NOT treat $field['disabled'] as a boolean.
        // It reads it as a list of choice *values* to disable individually —
        // render_field() does `acf_in_array($value, $field['disabled'])` per
        // choice (class-acf-field-radio.php). Setting it to 1 disables
        // nothing and leaves the field fully editable. Disable every choice so
        // the current selection stays visible (a disabled+checked radio still
        // renders as selected) but none can be changed. A checkbox field has
        // the same semantics; anything else falls back to the boolean form.
        $type = $field['type'] ?? '';
        if ($type === 'radio' || $type === 'checkbox') {
            $field['disabled'] = array_keys($field['choices'] ?? []);
        } else {
            $field['disabled'] = 1;
        }

        // Tag the field wrapper so the read-only stylesheet can grey it out.
        // ACF disables the inputs but leaves the field looking editable, so
        // this class is what makes "not editable" visible.
        $existingClass = $field['wrapper']['class'] ?? '';
        $field['wrapper']['class'] = trim($existingClass . ' ' . self::READONLY_CLASS);

        return $field;
    }

    /**
     * Enqueue the read-only stylesheet on the member edit screen for users
     * who cannot edit the certification. Dims the tagged field and shows a
     * not-allowed cursor so it is visibly locked, not just inert.
     */
    public function enqueueReadOnlyStyle(): void
    {
        if ($this->currentUserCanEdit()) {
            return;
        }

        $screen = get_current_screen();
        $postType = $this->member_config['POST_TYPE'] ?? '';
        if (!$screen || $postType === '' || $screen->post_type !== $postType) {
            return;
        }

        // Registering a src-less handle is the supported way to attach inline
        // CSS without shipping a stylesheet file for a rule set this small.
        wp_register_style('scrutiny-cert-readonly', false);
        wp_enqueue_style('scrutiny-cert-readonly');
        wp_add_inline_style(
            'scrutiny-cert-readonly',
            '.acf-field.' . self::READONLY_CLASS . '{opacity:.6;}'
            . '.acf-field.' . self::READONLY_CLASS . ' label,'
            . '.acf-field.' . self::READONLY_CLASS . ' input{cursor:not-allowed;}'
        );
    }

    /**
     * ACF update_value: preserve the stored certification value when the
     * current user lacks the edit capability.
     *
     * @param mixed $value The new value being saved
     * @param mixed $postId The post ID
     * @param array $field The ACF field array
     * @return mixed The value to save
     */
    public function preserveCertification(mixed $value, mixed $postId, array $field): mixed
    {
        // Step out of the way for REST API writes. Trusted server-side
        // callers (notably Integrity) reach update_field() via their own
        // REST endpoints with their own permission system; the WordPress
        // capability gate below assumes a request with a current user
        // (admin form, acf_form()) and would otherwise drop their value.
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return $value;
        }

        if ($this->currentUserCanEdit()) {
            return $value;
        }

        $numericPostId = is_numeric($postId) ? (int) $postId : 0;
        $fieldName = $this->member_config['FIELD_RESPONDER_CERTIFICATION'] ?? '';

        $existing = $fieldName !== '' ? get_field($fieldName, $numericPostId, false) : null;

        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        // No stored value yet — let the incoming value through so a
        // newly-flagged responder can still be given a starting stage by
        // whatever process created them.
        return $value;
    }
}
