<?php

declare(strict_types=1);

namespace Scrutiny\Privacy;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use function current_user_can;

/**
 * Personal Data Policy
 *
 * Pure stateless helpers that define the Scrutiny privacy policy:
 *
 *   - the two capability names that gate viewing and editing personal data
 *   - the fixed-width placeholder used anywhere a value is masked
 *   - the clear-button sentinel that distinguishes an intentional clear
 *     from an untouched field
 *   - capability checks for the current user
 *   - obscuring helpers for email, phone and generic contact-field values
 *
 * This class has no WordPress side effects. It can be instantiated in a
 * unit test with `new PersonalDataPolicy()` and exercised directly.
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
 * Scrutiny's obscured values never round-trip through an editable pathway.
 */
final class PersonalDataPolicy
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

    /**
     * Whether the current user may see unobscured personal data.
     */
    public function currentUserCanView(): bool
    {
        return current_user_can(self::VIEW_CAPABILITY);
    }

    /**
     * Whether the current user may update personal data fields.
     */
    public function currentUserCanEdit(): bool
    {
        return current_user_can(self::EDIT_CAPABILITY);
    }

    /**
     * Obscure an email address.
     *
     * Returns a fixed-width bullet string for any non-empty input. The
     * output reveals nothing about the original — no length, no first
     * character of the local part or domain, no TLD. A non-empty
     * placeholder is retained (rather than an empty string) so admin
     * UIs can use it as a field placeholder and still signal that a
     * value is stored.
     */
    public function obscureEmail(string $email): string
    {
        return $email === '' ? '' : self::FIXED_PLACEHOLDER;
    }

    /**
     * Obscure a phone number.
     *
     * Returns a fixed-width bullet string for any non-empty input.
     * The output reveals nothing about the original — no digit count,
     * no last-N digits. See {@see self::obscureEmail} for the
     * re-identification rationale.
     */
    public function obscurePhone(string $number): string
    {
        return $number === '' ? '' : self::FIXED_PLACEHOLDER;
    }

    /**
     * Render a fixed-width placeholder for a stored contact-field value.
     *
     * Used by the TSML contact-field renderer to produce masked previews
     * for users in the NONE tier. Empty values stay empty so viewers can
     * still tell which contact slots are in use without learning anything
     * about the actual values.
     */
    public function maskContactField(string $value): string
    {
        return $value !== '' ? self::FIXED_PLACEHOLDER : '';
    }
}
