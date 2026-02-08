<?php

declare(strict_types=1);

namespace Scrutiny\Audit;

use Scrutiny\Audit\Interfaces\AuditLoggerInterface;
use Scrutiny\Privacy\PersonalDataFields;
use Unity\Members\Interfaces\MemberInterface;
use Unity\Members\MemberConstants;
use function add_action;
use function add_filter;
use function get_post_type;
use function is_admin;

/**
 * Audit Tracker
 *
 * Hooks into Unity's member lifecycle events and ACF field loading to automatically log
 * creation, updates, viewing, and deletion of personal data fields.
 *
 * Listens to:
 *   - current_screen      (fired when admin screen loads - used for admin form view tracking)
 *   - acf/load_value      (fired when ACF loads a field value - used for frontend view tracking)
 *   - member_changed      (fired by MemberChangeTracker when fields change)
 *   - before_delete_post  (WordPress hook for post deletion)
 *   - wp_trash_post       (WordPress hook for post trashing)
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

    public function __construct(AuditLoggerInterface $logger)
    {
        $this->logger = $logger;

        // Log when a member edit form is displayed in admin
        add_action('current_screen', [$this, 'onMemberAdminFormDisplayed'], 10, 1);

        // Log when personal data fields are accessed via ACF on frontend
        add_filter('acf/load_value', [$this, 'onPersonalDataFieldLoaded'], 10, 3);

        // Log personal data changes when a member is updated
        add_action('member_changed', [$this, 'onMemberChanged'], 10, 2);

        // Log personal data deletion when a member post is trashed or deleted
        add_action('before_delete_post', [$this, 'onMemberDeleted'], 10, 2);
        add_action('wp_trash_post', [$this, 'onMemberTrashed'], 10, 1);
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
        if ($screen->post_type !== MemberConstants::MEMBER_POST_TYPE) {
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
        if (get_post_type($postId) !== MemberConstants::MEMBER_POST_TYPE) {
            return $value;
        }

        // Only log on frontend, not in admin
        if (is_admin()) {
            return $value;
        }

        // Check if this is a personal data field
        $fieldKey = $field['key'] ?? '';
        if (!isset(PersonalDataFields::ACF_FIELD_MAP[$fieldKey])) {
            return $value;
        }

        // Get the logical field name
        $fieldName = PersonalDataFields::ACF_FIELD_MAP[$fieldKey];

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
     * @param MemberInterface $updatedMember The member after changes
     * @param MemberInterface $originalMember The member before changes
     * @return void
     */
    public function onMemberChanged(MemberInterface $updatedMember, MemberInterface $originalMember): void
    {
        $memberId = $updatedMember->getId();

//        if ($originalMember->getPrivateName() !== $updatedMember->getPrivateName()) {
//            $this->logger->log(
//                AuditLoggerInterface::ACTION_UPDATE,
//                AuditLoggerInterface::ENTITY_MEMBER,
//                $memberId,
//                PersonalDataFields::PRIVATE_NAME,
//                'Value changed'
//            );
//        }

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
     * Log personal data deletion when a member post is permanently deleted
     *
     * @param int $postId The post ID being deleted
     * @param \WP_Post|null $post The post object
     * @return void
     */
    public function onMemberDeleted(int $postId, $post = null): void
    {
        if (get_post_type($postId) !== MemberConstants::MEMBER_POST_TYPE) {
            return;
        }

        $this->logger->logBatch(
            AuditLoggerInterface::ACTION_DELETE,
            AuditLoggerInterface::ENTITY_MEMBER,
            $postId,
            PersonalDataFields::ALL_FIELDS,
            'Member permanently deleted'
        );
    }

    /**
     * Log personal data when a member post is trashed
     *
     * @param int $postId The post ID being trashed
     * @return void
     */
    public function onMemberTrashed(int $postId): void
    {
        if (get_post_type($postId) !== MemberConstants::MEMBER_POST_TYPE) {
            return;
        }

        $this->logger->logBatch(
            AuditLoggerInterface::ACTION_DELETE,
            AuditLoggerInterface::ENTITY_MEMBER,
            $postId,
            PersonalDataFields::ALL_FIELDS,
            'Member moved to trash'
        );
    }
}