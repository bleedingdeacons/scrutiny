<?php

declare(strict_types=1);

namespace Scrutiny\Privacy\Interfaces;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Data Obscurer Interface
 *
 * Defines the contract for obscuring personal data values in the UI.
 */
interface DataObscurer
{
    /**
     * Obscure an email address
     *
     * Example: "john@example.com" → "j***@e***.com"
     *
     * @param string $email The email to obscure
     * @return string The obscured email
     */
    public function obscureEmail(string $email): string;

    /**
     * Obscure a mobile phone number
     *
     * Example: "07700 900123" → "•••••• •••123"
     *
     * @param string $number The phone number to obscure
     * @return string The obscured number
     */
    public function obscurePhone(string $number): string;

    /**
     * Check whether the current user has permission to view unobscured personal data
     *
     * @return bool
     */
    public function currentUserCanViewPersonalData(): bool;

    /**
     * Check whether the current user has permission to update personal data fields
     *
     * @return bool
     */
    public function currentUserCanEditPersonalData(): bool;
}