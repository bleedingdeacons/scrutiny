<?php

declare(strict_types=1);

namespace Scrutiny\Audit;

use Scrutiny\Audit\Interfaces\AuditLoggerInterface;
use Scrutiny\Privacy\PersonalDataFields;
use Unity\Members\Interfaces\MemberInterface;
use Unity\Members\MemberConstants;
use function add_action;
use function get_post_type;

/**
 * Audit Tracker
 *
 * Hooks into Unity's member lifecycle events to automatically log
 * creation, updates, and deletion of personal data fields.
 *
 * Listens to:
 *   - member_changed      (fired by MemberChangeTracker when fields change)
 *   - member_before_save  (fired before ACF saves)
 *   - before_delete_post  (WordPress hook for post deletion)
 */
class AuditTracker
{
    private AuditLoggerInterface $logger;

    public function __construct(AuditLoggerInterface $logger)
    {
        $this->logger = $logger;

        // Log personal data changes when a member is updated
        add_action('member_changed', [$this, 'onMemberChanged'], 10, 2);

        // Log personal data deletion when a member post is trashed or deleted
        add_action('before_delete_post', [$this, 'onMemberDeleted'], 10, 2);
        add_action('wp_trash_post', [$this, 'onMemberTrashed'], 10, 1);
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

        if ($originalMember->getPrivateName() !== $updatedMember->getPrivateName()) {
            $this->logger->log(
                AuditLoggerInterface::ACTION_UPDATE,
                AuditLoggerInterface::ENTITY_MEMBER,
                $memberId,
                PersonalDataFields::PRIVATE_NAME,
                'Value changed'
            );
        }

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
