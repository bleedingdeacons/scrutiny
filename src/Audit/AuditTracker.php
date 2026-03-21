<?php

declare(strict_types=1);

namespace Scrutiny\Audit;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Scrutiny\Audit\Interfaces\AuditLoggerInterface;
use Scrutiny\Privacy\PersonalDataFields;

use Unity\Core\Interfaces\Configuration;
use Unity\Groups\Interfaces\Group;
use Unity\Members\Interfaces\Member;
use function add_action;
use function add_filter;
use function get_post_type;
use function is_admin;

/**
 * Audit Tracker
 *
 * Hooks into Unity's member and group lifecycle events and ACF field loading to automatically log
 * creation, updates, viewing, and deletion of personal data fields.
 *
 * Listens to:
 *   - current_screen             (fired when admin screen loads - used for admin form view tracking)
 *   - acf/load_value             (fired when ACF loads a field value - used for frontend view tracking)
 *   - unity/member_changing      (fired by MemberChangeTracker when member fields change)
 *   - unity/member_deleted       (fired by MemberChangeTracker when a member is trashed or deleted)
 *   - unity/group_changing       (fired by GroupChangeTracker when group fields change)
 *   - unity/group_deleted        (fired by GroupChangeTracker when a group is trashed or deleted)
 *   - unity/group_hidden         (fired by GroupChangeTracker when a group is set to private)
 */
class AuditTracker
{
    private AuditLoggerInterface $logger;

    /**
     * Track which member fields have been logged in this request to prevent duplicates
     * Key format: "{post_id}_{field_name}"
     * @var array<string, bool>
     */
    private array $loggedMemberViews = [];

    private readonly array $member_config;

    /**
     * Map from ACF field keys to logical personal data field names,
     * built at runtime from configuration.
     *
     * @var array<string, string>
     */
    private readonly array $acfFieldMap;

    public function __construct(Configuration $configuration, AuditLoggerInterface $logger)
    {
        $this->logger = $logger;

        $this->member_config = $configuration->getConfig(Member::class);

        // Build the ACF field map from configuration and PersonalDataFields::CONFIG_KEY_MAP
        $map = [];
        foreach (PersonalDataFields::CONFIG_KEY_MAP as $configKey => $logicalName) {
            if (isset($this->member_config[$configKey])) {
                $map[$this->member_config[$configKey]] = $logicalName;
            }
        }
        $this->acfFieldMap = $map;

        // Log when a member edit form is displayed in admin
        add_action('current_screen', [$this, 'onMemberAdminFormDisplayed'], 10, 1);

        // Log when personal data fields are accessed via ACF on frontend
        add_filter('acf/load_value', [$this, 'onPersonalDataFieldLoaded'], 10, 3);

        // Log personal data changes when a member is updated
        add_action('unity/member_changing', [$this, 'onMemberChanged'], 10, 2);

        // Log contact data changes when a group is updated
        add_action('unity/group_changing', [$this, 'onGroupChanged'], 10, 2);

        // Log personal data deletion when a member is deleted or trashed
        add_action('unity/member_deleted', [$this, 'onMemberDeleted'], 10, 2);

        // Log contact data deletion when a group is deleted or trashed
        add_action('unity/group_deleted', [$this, 'onGroupDeleted'], 10, 2);

        // Log when a group is hidden (set to private)
        add_action('unity/group_hidden', [$this, 'onGroupHidden'], 10, 2);
    }

    /**
     * Log when a member edit form is displayed in the WordPress admin
     *
     * Triggered on 'current_screen' when the admin screen is set up.
     * Logs when the edit screen for a member post is accessed.
     *
     * @param \WP_Screen $screen The current screen object
     * @return void
     */
    public function onMemberAdminFormDisplayed($screen): void
    {
        // Check if we're on a post edit screen
        if (!$screen || $screen->base !== 'post') {
            return;
        }

        // Verify it's a member post type
        if ($screen->post_type !== $this->member_config['POST_TYPE']) {
            return;
        }

        // Get the post ID if we're editing an existing post
        $postId = isset($_GET['post']) ? (int) $_GET['post'] : 0;

        // Only log for existing posts (not new post screen)
        if ($postId <= 0) {
            return;
        }

        // Create unique key for this post
        $logKey = 'admin_' . $postId;

        // Prevent duplicate logging in the same request
        if (isset($this->loggedMemberViews[$logKey])) {
            return;
        }

        // Mark this member as logged for this request
        $this->loggedMemberViews[$logKey] = true;

        // Log viewing of all personal data fields
        $this->logger->logBatch(
            AuditLoggerInterface::ACTION_VIEW,
            AuditLoggerInterface::ENTITY_MEMBER,
            $postId,
            PersonalDataFields::ALL_FIELDS,
            'Member edit form displayed in admin'
        );
    }

    /**
     * Log when a personal data field is loaded via ACF
     *
     * Triggered on 'acf/load_value' when ACF loads a field value.
     * Only logs personal data fields on member posts when viewed on the frontend.
     * Prevents duplicate logging per field per post per request.
     *
     * @param mixed $value The field value
     * @param int|string $postId The post ID
     * @param array $field The field array
     * @return mixed The unchanged field value
     */
    public function onPersonalDataFieldLoaded($value, $postId, array $field)
    {
        // Only track integer post IDs (not user_123, term_456, etc.)
        if (!is_int($postId) || $postId <= 0) {
            return $value;
        }

        // Verify it's a member post type
        if (get_post_type($postId) !== $this->member_config['POST_TYPE']) {
            return $value;
        }

        // Only log on frontend, not in admin
        if (is_admin()) {
            return $value;
        }

        // Check if this is a personal data field
        $fieldKey = $field['key'] ?? '';
        if (!isset($this->acfFieldMap[$fieldKey])) {
            return $value;
        }

        // Get the logical field name
        $fieldName = $this->acfFieldMap[$fieldKey];

        // Create unique key for this field + post combination
        $logKey = $postId . '_' . $fieldName;

        // Prevent duplicate logging in the same request
        if (isset($this->loggedMemberViews[$logKey])) {
            return $value;
        }

        // Mark this field as logged for this request
        $this->loggedMemberViews[$logKey] = true;

        // Log the field view
        $this->logger->log(
            AuditLoggerInterface::ACTION_VIEW,
            AuditLoggerInterface::ENTITY_MEMBER,
            $postId,
            $fieldName,
            'Personal data field accessed'
        );

        return $value;
    }

    /**
     * Log changes to personal data fields when a member is updated
     *
     * Compares original and updated member to detect which personal
     * data fields changed, and logs each change individually.
     *
     * @param Member $updatedMember The member after changes
     * @param Member $originalMember The member before changes
     * @return void
     */
    public function onMemberChanged(Member $updatedMember, Member $originalMember): void
    {
        $memberId = $updatedMember->getId();

        if ($originalMember->getPersonalEmail() !== $updatedMember->getPersonalEmail()) {
            $this->logger->log(
                AuditLoggerInterface::ACTION_UPDATE,
                AuditLoggerInterface::ENTITY_MEMBER,
                $memberId,
                PersonalDataFields::PERSONAL_EMAIL,
                'Value changed'
            );
        }

        if ($originalMember->getMobileNumber() !== $updatedMember->getMobileNumber()) {
            $this->logger->log(
                AuditLoggerInterface::ACTION_UPDATE,
                AuditLoggerInterface::ENTITY_MEMBER,
                $memberId,
                PersonalDataFields::MOBILE_NUMBER,
                'Value changed'
            );
        }
    }

    /**
     * Log changes to contact data when a group is updated
     *
     * Compares the original and updated group's contacts, and also compares
     * contacts on each meeting within the group. Logs each change individually.
     *
     * @param Group $updatedGroup The group after changes
     * @param Group $originalGroup The group before changes
     * @return void
     */
    public function onGroupChanged(Group $updatedGroup, Group $originalGroup): void
    {
        $this->logContactChanges(
            AuditLoggerInterface::ENTITY_GROUP,
            $updatedGroup->getId(),
            $originalGroup->getContacts(),
            $updatedGroup->getContacts(),
            PersonalDataFields::GROUP_CONTACT_NAME,
            PersonalDataFields::GROUP_CONTACT_EMAIL,
            PersonalDataFields::GROUP_CONTACT_PHONE
        );

        $this->logMeetingContactChanges($originalGroup, $updatedGroup);
    }

    /**
     * Compare contacts on meetings within the original and updated group,
     * logging changes for each meeting whose contacts differ.
     *
     * Meetings are matched by ID. Meetings that only appear in the updated group
     * are treated as newly added; meetings that only appear in the original are ignored
     * here (deletion is handled elsewhere).
     *
     * @param Group $originalGroup The group before changes
     * @param Group $updatedGroup The group after changes
     * @return void
     */
    private function logMeetingContactChanges(Group $originalGroup, Group $updatedGroup): void
    {
        $originalMeetings = [];
        foreach ($originalGroup->getMeetings() as $meeting) {
            $originalMeetings[$meeting->getId()] = $meeting;
        }

        foreach ($updatedGroup->getMeetings() as $updatedMeeting) {
            $meetingId = $updatedMeeting->getId();
            $originalContacts = isset($originalMeetings[$meetingId])
                ? $originalMeetings[$meetingId]->getContacts()
                : [];

            $this->logContactChanges(
                AuditLoggerInterface::ENTITY_MEETING,
                $meetingId,
                $originalContacts,
                $updatedMeeting->getContacts(),
                PersonalDataFields::MEETING_CONTACT_NAME,
                PersonalDataFields::MEETING_CONTACT_EMAIL,
                PersonalDataFields::MEETING_CONTACT_PHONE
            );
        }
    }

    /**
     * Compare two arrays of contacts and log an update for each field type
     * (name, email, phone) that differs between them.
     *
     * Contacts are normalised into sorted "name|email|phone" keys so that
     * reordering alone does not trigger a log entry.
     *
     * @param string $entityType The entity type constant (group or meeting)
     * @param int    $entityId   The entity post ID
     * @param array  $originalContacts Contacts before the change
     * @param array  $updatedContacts  Contacts after the change
     * @param string $nameField  The PersonalDataFields constant for the name field
     * @param string $emailField The PersonalDataFields constant for the email field
     * @param string $phoneField The PersonalDataFields constant for the phone field
     * @return void
     */
    private function logContactChanges(
        string $entityType,
        int $entityId,
        array $originalContacts,
        array $updatedContacts,
        string $nameField,
        string $emailField,
        string $phoneField
    ): void {
        $normalize = static function (array $contacts): array {
            $names = [];
            $emails = [];
            $phones = [];
            foreach ($contacts as $contact) {
                $names[] = $contact->getName();
                $emails[] = $contact->getEmail();
                $phones[] = $contact->getPhone();
            }
            sort($names);
            sort($emails);
            sort($phones);
            return ['names' => $names, 'emails' => $emails, 'phones' => $phones];
        };

        $original = $normalize($originalContacts);
        $updated = $normalize($updatedContacts);

        if ($original['names'] !== $updated['names']) {
            $this->logger->log(
                AuditLoggerInterface::ACTION_UPDATE,
                $entityType,
                $entityId,
                $nameField,
                'Contact name changed'
            );
        }

        if ($original['emails'] !== $updated['emails']) {
            $this->logger->log(
                AuditLoggerInterface::ACTION_UPDATE,
                $entityType,
                $entityId,
                $emailField,
                'Contact email changed'
            );
        }

        if ($original['phones'] !== $updated['phones']) {
            $this->logger->log(
                AuditLoggerInterface::ACTION_UPDATE,
                $entityType,
                $entityId,
                $phoneField,
                'Contact phone changed'
            );
        }
    }

    /**
     * Log personal data deletion when a member is deleted or trashed
     *
     * Triggered by the unity/member_deleted hook fired from MemberChangeTracker.
     *
     * @param int $postId The post ID being deleted or trashed
     * @param Member|null $member The member at the time of deletion (may be null)
     * @return void
     */
    public function onMemberDeleted(int $postId, ?Member $member = null): void
    {
        $this->logger->logBatch(
            AuditLoggerInterface::ACTION_DELETE,
            AuditLoggerInterface::ENTITY_MEMBER,
            $postId,
            PersonalDataFields::ALL_FIELDS,
            'Member deleted'
        );
    }

    /**
     * Log contact data deletion when a group is deleted or trashed
     *
     * Triggered by the unity/group_deleted hook fired from GroupChangeTracker.
     *
     * @param int $postId The post ID being deleted or trashed
     * @param Group|null $group The group at the time of deletion (may be null)
     * @return void
     */
    public function onGroupDeleted(int $postId, ?Group $group = null): void
    {
        $this->logger->logBatch(
            AuditLoggerInterface::ACTION_DELETE,
            AuditLoggerInterface::ENTITY_GROUP,
            $postId,
            PersonalDataFields::GROUP_CONTACT_FIELDS,
            'Group deleted'
        );
    }

    /**
     * Log when a group is hidden (post status set to private)
     *
     * Triggered by the unity/group_hidden hook fired from GroupChangeTracker.
     *
     * @param int $postId The post ID that was hidden
     * @param Group|null $group The group at the time of hiding (may be null)
     * @return void
     */
    public function onGroupHidden(int $postId, ?Group $group = null): void
    {
        $this->logger->logBatch(
            AuditLoggerInterface::ACTION_UPDATE,
            AuditLoggerInterface::ENTITY_GROUP,
            $postId,
            PersonalDataFields::GROUP_CONTACT_FIELDS,
            'Group hidden (set to private)'
        );
    }
}