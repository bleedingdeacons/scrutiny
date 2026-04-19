<?php

declare(strict_types=1);

namespace Scrutiny\Privacy\Contacts;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use WP_Post;
use function add_action;
use function wp_is_post_autosave;
use function wp_is_post_revision;

/**
 * Strips the protected TSML contact fields from $_POST before TSML's
 * own save handler runs, so DOM tampering (editing masked values,
 * re-enabling readonly inputs, forging a hidden field) cannot commit
 * changes.
 *
 * Runs at priority 1 on save_post_{type} to execute before TSML's
 * own save logic. Covers both tsml_meeting and tsml_group since the
 * contact fields live on (and are editable from) both post types.
 */
final class SaveGuard
{
    private const SUPPORTED_POST_TYPES = ['tsml_meeting', 'tsml_group'];

    public function __construct(
        private readonly Access $access,
        private readonly ProtectedFields $fields,
    ) {
        foreach (self::SUPPORTED_POST_TYPES as $postType) {
            add_action("save_post_{$postType}", [$this, 'strip'], 1, 2);
        }
    }

    public function strip(int $postId, WP_Post $post): void
    {
        if (wp_is_post_autosave($postId) || wp_is_post_revision($postId)) {
            return;
        }
        if ($this->access->canEdit()) {
            return;
        }
        foreach ($this->fields->all() as $field) {
            if (isset($_POST[$field])) {
                unset($_POST[$field]);
            }
        }
    }
}
