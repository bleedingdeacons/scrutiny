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
     * Logical field name: member's personal email address
     */
    public const PERSONAL_EMAIL = 'personal-email';

    /**
     * Logical field name: member's mobile phone number
     */
    public const MOBILE_NUMBER = 'mobile-number';

    /**
     * Logical field name: group contact name
     */
    public const GROUP_CONTACT_NAME = 'group-contact-name';

    /**
     * Logical field name: group contact email
     */
    public const GROUP_CONTACT_EMAIL = 'group-contact-email';

    /**
     * Logical field name: group contact phone
     */
    public const GROUP_CONTACT_PHONE = 'group-contact-phone';

    /**
     * Logical field name: meeting contact name
     */
    public const MEETING_CONTACT_NAME = 'meeting-contact-name';

    /**
     * Logical field name: meeting contact email
     */
    public const MEETING_CONTACT_EMAIL = 'meeting-contact-email';

    /**
     * Logical field name: meeting contact phone
     */
    public const MEETING_CONTACT_PHONE = 'meeting-contact-phone';

    /**
     * All personal data field names for members
     *
     * @var array<string>
     */
    public const ALL_FIELDS = [
        self::PERSONAL_EMAIL,
        self::MOBILE_NUMBER,
    ];

    /**
     * All personal data field names for group contacts
     *
     * @var array<string>
     */
    public const GROUP_CONTACT_FIELDS = [
        self::GROUP_CONTACT_NAME,
        self::GROUP_CONTACT_EMAIL,
        self::GROUP_CONTACT_PHONE,
    ];

    /**
     * All personal data field names for meeting contacts
     *
     * @var array<string>
     */
    public const MEETING_CONTACT_FIELDS = [
        self::MEETING_CONTACT_NAME,
        self::MEETING_CONTACT_EMAIL,
        self::MEETING_CONTACT_PHONE,
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
        self::GROUP_CONTACT_NAME   => 'Group Contact Name',
        self::GROUP_CONTACT_EMAIL  => 'Group Contact Email',
        self::GROUP_CONTACT_PHONE  => 'Group Contact Phone',
        self::MEETING_CONTACT_NAME  => 'Meeting Contact Name',
        self::MEETING_CONTACT_EMAIL => 'Meeting Contact Email',
        self::MEETING_CONTACT_PHONE => 'Meeting Contact Phone',
    ];

    /**
     * Legacy field name mappings for backward compatibility.
     *
     * Earlier versions stored field names with underscores in the audit log.
     *
     * @var array<string, string>
     */
    private const LEGACY_FIELD_MAP = [
        'personal_email' => self::PERSONAL_EMAIL,
        'mobile_number'  => self::MOBILE_NUMBER,
    ];

    /**
     * Get the human-readable label for a field name, with backward compatibility
     * for legacy underscore-style names in existing audit log entries.
     *
     * @param string $fieldName The field name to look up
     * @return string The label, or the original field name if not found
     */
    public static function getLabel(string $fieldName): string
    {
        if (isset(self::LABELS[$fieldName])) {
            return self::LABELS[$fieldName];
        }

        if (isset(self::LEGACY_FIELD_MAP[$fieldName])) {
            $canonical = self::LEGACY_FIELD_MAP[$fieldName];
            return self::LABELS[$canonical] ?? $fieldName;
        }

        return $fieldName;
    }

    private function __construct()
    {
    }
}