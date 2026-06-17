<?php

declare(strict_types=1);

namespace Scrutiny\Audit\Interfaces;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Audit Logger Interface
 *
 * Defines the contract for GDPR compliant audit logging of personal data access and changes.
 */
interface AuditLogger
{
    public const ACTION_VIEW = 'view';
    public const ACTION_CREATE = 'create';
    public const ACTION_UPDATE = 'update';
    public const ACTION_DELETE = 'delete';
    public const ACTION_EXPORT = 'export';
    public const ACTION_IMPORT = 'import';
    public const ACTION_CALL = 'call';
    public const ACTION_MESSAGE = 'message';

    public const ENTITY_MEMBER = 'member';
    public const ENTITY_GROUP = 'group';
    public const ENTITY_MEETING = 'meeting';
    public const ENTITY_POSITION = 'position';

    /**
     * Log an access or change event for personal data
     *
     * @param string $action The action performed (view, create, update, delete, export, import)
     * @param string $entityType The type of entity (e.g. 'member')
     * @param int $entityId The ID of the entity
     * @param string $fieldName The personal data field accessed or changed
     * @param string $detail Optional detail about the event (must not contain raw PII)
     * @return void
     */
    public function log(
        string $action,
        string $entityType,
        int $entityId,
        string $fieldName,
        string $detail = ''
    ): void;

    /**
     * Log a batch of field accesses in a single event
     *
     * @param string $action The action performed
     * @param string $entityType The type of entity
     * @param int $entityId The ID of the entity
     * @param array<string> $fieldNames Array of personal data field names accessed
     * @param string $detail Optional detail
     * @return void
     */
    public function logBatch(
        string $action,
        string $entityType,
        int $entityId,
        array $fieldNames,
        string $detail = ''
    ): void;
}