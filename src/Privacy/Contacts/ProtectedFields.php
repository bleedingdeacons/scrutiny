<?php

declare(strict_types=1);

namespace Scrutiny\Privacy\Contacts;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use function apply_filters;

/**
 * The nine named-contact meta keys on TSML meeting and group posts
 * (contact_1_name … contact_3_phone).
 *
 * The list is filterable so site owners can adjust the set without
 * editing plugin files. Two filters are supported for back-compat:
 *
 *   scrutiny_tsml_protected_fields — canonical Scrutiny name
 *   tsml_cac_protected_fields      — legacy name from the standalone
 *                                    TSML Contact Access Control plugin
 *
 * Example:
 *
 *     add_filter('scrutiny_tsml_protected_fields', fn() => ['contact_1_email']);
 */
final class ProtectedFields
{
    private const DEFAULT_FIELDS = [
        'contact_1_email', 'contact_1_phone',
        'contact_2_email', 'contact_2_phone',
        'contact_3_email', 'contact_3_phone',
    ];

    /** @return string[] */
    public function all(): array
    {
        /** @var string[] $fields */
        $fields = apply_filters('scrutiny_tsml_protected_fields', self::DEFAULT_FIELDS);
        /** @var string[] $fields */
        $fields = apply_filters('tsml_cac_protected_fields', $fields);

        return array_values(array_filter(array_map('strval', $fields)));
    }
}
