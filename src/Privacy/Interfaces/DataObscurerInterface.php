<?php

declare(strict_types=1);

namespace Scrutiny\Privacy\Interfaces;

/**
 * Data Obscurer Interface
 *
 * Defines the contract for obscuring personal data values in the UI.
 */
interface DataObscurerInterface
{
    /**
     * Obscure a personal name (first name and initial)
     *
     * Example: "John S" → "J*** S"
     *
     * @param string $name The name to obscure
     * @return string The obscured name
     */
    public function obscureName(string $name): string;

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
}
