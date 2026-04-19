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
use function get_field;

/**
 * Member Fields Obscurer
 *
 * Obscures the two ACF personal-data fields (personal email and mobile
 * number) on the Unity Member edit screen and anywhere those fields are
 * read via get_field().
 *
 * Users with {@see PersonalDataPolicy::VIEW_CAPABILITY} see unobscured
 * values; all other users see the fixed-width placeholder.
 *
 * Users with {@see PersonalDataPolicy::EDIT_CAPABILITY} may update
 * personal data fields; all other users have their changes silently
 * rejected and the existing stored value preserved.
 */
final class MemberFieldsObscurer
{
    private readonly array $member_config;

    public function __construct(
        Configuration $configuration,
        private readonly PersonalDataPolicy $policy,
    ) {
        $this->member_config = $configuration->getConfig(Member::class);
    }

    public function register(): void
    {
        // The config stores full ACF sub-field names including the group prefix,
        // e.g. "about-layout-group_personal-email". ACF filter variants behave
        // differently depending on context:
        //
        //   acf/format_value/name=  → matches the full name used in get_field()
        //   acf/prepare_field/name= → matches the field's _name (sub-field part only)
        //
        // We register both variants for full coverage.

        $emailFieldFull = $this->member_config['FIELD_PERSONAL_EMAIL'] ?? '';
        $mobileFieldFull = $this->member_config['FIELD_MOBILE_NUMBER'] ?? '';

        // Extract the sub-field _name (part after the group prefix separator "_")
        // e.g. "about-layout-group_personal-email" → "personal-email"
        $emailFieldShort = str_contains($emailFieldFull, '_')
            ? substr($emailFieldFull, strpos($emailFieldFull, '_') + 1)
            : $emailFieldFull;
        $mobileFieldShort = str_contains($mobileFieldFull, '_')
            ? substr($mobileFieldFull, strpos($mobileFieldFull, '_') + 1)
            : $mobileFieldFull;

        // Frontend: acf/format_value uses the full field name (as passed to get_field)
        add_filter('acf/format_value/name=' . $emailFieldFull, [$this, 'obscureAcfPersonalEmail'], 20, 3);
        add_filter('acf/format_value/name=' . $mobileFieldFull, [$this, 'obscureAcfMobileNumber'], 20, 3);

        // Admin edit forms: acf/prepare_field matches against _name (the sub-field
        // part only, without the group prefix). format_value does NOT fire in admin.
        add_filter('acf/prepare_field/name=' . $emailFieldShort, [$this, 'prepareAcfPersonalEmail']);
        add_filter('acf/prepare_field/name=' . $mobileFieldShort, [$this, 'prepareAcfMobileNumber']);

        // Also register with the full name in case _name includes the group prefix
        if ($emailFieldShort !== $emailFieldFull) {
            add_filter('acf/prepare_field/name=' . $emailFieldFull, [$this, 'prepareAcfPersonalEmail']);
            add_filter('acf/prepare_field/name=' . $mobileFieldFull, [$this, 'prepareAcfMobileNumber']);
        }

        // Prevent empty submissions from wiping obscured field data.
        // When the field is shown with a placeholder (value cleared), saving
        // without typing anything would set the field to empty. These filters
        // detect that case and preserve the original value.
        //
        // For sub-fields inside ACF groups, name-based update_value filters
        // are unreliable — ACF may resolve the name differently during the
        // group save, causing double-firing or missed matches that blank
        // fields. Key-based filters (acf/update_value/key=) are guaranteed
        // to fire exactly once per field with the correct value.
        $emailFieldKey = $this->member_config['KEY_PERSONAL_EMAIL'] ?? '';
        $mobileFieldKey = $this->member_config['KEY_MOBILE_NUMBER'] ?? '';

        if ($emailFieldKey !== '') {
            add_filter('acf/update_value/key=' . $emailFieldKey, [$this, 'preservePersonalEmail'], 10, 3);
        }
        if ($mobileFieldKey !== '') {
            add_filter('acf/update_value/key=' . $mobileFieldKey, [$this, 'preserveMobileNumber'], 10, 3);
        }
    }

    /**
     * ACF filter: obscure the personal email field value (frontend via format_value)
     *
     * @param mixed $value The field value
     * @param mixed $postId The post ID
     * @param array $field The ACF field array
     * @return mixed The potentially obscured value
     */
    public function obscureAcfPersonalEmail(mixed $value, mixed $postId, array $field): mixed
    {
        if (!is_string($value) || $value === '') {
            return $value;
        }

        if ($this->policy->currentUserCanView()) {
            return $value;
        }

        return $this->policy->obscureEmail($value);
    }

    /**
     * ACF filter: obscure the mobile number field value (frontend via format_value)
     *
     * @param mixed $value The field value
     * @param mixed $postId The post ID
     * @param array $field The ACF field array
     * @return mixed The potentially obscured value
     */
    public function obscureAcfMobileNumber(mixed $value, mixed $postId, array $field): mixed
    {
        if (!is_string($value) || $value === '') {
            return $value;
        }

        if ($this->policy->currentUserCanView()) {
            return $value;
        }

        return $this->policy->obscurePhone($value);
    }

    /**
     * ACF prepare_field: obscure personal email in admin edit forms
     *
     * Shows the obscured value as a placeholder so the user can see that
     * data exists without revealing it. The input is left editable so the
     * user can type a replacement value. An acf/update_value filter
     * ensures that submitting an empty field preserves the original.
     *
     * @param array|false $field The ACF field array, or false if already hidden
     * @return array|false The modified field array
     */
    public function prepareAcfPersonalEmail(array|false $field): array|false
    {
        if ($field === false) {
            return $field;
        }

        $value = $field['value'] ?? '';

        if (!is_string($value) || $value === '') {
            return $field;
        }

        if ($this->policy->currentUserCanView()) {
            // User can see the real value — disable the input if they cannot edit
            if (!$this->policy->currentUserCanEdit()) {
                $field['disabled'] = 1;
            }
            return $field;
        }

        $field['placeholder'] = $this->policy->obscureEmail($value);
        $field['value'] = '';

        return $field;
    }

    /**
     * ACF prepare_field: obscure mobile number in admin edit forms
     *
     * @param array|false $field The ACF field array, or false if already hidden
     * @return array|false The modified field array
     */
    public function prepareAcfMobileNumber(array|false $field): array|false
    {
        if ($field === false) {
            return $field;
        }

        $value = $field['value'] ?? '';

        if (!is_string($value) || $value === '') {
            return $field;
        }

        if ($this->policy->currentUserCanView()) {
            // User can see the real value — disable the input if they cannot edit
            if (!$this->policy->currentUserCanEdit()) {
                $field['disabled'] = 1;
            }
            return $field;
        }

        $field['placeholder'] = $this->policy->obscurePhone($value);
        $field['value'] = '';

        return $field;
    }

    /**
     * ACF update_value: guard personal email updates behind the edit capability.
     *
     * Users with the edit capability may update the value freely.
     * Users who can view but not edit will have their change rejected and
     * the existing stored value preserved. Users who cannot view personal
     * data see an obscured placeholder and submit an empty string — the
     * existing value is likewise preserved.
     *
     * @param mixed $value The new value being saved
     * @param mixed $postId The post ID
     * @param array $field The ACF field array
     * @return mixed The value to save
     */
    public function preservePersonalEmail(mixed $value, mixed $postId, array $field): mixed
    {
        return $this->preserveAcfValue(
            $value,
            $postId,
            $this->member_config['FIELD_PERSONAL_EMAIL'] ?? ''
        );
    }

    /**
     * ACF update_value: guard mobile number updates behind the edit capability.
     *
     * @param mixed $value The new value being saved
     * @param mixed $postId The post ID
     * @param array $field The ACF field array
     * @return mixed The value to save
     */
    public function preserveMobileNumber(mixed $value, mixed $postId, array $field): mixed
    {
        return $this->preserveAcfValue(
            $value,
            $postId,
            $this->member_config['FIELD_MOBILE_NUMBER'] ?? ''
        );
    }

    /**
     * Shared implementation for the email and mobile preserve filters.
     */
    private function preserveAcfValue(mixed $value, mixed $postId, string $fieldName): mixed
    {
        $numericPostId = is_numeric($postId) ? (int) $postId : 0;

        // The Clear button submits a sentinel value rather than an empty
        // string so the server can tell an intentional clear apart from
        // an untouched field. Convert the sentinel to empty before saving.
        if ($value === PersonalDataPolicy::CLEAR_SENTINEL) {
            return '';
        }

        if ($this->policy->currentUserCanEdit()) {
            // Users who cannot view personal data see an empty input with
            // an obscured placeholder. If they don't type anything the form
            // submits an empty string — preserve the existing value since
            // the user simply didn't touch the field.
            if (!$this->policy->currentUserCanView() && ($value === '' || $value === null)) {
                $existing = $fieldName !== '' ? get_field($fieldName, $numericPostId, false) : null;
                if (is_string($existing) && $existing !== '') {
                    return $existing;
                }
            }
            return $value;
        }

        // User cannot edit — always preserve the existing value.
        $existing = $fieldName !== '' ? get_field($fieldName, $numericPostId, false) : null;

        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        // No existing value stored — allow the initial value through
        return $value;
    }
}
