<?php

declare(strict_types=1);

namespace Scrutiny\Privacy;

use Unity\Members\MemberConstants;

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
//        self::PRIVATE_NAME,
        self::PERSONAL_EMAIL,
        self::MOBILE_NUMBER,
    ];

    /**
     * Map from ACF field keys to logical personal data field names
     *
     * @var array<string, string>
     */
    public const ACF_FIELD_MAP = [
        MemberConstants::FIELD_PERSONAL_EMAIL => self::PERSONAL_EMAIL,
        MemberConstants::FIELD_MOBILE_NUMBER  => self::MOBILE_NUMBER,
    ];

    /**
     * Human-readable labels for each personal data field
     *
     * @var array<string, string>
     */
    public const LABELS = [
//        self::PRIVATE_NAME   => 'Private Name',
        self::PERSONAL_EMAIL => 'Personal Email',
        self::MOBILE_NUMBER  => 'Mobile Number',
    ];

    private function __construct()
    {
    }
}
