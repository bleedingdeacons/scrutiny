<?php

declare(strict_types=1);

namespace Scrutiny\Privacy;

use Scrutiny\Audit\Interfaces\AuditLoggerInterface;
use Scrutiny\Privacy\Interfaces\DataObscurerInterface;
use TsmlForUnity\Members\TsmlMemberFields;
use function add_filter;
use function current_user_can;

/**
 * Data Obscurer
 *
 * Obscures personal data fields in the WordPress admin UI and logs
 * each access event to the GDPR audit trail.
 *
 * Hooks into ACF field rendering to mask personal email and mobile number
 * values on the member edit screen. The post title (private name) is
 * obscured via the WordPress `the_title` filter on admin screens.
 *
 * Users with the `scrutiny_view_personal_data` capability see unobscured
 * values; all other users see masked placeholders.
 */
class DataObscurer implements DataObscurerInterface
{
    public const CAPABILITY = 'scrutiny_view_personal_data';

    private AuditLoggerInterface $logger;

    public function __construct(AuditLoggerInterface $logger)
    {
        $this->logger = $logger;

        // Obscure ACF field values when they are loaded for display
        add_filter('acf/format_value/name=' . TsmlMemberFields::FIELD_PERSONAL_EMAIL, [$this, 'obscureAcfPersonalEmail'], 20, 3);
        add_filter('acf/format_value/name=' . TsmlMemberFields::FIELD_MOBILE_NUMBER, [$this, 'obscureAcfMobileNumber'], 20, 3);

        // Obscure the post title (private name) in admin list tables
//        add_filter('the_title', [$this, 'obscurePostTitle'], 20, 2);
    }

    /**
     * @inheritDoc
     */
    public function obscureName(string $name): string
    {
        if ($name === '') {
            return '';
        }

        $parts = explode(' ', $name);
        $obscured = [];

        foreach ($parts as $part) {
            if (mb_strlen($part) <= 1) {
                $obscured[] = $part;
            } else {
                $obscured[] = mb_substr($part, 0, 1) . str_repeat('•', mb_strlen($part) - 1);
            }
        }

        return implode(' ', $obscured);
    }

    /**
     * @inheritDoc
     */
    public function obscureEmail(string $email): string
    {
        if ($email === '' || !str_contains($email, '@')) {
            return $email !== '' ? str_repeat('•', mb_strlen($email)) : '';
        }

        [$local, $domain] = explode('@', $email, 2);

        $obscuredLocal = mb_substr($local, 0, 1) . str_repeat('•', max(mb_strlen($local) - 1, 2));

        $domainParts = explode('.', $domain);
        $tld = array_pop($domainParts);
        $domainName = implode('.', $domainParts);
        $obscuredDomain = mb_substr($domainName, 0, 1) . str_repeat('•', max(mb_strlen($domainName) - 1, 2)) . '.' . $tld;

        return $obscuredLocal . '@' . $obscuredDomain;
    }

    /**
     * @inheritDoc
     */
    public function obscurePhone(string $number): string
    {
        if ($number === '') {
            return '';
        }

        // Keep only the last 3 digits visible, replace the rest with bullets
        $digits = preg_replace('/[^0-9]/', '', $number);
        $visibleSuffix = substr($digits, -3);
        $hiddenLength = max(strlen($digits) - 3, 0);

        return str_repeat('•', $hiddenLength) . $visibleSuffix;
    }

    /**
     * @inheritDoc
     */
    public function currentUserCanViewPersonalData(): bool
    {
        return current_user_can(self::CAPABILITY);
    }

    /**
     * ACF filter: obscure the personal email field value
     *
     * @param mixed $value The field value
     * @param int $postId The post ID
     * @param array $field The ACF field array
     * @return mixed The potentially obscured value
     */
    public function obscureAcfPersonalEmail(mixed $value, int $postId, array $field): mixed
    {
        if (!is_string($value) || $value === '') {
            return $value;
        }

        $this->logger->log(
            AuditLoggerInterface::ACTION_VIEW,
            AuditLoggerInterface::ENTITY_MEMBER,
            $postId,
            PersonalDataFields::PERSONAL_EMAIL,
            'ACF field rendered'
        );

        if ($this->currentUserCanViewPersonalData()) {
            return $value;
        }

        return $this->obscureEmail($value);
    }

    /**
     * ACF filter: obscure the mobile number field value
     *
     * @param mixed $value The field value
     * @param int $postId The post ID
     * @param array $field The ACF field array
     * @return mixed The potentially obscured value
     */
    public function obscureAcfMobileNumber(mixed $value, int $postId, array $field): mixed
    {
        if (!is_string($value) || $value === '') {
            return $value;
        }

        $this->logger->log(
            AuditLoggerInterface::ACTION_VIEW,
            AuditLoggerInterface::ENTITY_MEMBER,
            $postId,
            PersonalDataFields::MOBILE_NUMBER,
            'ACF field rendered'
        );

        if ($this->currentUserCanViewPersonalData()) {
            return $value;
        }

        return $this->obscurePhone($value);
    }

    /**
     * WordPress filter: obscure the post title (private name) for member posts
     *
     * @param string $title The post title
     * @param int|null $postId The post ID
     * @return string The potentially obscured title
     */
    // TODO Remove this method and remove the field references
    public function obscurePostTitle(string $title, ?int $postId = null): string
    {
        if ($postId === null || !is_admin()) {
            return $title;
        }

        if (get_post_type($postId) !== MemberConstants::MEMBER_POST_TYPE) {
            return $title;
        }

        $this->logger->log(
            AuditLoggerInterface::ACTION_VIEW,
            AuditLoggerInterface::ENTITY_MEMBER,
            $postId,
            PersonalDataFields::PRIVATE_NAME,
            'Post title rendered'
        );

        if ($this->currentUserCanViewPersonalData()) {
            return $title;
        }

        return $this->obscureName($title);
    }
}
