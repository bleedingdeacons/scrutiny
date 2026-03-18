<?php

declare(strict_types=1);

namespace Scrutiny\Audit;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Scrutiny\Audit\Interfaces\AuditLoggerInterface;
use Scrutiny\Audit\Interfaces\AuditRepositoryInterface;
use function get_current_user_id;
use function wp_get_current_user;

/**
 * Audit Logger
 *
 * Provides GDPR compliant audit logging for personal data access and changes.
 * Logs are stored without raw PII — only field names, entity references, and
 * the identity of the accessing user are recorded.
 */
class AuditLogger implements AuditLoggerInterface
{
    private AuditRepositoryInterface $repository;

    public function __construct(AuditRepositoryInterface $repository)
    {
        $this->repository = $repository;
    }

    /**
     * @inheritDoc
     */
    public function log(
        string $action,
        string $entityType,
        int $entityId,
        string $fieldName,
        string $detail = ''
    ): void {
        $user = wp_get_current_user();

        $this->repository->insert([
            'action'      => $action,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'field_name'  => $fieldName,
            'detail'      => $detail,
            'user_id'     => (int) get_current_user_id(),
            'user_login'  => $user->user_login ?? 'system',
            'ip_address'  => self::getClientIp(),
            'logged_at'   => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @inheritDoc
     */
    public function logBatch(
        string $action,
        string $entityType,
        int $entityId,
        array $fieldNames,
        string $detail = ''
    ): void {
        foreach ($fieldNames as $fieldName) {
            $this->log($action, $entityType, $entityId, $fieldName, $detail);
        }
    }

    /**
     * Get the client IP address, anonymised to comply with GDPR
     *
     * Truncates the last octet of IPv4 or the last 80 bits of IPv6
     * to store only a subnet-level identifier rather than a precise address.
     *
     * @return string
     */
    private static function getClientIp(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        // Anonymise: remove last octet for IPv4, last 80 bits for IPv6
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            $parts[3] = '0';
            return implode('.', $parts);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $packed = inet_pton($ip);
            if ($packed !== false) {
                // Zero out last 10 bytes (80 bits)
                for ($i = 6; $i < 16; $i++) {
                    $packed[$i] = "\0";
                }
                return inet_ntop($packed);
            }
        }

        return '0.0.0.0';
    }
}
