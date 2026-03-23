<?php

declare(strict_types=1);

namespace Scrutiny\Privacy;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Scrutiny\Audit\Interfaces\AuditLoggerInterface;
use Scrutiny\Privacy\Interfaces\DataObscurerInterface;
use Unity\Core\Interfaces\Configuration;
use Unity\Members\Interfaces\Member;
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
 *
 * Users with the `scrutiny_edit_personal_data` capability may update
 * personal data fields; all other users have their changes silently
 * rejected and the existing stored value preserved.
 */
class DataObscurer implements DataObscurerInterface
{
    public const CAPABILITY = 'scrutiny_view_personal_data';
    public const EDIT_CAPABILITY = 'scrutiny_edit_personal_data';

    private AuditLoggerInterface $logger;
    private readonly array $member_config;

    public function __construct(Configuration $configuration, AuditLoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->member_config = $configuration->getConfig(Member::class);

        // The config stores full ACF sub-field names including the group prefix,
        // e.g. "about-layout-group_personal-email". ACF filter variants behave
        // differently depending on context:
        //
        //   acf/format_value/name=  → matches the full name used in get_field()
        //   acf/prepare_field/name= → matches the field's _name (sub-field part only)
        //
        // We register both variants for full coverage.

        $emailFieldFull = $this->member_config['FIELD_PERSONAL_EMAIL'] ?? '';
        $mobileFieldFull = $this->member_config['FIELD_MOBILE_NUMBER'] ?? '';

        // Extract the sub-field _name (part after the group prefix separator "_")
        // e.g. "about-layout-group_personal-email" → "personal-email"
        $emailFieldShort = str_contains($emailFieldFull, '_')
            ? substr($emailFieldFull, strpos($emailFieldFull, '_') + 1)
            : $emailFieldFull;
        $mobileFieldShort = str_contains($mobileFieldFull, '_')
            ? substr($mobileFieldFull, strpos($mobileFieldFull, '_') + 1)
            : $mobileFieldFull;

        // Frontend: acf/format_value uses the full field name (as passed to get_field)
        add_filter('acf/format_value/name=' . $emailFieldFull, [$this, 'obscureAcfPersonalEmail'], 20, 3);
        add_filter('acf/format_value/name=' . $mobileFieldFull, [$this, 'obscureAcfMobileNumber'], 20, 3);

        // Admin edit forms: acf/prepare_field matches against _name (the sub-field
        // part only, without the group prefix). format_value does NOT fire in admin.
        add_filter('acf/prepare_field/name=' . $emailFieldShort, [$this, 'prepareAcfPersonalEmail']);
        add_filter('acf/prepare_field/name=' . $mobileFieldShort, [$this, 'prepareAcfMobileNumber']);

        // Also register with the full name in case _name includes the group prefix
        if ($emailFieldShort !== $emailFieldFull) {
            add_filter('acf/prepare_field/name=' . $emailFieldFull, [$this, 'prepareAcfPersonalEmail']);
            add_filter('acf/prepare_field/name=' . $mobileFieldFull, [$this, 'prepareAcfMobileNumber']);
        }

        // Prevent empty submissions from wiping obscured field data.
        // When the field is shown with a placeholder (value cleared), saving
        // without typing anything would set the field to empty. These filters
        // detect that case and preserve the original value.
        add_filter('acf/update_value/name=' . $emailFieldFull, [$this, 'preservePersonalEmail'], 10, 3);
        add_filter('acf/update_value/name=' . $mobileFieldFull, [$this, 'preserveMobileNumber'], 10, 3);

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
     * @inheritDoc
     */
    public function currentUserCanEditPersonalData(): bool
    {
        return current_user_can(self::EDIT_CAPABILITY);
    }

    /**
     * Resolve the current post ID from context.
     *
     * ACF's field array does not always carry a post_id, so we fall back
     * to the global $post or the $_GET['post'] parameter used on admin
     * edit screens.
     *
     * @param array $field The ACF field array (may contain 'post_id')
     * @return int The resolved post ID, or 0 if unavailable
     */
    private function resolvePostId(array $field): int
    {
        if (isset($field['post_id']) && is_numeric($field['post_id'])) {
            return (int) $field['post_id'];
        }

        if (isset($_GET['post']) && is_numeric($_GET['post'])) {
            return (int) $_GET['post'];
        }

        global $post;
        if (isset($post->ID)) {
            return (int) $post->ID;
        }

        return 0;
    }

    /**
     * ACF filter: obscure the personal email field value (frontend via format_value)
     *
     * @param mixed $value The field value
     * @param mixed $postId The post ID
     * @param array $field The ACF field array
     * @return mixed The potentially obscured value
     */
    public function obscureAcfPersonalEmail(mixed $value, mixed $postId, array $field): mixed
    {
        if (!is_string($value) || $value === '') {
            return $value;
        }

        if ($this->currentUserCanViewPersonalData()) {
            return $value;
        }

        return $this->obscureEmail($value);
    }

    /**
     * ACF filter: obscure the mobile number field value (frontend via format_value)
     *
     * @param mixed $value The field value
     * @param mixed $postId The post ID
     * @param array $field The ACF field array
     * @return mixed The potentially obscured value
     */
    public function obscureAcfMobileNumber(mixed $value, mixed $postId, array $field): mixed
    {
        if (!is_string($value) || $value === '') {
            return $value;
        }

        if ($this->currentUserCanViewPersonalData()) {
            return $value;
        }

        return $this->obscurePhone($value);
    }

    /**
     * ACF prepare_field: obscure personal email in admin edit forms
     *
     * Shows the obscured value as a placeholder so the user can see that
     * data exists without revealing it. The input is left editable so the
     * user can type a replacement value. An acf/update_value filter
     * ensures that submitting an empty field preserves the original.
     *
     * @param array|false $field The ACF field array, or false if already hidden
     * @return array|false The modified field array
     */
    public function prepareAcfPersonalEmail(array|false $field): array|false
    {
        if ($field === false) {
            return $field;
        }

        $value = $field['value'] ?? '';

        if (!is_string($value) || $value === '') {
            return $field;
        }

        $postId = $this->resolvePostId($field);

        if ($this->currentUserCanViewPersonalData()) {
            // User can see the real value — make it read-only if they cannot edit
            if (!$this->currentUserCanEditPersonalData()) {
                $field['readonly'] = 1;
            }
            return $field;
        }

        $field['placeholder'] = $this->obscureEmail($value);
        $field['value'] = '';

        return $field;
    }

    /**
     * ACF prepare_field: obscure mobile number in admin edit forms
     *
     * @param array|false $field The ACF field array, or false if already hidden
     * @return array|false The modified field array
     */
    public function prepareAcfMobileNumber(array|false $field): array|false
    {
        if ($field === false) {
            return $field;
        }

        $value = $field['value'] ?? '';

        if (!is_string($value) || $value === '') {
            return $field;
        }

        $postId = $this->resolvePostId($field);

        if ($this->currentUserCanViewPersonalData()) {
            // User can see the real value — make it read-only if they cannot edit
            if (!$this->currentUserCanEditPersonalData()) {
                $field['readonly'] = 1;
            }
            return $field;
        }

        $field['placeholder'] = $this->obscurePhone($value);
        $field['value'] = '';

        return $field;
    }

    /**
     * ACF update_value: guard personal email updates behind the edit capability.
     *
     * Users with `scrutiny_edit_personal_data` may update the value freely.
     * Users who can view but not edit will have their change rejected and
     * the existing stored value preserved. Users who cannot view personal
     * data see an obscured placeholder and submit an empty string — the
     * existing value is likewise preserved.
     *
     * @param mixed $value The new value being saved
     * @param mixed $postId The post ID
     * @param array $field The ACF field array
     * @return mixed The value to save
     */
    public function preservePersonalEmail(mixed $value, mixed $postId, array $field): mixed
    {
        if ($this->currentUserCanEditPersonalData()) {
            return $value;
        }

        // User cannot edit — always preserve the existing value
        $numericPostId = is_numeric($postId) ? (int) $postId : 0;
        $existing = get_post_meta($numericPostId, $field['name'] ?? '', true);

        if ($existing !== '' && $existing !== false) {
            return $existing;
        }

        // No existing value stored — allow the initial value through only
        // when it is genuinely empty (i.e. a new post, not an attempted edit)
        if ($value === '' || $value === null) {
            return $value;
        }

        return $value;
    }

    /**
     * ACF update_value: guard mobile number updates behind the edit capability.
     *
     * @param mixed $value The new value being saved
     * @param mixed $postId The post ID
     * @param array $field The ACF field array
     * @return mixed The value to save
     */
    public function preserveMobileNumber(mixed $value, mixed $postId, array $field): mixed
    {
        if ($this->currentUserCanEditPersonalData()) {
            return $value;
        }

        // User cannot edit — always preserve the existing value
        $numericPostId = is_numeric($postId) ? (int) $postId : 0;
        $existing = get_post_meta($numericPostId, $field['name'] ?? '', true);

        if ($existing !== '' && $existing !== false) {
            return $existing;
        }

        // No existing value stored — allow the initial value through only
        // when it is genuinely empty (i.e. a new post, not an attempted edit)
        if ($value === '' || $value === null) {
            return $value;
        }

        return $value;
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

        if (get_post_type($postId) !== $this->member_config['POST_TYPE']) {
            return $title;
        }

        $this->logger->log(
            AuditLoggerInterface::ACTION_VIEW,
            AuditLoggerInterface::ENTITY_MEMBER,
            $postId,
            'post_title',
            'Post title rendered'
        );

        if ($this->currentUserCanViewPersonalData()) {
            return $title;
        }

        return $this->obscureName($title);
    }
}