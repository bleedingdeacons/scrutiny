<?php

declare(strict_types=1);

namespace Scrutiny\Privacy;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Scrutiny\Audit\Interfaces\AuditLogger;
use Scrutiny\Privacy\Interfaces\DataObscurer;
use Unity\Core\Interfaces\Configuration;
use Unity\Members\Interfaces\Member;
use function add_filter;
use function current_user_can;
use function get_field;

/**
 * Data Obscurer
 *
 * Obscures personal data fields in the WordPress admin UI and logs
 * each access event to the GDPR audit trail.
 *
 * Hooks into ACF field rendering to mask personal email and mobile number
 * values on the member edit screen.
 *
 * Users with the `scrutiny_view_personal_data` capability see unobscured
 * values; all other users see masked placeholders.
 *
 * Users with the `scrutiny_edit_personal_data` capability may update
 * personal data fields; all other users have their changes silently
 * rejected and the existing stored value preserved.
 *
 * ## Obscuring policy
 *
 * The obscurer emits a fixed-width placeholder for any non-empty value.
 * It deliberately does not preserve any portion of the original (no first
 * letter, no length, no TLD, no last-N digits) because the member data
 * surfaces on screens that already expose the member's group, role, and
 * rotation — for small intergroups in the dozens-to-hundreds range, even
 * a few leaked characters are usually enough to re-identify an individual.
 *
 * This differs from the REST API obscurer in {@see \Integrity\Utils\Mask},
 * which retains first characters and TLDs so that update requests can
 * round-trip the masked value and the server can detect it as such.
 * Scrutiny's obscured values never round-trip through an editable pathway
 * — the edit form submits an empty string or a real replacement, and
 * {@see self::preservePersonalEmail} handles the empty-submission case.
 */
class PersonalDataObscurer implements DataObscurer
{
    public const VIEW_CAPABILITY = 'scrutiny_view_personal_data';
    public const EDIT_CAPABILITY = 'scrutiny_edit_personal_data';

    /**
     * Sentinel value submitted by the Clear button to signal an
     * intentional clear. Converted to an empty string before saving.
     */
    public const CLEAR_SENTINEL = '__CLEAR__';

    /**
     * Fixed-width placeholder returned for any non-empty obscured value.
     * Width is intentionally uniform so that it leaks no information about
     * the original. See class docblock for the re-identification rationale.
     */
    public const FIXED_PLACEHOLDER = '•••••';

    private AuditLogger $logger;
    private readonly array $member_config;

    public function __construct(Configuration $configuration, AuditLogger $logger)
    {
        $this->logger = $logger;
        $this->member_config = $configuration->getConfig(Member::class);

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
     * @inheritDoc
     *
     * Returns a fixed-width bullet string for any non-empty email. The output
     * deliberately reveals nothing about the input — no length, no first
     * character of the local part or domain, no TLD. This prevents
     * re-identification within small member sets (e.g. an AA intergroup of a
     * few hundred people) where knowledge of local-part length, domain length
     * and TLD is often enough to narrow down to a single individual when
     * combined with other context visible on the same screen.
     *
     * A non-empty placeholder is retained (rather than returning the empty
     * string) so that {@see self::prepareAcfPersonalEmail} can use the output
     * as an ACF field placeholder and still signal to the viewer that a
     * value is stored.
     */
    public function obscureEmail(string $email): string
    {
        return $email === '' ? '' : self::FIXED_PLACEHOLDER;
    }

    /**
     * @inheritDoc
     *
     * Returns a fixed-width bullet string for any non-empty phone number.
     * The output deliberately reveals nothing about the input — no digit
     * count, no last-N digits. See {@see self::obscureEmail} for the
     * re-identification rationale.
     */
    public function obscurePhone(string $number): string
    {
        return $number === '' ? '' : self::FIXED_PLACEHOLDER;
    }

    /**
     * @inheritDoc
     */
    public function currentUserCanViewPersonalData(): bool
    {
        return current_user_can(self::VIEW_CAPABILITY);
    }

    /**
     * @inheritDoc
     */
    public function currentUserCanEditPersonalData(): bool
    {
        return current_user_can(self::EDIT_CAPABILITY);
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

        if ($this->currentUserCanViewPersonalData()) {
            return $value;
        }

        return $this->obscureEmail($value);
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

        if ($this->currentUserCanViewPersonalData()) {
            return $value;
        }

        return $this->obscurePhone($value);
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

        if ($this->currentUserCanViewPersonalData()) {
            // User can see the real value — disable the input if they cannot edit
            if (!$this->currentUserCanEditPersonalData()) {
                $field['disabled'] = 1;
            }
            return $field;
        }

        $field['placeholder'] = $this->obscureEmail($value);
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

        if ($this->currentUserCanViewPersonalData()) {
            // User can see the real value — disable the input if they cannot edit
            if (!$this->currentUserCanEditPersonalData()) {
                $field['disabled'] = 1;
            }
            return $field;
        }

        $field['placeholder'] = $this->obscurePhone($value);
        $field['value'] = '';

        return $field;
    }

    /**
     * ACF update_value: guard personal email updates behind the edit capability.
     *
     * Users with `scrutiny_edit_personal_data` may update the value freely.
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
        $numericPostId = is_numeric($postId) ? (int) $postId : 0;
        $fieldName = $this->member_config['FIELD_PERSONAL_EMAIL'] ?? '';

        // The Clear button submits a sentinel value rather than an empty
        // string so the server can tell an intentional clear apart from
        // an untouched field. Convert the sentinel to empty before saving.
        if ($value === self::CLEAR_SENTINEL) {
            return '';
        }

        if ($this->currentUserCanEditPersonalData()) {
            // Users who cannot view personal data see an empty input with
            // an obscured placeholder. If they don't type anything the form
            // submits an empty string — preserve the existing value since
            // the user simply didn't touch the field.
            if (!$this->currentUserCanViewPersonalData() && ($value === '' || $value === null)) {
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
        $numericPostId = is_numeric($postId) ? (int) $postId : 0;
        $fieldName = $this->member_config['FIELD_MOBILE_NUMBER'] ?? '';

        // The Clear button submits a sentinel value rather than an empty
        // string so the server can tell an intentional clear apart from
        // an untouched field. Convert the sentinel to empty before saving.
        if ($value === self::CLEAR_SENTINEL) {
            return '';
        }

        if ($this->currentUserCanEditPersonalData()) {
            // Users who cannot view personal data see an empty input with
            // an obscured placeholder. If they don't type anything the form
            // submits an empty string — preserve the existing value since
            // the user simply didn't touch the field.
            if (!$this->currentUserCanViewPersonalData() && ($value === '' || $value === null)) {
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