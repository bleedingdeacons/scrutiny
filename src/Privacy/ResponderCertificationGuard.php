<?php

declare(strict_types=1);

namespace Scrutiny\Privacy;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Unity\Core\Interfaces\Configuration;
use Unity\Members\Interfaces\Member;

use function add_filter;
use function current_user_can;
use function get_field;

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

        if (!$this->currentUserCanEdit()) {
            $field['disabled'] = 1;
        }

        return $field;
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
