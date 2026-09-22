<?php

declare(strict_types=1);

namespace Scrutiny\Tests\Unit\Privacy;

use BleedingDeacons\WpMocks\WpState;
use Brain\Monkey\Functions;
use Scrutiny\Privacy\GroupFieldsObscurer;
use Scrutiny\Privacy\PersonalDataPolicy;
use WP_Post;

/*
 * Tests for GroupFieldsObscurer — the $_POST strip on save and the admin
 * mask/lock UI emission.
 */

covers(GroupFieldsObscurer::class);

function groupObscurer(): GroupFieldsObscurer
{
    return new GroupFieldsObscurer(new PersonalDataPolicy());
}

/**
 * Run emitAdminUi with $post_type and the edited post's ID/$_GET set up,
 * capturing everything it echoes.
 */
function captureGroupAdminUi(string $postType, int $postId): string
{
    global $post_type, $post;
    $post_type = $postType;
    $post = null;
    $_GET['post'] = (string) $postId;

    ob_start();
    try {
        groupObscurer()->emitAdminUi();
    } finally {
        $output = (string) ob_get_clean();
    }

    return $output;
}

/** The fixed placeholder as wp_json_encode() writes it: \uXXXX-escaped. */
function encodedPlaceholder(): string
{
    return trim((string) json_encode(PersonalDataPolicy::FIXED_PLACEHOLDER), '"');
}

beforeEach(function () {
    $GLOBALS['scrutiny_test_capabilities'] = [];
    $GLOBALS['scrutiny_test_post_meta'] = [];
    $GLOBALS['scrutiny_test_actions'] = [];

    // protectedContactFields()'s two filters pass through unchanged (Brain
    // Monkey's apply_filters returns the value it was handed), so the default
    // set of six contact fields is used.
    //
    // __() and wp_json_encode() are real pass-through stubs in wp-mocks, so
    // neither needs standing in for here any more.
});

afterEach(function () {
    $_POST = [];
    $_GET = [];
});

// ─── register ──────────────────────────────────────────────────
describe('register', function () {
    it('always wires the save strip, and the admin UI when in admin', function () {
        WpState::$isAdmin = true;

        groupObscurer()->register();

        expect(array_column($GLOBALS['scrutiny_test_actions'], 'hook'))->toContain(
            'save_post_tsml_meeting',
            'save_post_tsml_group',
            'admin_footer-post.php',
            'admin_footer-post-new.php',
        );
    });

    it('skips the admin UI hooks outside admin', function () {
        WpState::$isAdmin = false;

        groupObscurer()->register();

        expect(array_column($GLOBALS['scrutiny_test_actions'], 'hook'))
            ->toContain('save_post_tsml_group')
            ->not->toContain('admin_footer-post.php');
    });
});

// ─── stripProtectedFields ──────────────────────────────────────
describe('stripProtectedFields', function () {
    it('removes protected fields for a user who cannot edit', function () {
        $_POST = [
            'contact_1_email' => 'leak@example.com',
            'contact_1_phone' => '0700',
            'post_title'      => 'kept',
        ];

        groupObscurer()->stripProtectedFields(5, new WP_Post(['ID' => 5]));

        expect($_POST)
            ->not->toHaveKey('contact_1_email')
            ->not->toHaveKey('contact_1_phone')
            ->post_title->toBe('kept');
    });

    it('leaves $_POST untouched for a user who can edit', function () {
        $GLOBALS['scrutiny_test_capabilities'][PersonalDataPolicy::EDIT_CAPABILITY] = true;

        $_POST = ['contact_1_email' => 'kept@example.com'];

        groupObscurer()->stripProtectedFields(5, new WP_Post(['ID' => 5]));

        expect($_POST['contact_1_email'])->toBe('kept@example.com');
    });

    it('skips autosaves and revisions', function () {
        // WordPress answers with the autosave's own post ID, not a bare true —
        // and wp-mocks types the stub int|false to match, so that is what a
        // "yes, this is an autosave" answer has to look like.
        Functions\when('wp_is_post_autosave')->justReturn(9001);

        $_POST = ['contact_1_email' => 'kept@example.com'];

        groupObscurer()->stripProtectedFields(5, new WP_Post(['ID' => 5]));

        // Early return before the strip loop.
        expect($_POST['contact_1_email'])->toBe('kept@example.com');
    });
});

// ─── emitAdminUi ───────────────────────────────────────────────
describe('emitAdminUi', function () {
    it('outputs nothing for an editor', function () {
        $GLOBALS['scrutiny_test_capabilities'][PersonalDataPolicy::EDIT_CAPABILITY] = true;

        expect(captureGroupAdminUi('tsml_group', 5))->toBe('');
    });

    it('outputs nothing on an unsupported post type', function () {
        expect(captureGroupAdminUi('post', 5))->toBe('');
    });

    it('shows a read-only banner for a view-only user', function () {
        $GLOBALS['scrutiny_test_capabilities'][PersonalDataPolicy::VIEW_CAPABILITY] = true;

        expect(captureGroupAdminUi('tsml_group', 5))->toContain(
            'scrutiny-tsml-style',
            'scrutiny-tsml-script',
            'Named contact fields are read-only.',
            // View-only users see real values, so masking is off.
            'var APPLY_MASK = false;',
        );
    });

    it('masks values for a user with no access', function () {
        // A group post: contact meta lives on the group itself.
        $GLOBALS['scrutiny_test_post_meta'][5]['contact_1_email'] = 'secret@example.com';

        WpState::addPost(5, ['post_type' => 'tsml_group']);

        expect(captureGroupAdminUi('tsml_group', 5))
            ->toContain('Named contact fields are hidden.', 'var APPLY_MASK = true;')
            // The masked preview, not the real value, is embedded.
            // wp_json_encode escapes the bullet placeholder to its \uXXXX
            // unicode form.
            ->toContain(encodedPlaceholder())
            ->not->toContain('secret@example.com');
    });

    it('reads contact meta from the linked group for a meeting', function () {
        // Meeting 5 points at group 9 via group_id meta; the masked values are
        // read from the group, not the meeting.
        $GLOBALS['scrutiny_test_post_meta'][5]['group_id'] = '9';
        $GLOBALS['scrutiny_test_post_meta'][9]['contact_1_email'] = 'secret@example.com';

        WpState::addPost(5, ['post_type' => 'tsml_meeting']);

        expect(captureGroupAdminUi('tsml_meeting', 5))
            ->toContain('var APPLY_MASK = true;', encodedPlaceholder());
    });
});
