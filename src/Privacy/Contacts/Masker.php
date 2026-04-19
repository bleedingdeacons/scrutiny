<?php

declare(strict_types=1);

namespace Scrutiny\Privacy\Contacts;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Scrutiny\Privacy\PersonalDataObscurer;

/**
 * Renders a fixed-width placeholder for each protected TSML contact
 * field when the viewer has no clearance.
 *
 * Uses the same placeholder as {@see PersonalDataObscurer::FIXED_PLACEHOLDER}
 * so masked values have a consistent visual across the admin UI.
 *
 * Empty values stay empty so non-privileged users can still tell
 * which contact slots are in use without learning anything about
 * the actual values.
 */
final class Masker
{
    public function mask(string $value): string
    {
        return $value !== '' ? PersonalDataObscurer::FIXED_PLACEHOLDER : '';
    }
}
