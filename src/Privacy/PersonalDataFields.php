<?php

declare(strict_types=1);

namespace Scrutiny\Privacy;

/**
 * Personal Data Fields
 *
 * Defines which member fields contain personal data subject to
 * GDPR audit logging and UI obscuring.
 */
final class PersonalDataFields
{
    /**
     * Logical field name: member's private name (first name and initial)
     */
//    public const PRIVATE_NAME = 'private_name';

    /**
     * Logical field name: member's personal email address
     */
    public const PERSONAL_EMAIL = 'personal_email';

    /**
     * Logical field name: member's mobile phone number
     */
    public const MOBILE_NUMBER = 'mobile_number';

    /**
     * All personal data field names
     *
     * @var array<string>
     */
    public const ALL_FIELDS = [
        self::PERSONAL_EMAIL,
        self::MOBILE_NUMBER,
    ];

    /**
     * Configuration keys that map to personal data logical field names.
     *
     * Each key corresponds to a constant name in the data provider's Fields class
     * which is registered via Configuration::setConfig(Member::class, ...).
     *
     * @var array<string, string>
     */
    public const CONFIG_KEY_MAP = [
        'FIELD_PERSONAL_EMAIL' => self::PERSONAL_EMAIL,
        'FIELD_MOBILE_NUMBER'  => self::MOBILE_NUMBER,
    ];

    /**
     * Human-readable labels for each personal data field
     *
     * @var array<string, string>
     */
    public const LABELS = [
        self::PERSONAL_EMAIL => 'Personal Email',
        self::MOBILE_NUMBER  => 'Mobile Number',
    ];

    private function __construct()
    {
    }
}
