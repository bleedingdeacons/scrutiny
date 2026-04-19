<?php

declare(strict_types=1);

namespace Scrutiny\Privacy\Interfaces;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Data Obscurer
 *
 * A component that obscures personal data in a specific storage context
 * (ACF member fields, TSML contact postmeta, …) by registering WordPress
 * hooks.
 *
 * Implementations hold only their own context-specific configuration.
 * Shared policy — capability checks, the fixed placeholder, the tier
 * enum, the obscuring helpers — lives in {@see \Scrutiny\Privacy\PersonalDataPolicy}
 * and is injected.
 *
 * The boot sequence in {@see \Scrutiny\Plugin} instantiates each obscurer
 * via the container and calls {@see self::register()} to wire its hooks.
 */
interface DataObscurer
{
    /**
     * Register all WordPress hooks required to obscure this context.
     *
     * Called once, during plugin boot.
     */
    public function register(): void;
}
