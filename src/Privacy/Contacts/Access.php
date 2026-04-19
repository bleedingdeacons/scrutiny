<?php

declare(strict_types=1);

namespace Scrutiny\Privacy\Contacts;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Scrutiny\Privacy\PersonalDataObscurer;
use function current_user_can;

/**
 * Access tier for the protected TSML contact fields.
 *
 *   EDIT — holds scrutiny_edit_personal_data, may freely change values.
 *   VIEW — holds scrutiny_view_personal_data, sees real values but
 *          cannot change them.
 *   NONE — holds neither; sees masked placeholders and cannot change
 *          values.
 */
enum Tier: string
{
    case NONE = 'none';
    case VIEW = 'view';
    case EDIT = 'edit';
}

/**
 * Capability gate for the TSML contact-field protection.
 *
 * Wraps the two Scrutiny capabilities so the rest of the TSML module
 * never calls current_user_can() directly. Intentionally reuses the
 * same capability constants as {@see PersonalDataObscurer} — the two
 * components protect different storage mechanisms (ACF fields and
 * TSML postmeta) but share a single permissions model.
 */
final class Access
{
    public function canView(): bool
    {
        return current_user_can(PersonalDataObscurer::VIEW_CAPABILITY)
            || current_user_can(PersonalDataObscurer::EDIT_CAPABILITY);
    }

    public function canEdit(): bool
    {
        return current_user_can(PersonalDataObscurer::EDIT_CAPABILITY);
    }

    public function tier(): Tier
    {
        return match (true) {
            $this->canEdit() => Tier::EDIT,
            $this->canView() => Tier::VIEW,
            default          => Tier::NONE,
        };
    }
}
