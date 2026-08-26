<?php

declare(strict_types=1);

namespace Scrutiny\Privacy;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use function apply_filters;

/**
 * Personal Data Fields
 *
 * Defines which member fields contain personal data subject to
 * GDPR audit logging and UI obscuring.
 */
final class PersonalDataFields
{
    /**
     * Default set of the nine named-contact meta keys on TSML meeting
     * and group posts (contact_1_name … contact_3_phone).
     *
     * Exposed via {@see self::protectedContactFields()} which applies
     * the filters that let site owners adjust the set without editing
     * plugin files.
     *
     * @var array<string>
     */
    private const DEFAULT_PROTECTED_CONTACT_FIELDS = [
        'contact_1_email', 'contact_1_phone',
        'contact_2_email', 'contact_2_phone',
        'contact_3_email', 'contact_3_phone',
    ];

    /**
     * Logical field name: member's personal email address
     */
    public const PERSONAL_EMAIL = 'personal-email';

    /**
     * Logical field name: member's mobile phone number
     */
    public const MOBILE_NUMBER = 'mobile-number';

    /**
     * Logical field name: telephone-responder certification stage
     *
     * Deliberately not personal data — it is a service status, not something
     * that identifies the member. It is therefore absent from
     * {@see self::ALL_FIELDS}, {@see self::CONFIG_KEY_MAP} and
     * {@see self::CONFIG_ACF_KEY_MAP}, so it is never obscured and never
     * generates view entries. It appears here only so the audit log can
     * label its change entries, which — unlike the personal-data fields —
     * record the new value outright.
     */
    public const RESPONDER_CERTIFICATION = 'responder-certification';

    /**
     * Logical field name: the member's home group
     *
     * Deliberately not personal data, on the same reasoning as
     * {@see self::RESPONDER_CERTIFICATION}: a group is a public entity, and
     * which one a member calls home is service information rather than
     * something that identifies them. It is therefore absent from
     * {@see self::ALL_FIELDS}, {@see self::CONFIG_KEY_MAP} and
     * {@see self::CONFIG_ACF_KEY_MAP}, so it is never obscured and never
     * generates view entries. Its audit entries name the group outright — an
     * entry saying only that a member "was assigned to a group" would answer
     * nothing an auditor asked.
     */
    public const HOME_GROUP = 'home-group';

    /**
     * Logical field name: whether the member is their home group's GSR
     *
     * Not personal data, on the same reasoning as {@see self::HOME_GROUP} —
     * being a General Service Representative is a service role, and the role
     * belongs to a public group. Its entries name that group, since "GSR" on
     * its own does not say what the member is GSR for.
     */
    public const GSR = 'gsr';

    /**
     * Logical field name: the member's intergroup service position
     *
     * Not personal data, for the same reason as {@see self::HOME_GROUP}: an
     * intergroup position is a public service role. Its entries likewise name
     * the position outright, so the log answers who took a position, who
     * vacated it, and when.
     */
    public const INTERGROUP_POSITION = 'intergroup-position';

    /**
     * Logical field name: date the member's intergroup position rotates
     *
     * Service information about a service post, so the entry records the
     * date outright.
     */
    public const POSITION_ROTATION = 'position-rotation';

    /**
     * Logical field name: whether the member takes 12th-step calls
     *
     * Availability for service, not an attribute of the person, so the entry
     * says which way it went.
     */
    public const TWELFTH_STEPPER = 'twelfth-stepper';

    /**
     * Logical field name: whether the member takes helpline calls
     *
     * The companion flag to {@see self::RESPONDER_CERTIFICATION}, and
     * recorded on the same footing.
     */
    public const TELEPHONE_RESPONDER = 'telephone-responder';

    /**
     * Logical field name: whether the member's name is shown publicly
     *
     * A privacy setting whose value is a yes or a no, revealing nothing about
     * the member by being written down — and the single most useful thing to
     * know about a privacy setting is which way it was moved, and by whom.
     */
    public const SHOW_ANONYMOUS_NAME = 'show-anonymous-name';

    /**
     * Logical field name: whether the member's profile is shown publicly
     *
     * As {@see self::SHOW_ANONYMOUS_NAME}.
     */
    public const SHOW_MEMBER_PROFILE = 'show-member-profile';

    /**
     * Logical field name: the geographic area the member covers
     *
     * Recorded as a change only, never as a value. It is coarse, but it is
     * still where a named individual is, and this log is not the place for
     * that.
     */
    public const AREA = 'area';

    /**
     * Logical field name: the forms of contact the member accepts
     *
     * Recorded as a change only. Reach reads this selection for gender
     * matching, so the values say more about the member than a service
     * preference should be allowed to leak into an audit trail.
     */
    public const ACCEPTS = 'accepts';

    /**
     * Logical field name: the member's free-text profile
     *
     * Recorded as a change only — it is prose the member wrote about
     * themselves and may contain anything at all.
     */
    public const ANONYMOUS_PROFILE = 'anonymous-profile';

    /**
     * Logical field name: the member's meeting PO reference
     *
     * Recorded as a change only: the field is typed `mixed` and is marked for
     * removal in TsmlMember, so there is no value shape worth rendering.
     */
    public const MEETING_PO = 'meeting-po';

    /**
     * Sentinel field name used when an audit entry refers to the entire
     * record rather than a single field — for example, the one-shot
     * "Member created" entry written when a new member is inserted.
     *
     * Stored in the `field_name` column so the admin filter and label
     * lookup keep working without special-casing empty strings.
     */
    public const ALL_FIELDS_SENTINEL = 'all-fields';

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
     * Logical field name: GDPR acceptance flag
     *
     * Records whether the member has accepted the privacy policy.
     */
    public const GDPR_ACCEPTED = 'gdpr-accepted';

    /**
     * Logical field name: timestamp at which GDPR acceptance was recorded
     */
    public const GDPR_ACCEPTED_AT = 'gdpr-accepted-at';

    /**
     * Logical field name: privacy policy version that was accepted
     */
    public const GDPR_ACCEPTANCE_VERSION = 'gdpr-acceptance-version';

    /**
     * Logical field name: how acceptance was captured (e.g. "web-form", "api", "import")
     */
    public const GDPR_ACCEPTANCE_METHOD = 'gdpr-acceptance-method';

    /**
     * Logical field name: the exact statement the member accepted
     */
    public const GDPR_ACCEPTANCE_STATEMENT = 'gdpr-acceptance-statement';

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
     * All GDPR compliance field names for members.
     *
     * Tracked alongside the personal-data fields so that audit consumers
     * can see when consent was recorded, revoked, or amended.
     *
     * @var array<string>
     */
    public const GDPR_FIELDS = [
        self::GDPR_ACCEPTED,
        self::GDPR_ACCEPTED_AT,
        self::GDPR_ACCEPTANCE_VERSION,
        self::GDPR_ACCEPTANCE_METHOD,
        self::GDPR_ACCEPTANCE_STATEMENT,
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
        'FIELD_PERSONAL_EMAIL'             => self::PERSONAL_EMAIL,
        'FIELD_MOBILE_NUMBER'              => self::MOBILE_NUMBER,
        'FIELD_GDPR_ACCEPTED'              => self::GDPR_ACCEPTED,
        'FIELD_GDPR_ACCEPTED_AT'           => self::GDPR_ACCEPTED_AT,
        'FIELD_GDPR_ACCEPTANCE_VERSION'    => self::GDPR_ACCEPTANCE_VERSION,
        'FIELD_GDPR_ACCEPTANCE_METHOD'     => self::GDPR_ACCEPTANCE_METHOD,
        'FIELD_GDPR_ACCEPTANCE_STATEMENT'  => self::GDPR_ACCEPTANCE_STATEMENT,
    ];

    /**
     * Configuration keys that hold the ACF field keys for personal data fields.
     *
     * Used by MemberFieldsObscurer to register acf/update_value/key= filters which
     * are more reliable than name-based filters for group sub-fields.
     *
     * @var array<string, string>
     */
    public const CONFIG_ACF_KEY_MAP = [
        'KEY_PERSONAL_EMAIL'             => self::PERSONAL_EMAIL,
        'KEY_MOBILE_NUMBER'              => self::MOBILE_NUMBER,
        'KEY_GDPR_ACCEPTED'              => self::GDPR_ACCEPTED,
        'KEY_GDPR_ACCEPTED_AT'           => self::GDPR_ACCEPTED_AT,
        'KEY_GDPR_ACCEPTANCE_VERSION'    => self::GDPR_ACCEPTANCE_VERSION,
        'KEY_GDPR_ACCEPTANCE_METHOD'     => self::GDPR_ACCEPTANCE_METHOD,
        'KEY_GDPR_ACCEPTANCE_STATEMENT'  => self::GDPR_ACCEPTANCE_STATEMENT,
    ];

    /**
     * Human-readable labels for each personal data field
     *
     * @var array<string, string>
     */
    public const LABELS = [
        self::PERSONAL_EMAIL => 'Personal Email',
        self::MOBILE_NUMBER  => 'Mobile Number',
        self::RESPONDER_CERTIFICATION => 'Responder Certification',
        self::HOME_GROUP => 'Home Group',
        self::INTERGROUP_POSITION => 'Intergroup Position',
        self::GSR => 'GSR',
        self::POSITION_ROTATION => 'Position Rotation',
        self::TWELFTH_STEPPER => '12th Stepper',
        self::TELEPHONE_RESPONDER => 'Telephone Responder',
        self::SHOW_ANONYMOUS_NAME => 'Show Anonymous Name',
        self::SHOW_MEMBER_PROFILE => 'Show Member Profile',
        self::AREA => 'Area',
        self::ACCEPTS => 'Accepts',
        self::ANONYMOUS_PROFILE => 'Anonymous Profile',
        self::MEETING_PO => 'Meeting PO',
        self::ALL_FIELDS_SENTINEL => 'All fields',
        self::GROUP_CONTACT_NAME   => 'Group Contact Name',
        self::GROUP_CONTACT_EMAIL  => 'Group Contact Email',
        self::GROUP_CONTACT_PHONE  => 'Group Contact Phone',
        self::MEETING_CONTACT_NAME  => 'Meeting Contact Name',
        self::MEETING_CONTACT_EMAIL => 'Meeting Contact Email',
        self::MEETING_CONTACT_PHONE => 'Meeting Contact Phone',
        self::GDPR_ACCEPTED              => 'GDPR Accepted',
        self::GDPR_ACCEPTED_AT           => 'GDPR Accepted At',
        self::GDPR_ACCEPTANCE_VERSION    => 'GDPR Policy Version',
        self::GDPR_ACCEPTANCE_METHOD     => 'GDPR Acceptance Method',
        self::GDPR_ACCEPTANCE_STATEMENT  => 'GDPR Acceptance Statement',
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
            // Every LEGACY_FIELD_MAP value is a LABELS key, so this always hits.
            return self::LABELS[$canonical];
        }

        return $fieldName;
    }

    /**
     * The nine named-contact meta keys on TSML meeting and group posts.
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
     *
     * @return string[]
     */
    public static function protectedContactFields(): array
    {
        /** @var string[] $fields */
        $fields = apply_filters('scrutiny_tsml_protected_fields', self::DEFAULT_PROTECTED_CONTACT_FIELDS);
        /** @var string[] $fields */
        $fields = apply_filters('tsml_cac_protected_fields', $fields);

        return array_values(array_filter(array_map('strval', $fields)));
    }

    private function __construct()
    {
    }
}
