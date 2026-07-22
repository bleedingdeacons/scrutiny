<?php

declare(strict_types=1);

namespace Scrutiny\Tests\Unit\Privacy;

use PHPUnit\Framework\TestCase;
use Scrutiny\Privacy\GroupFieldsObscurer;
use Scrutiny\Privacy\PersonalDataPolicy;
use WP_Mock;
use WP_Post;

/**
 * Tests for GroupFieldsObscurer — the $_POST strip on save and the admin
 * mask/lock UI emission.
 *
 * @covers \Scrutiny\Privacy\GroupFieldsObscurer
 */
class GroupFieldsObscurerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();
        $GLOBALS['scrutiny_test_capabilities'] = [];
        $GLOBALS['scrutiny_test_post_meta'] = [];
        $GLOBALS['scrutiny_test_actions'] = [];

        // protectedContactFields()'s two filters pass through unchanged
        // (WP_Mock's default apply_filters behaviour), so the default set
        // of six contact fields is used.
        // The banner text passes through translation untouched.
        WP_Mock::userFunction('__')->andReturnUsing(fn ($text) => $text);
        // The admin UI serialises field lists/masks to JSON for its script.
        WP_Mock::userFunction('wp_json_encode')->andReturnUsing(
            fn ($data, int $options = 0) => json_encode($data, $options)
        );
    }

    protected function tearDown(): void
    {
        WP_Mock::tearDown();
        unset($_POST, $_GET);
        $_POST = [];
        $_GET = [];
        parent::tearDown();
    }

    private function obscurer(): GroupFieldsObscurer
    {
        return new GroupFieldsObscurer(new PersonalDataPolicy());
    }

    // ─── register ──────────────────────────────────────────────────

    /**
     * @test
     */
    public function register_always_wires_the_save_strip_and_admin_ui_when_admin(): void
    {
        WP_Mock::userFunction('is_admin')->andReturn(true);

        $this->obscurer()->register();

        $hooks = array_column($GLOBALS['scrutiny_test_actions'], 'hook');
        $this->assertContains('save_post_tsml_meeting', $hooks);
        $this->assertContains('save_post_tsml_group', $hooks);
        $this->assertContains('admin_footer-post.php', $hooks);
        $this->assertContains('admin_footer-post-new.php', $hooks);
    }

    /**
     * @test
     */
    public function register_skips_the_admin_ui_hooks_outside_admin(): void
    {
        WP_Mock::userFunction('is_admin')->andReturn(false);

        $this->obscurer()->register();

        $hooks = array_column($GLOBALS['scrutiny_test_actions'], 'hook');
        $this->assertContains('save_post_tsml_group', $hooks);
        $this->assertNotContains('admin_footer-post.php', $hooks);
    }

    // ─── stripProtectedFields ──────────────────────────────────────

    /**
     * @test
     */
    public function strip_removes_protected_fields_for_a_user_who_cannot_edit(): void
    {
        WP_Mock::userFunction('wp_is_post_autosave')->andReturn(false);
        WP_Mock::userFunction('wp_is_post_revision')->andReturn(false);

        $_POST = [
            'contact_1_email' => 'leak@example.com',
            'contact_1_phone' => '0700',
            'post_title'      => 'kept',
        ];

        $this->obscurer()->stripProtectedFields(5, new WP_Post(['ID' => 5]));

        $this->assertArrayNotHasKey('contact_1_email', $_POST);
        $this->assertArrayNotHasKey('contact_1_phone', $_POST);
        $this->assertSame('kept', $_POST['post_title']);
    }

    /**
     * @test
     */
    public function strip_leaves_post_untouched_for_a_user_who_can_edit(): void
    {
        $GLOBALS['scrutiny_test_capabilities'][PersonalDataPolicy::EDIT_CAPABILITY] = true;

        WP_Mock::userFunction('wp_is_post_autosave')->andReturn(false);
        WP_Mock::userFunction('wp_is_post_revision')->andReturn(false);

        $_POST = ['contact_1_email' => 'kept@example.com'];

        $this->obscurer()->stripProtectedFields(5, new WP_Post(['ID' => 5]));

        $this->assertSame('kept@example.com', $_POST['contact_1_email']);
    }

    /**
     * @test
     */
    public function strip_skips_autosaves_and_revisions(): void
    {
        WP_Mock::userFunction('wp_is_post_autosave')->andReturn(true);
        WP_Mock::userFunction('wp_is_post_revision')->andReturn(false);

        $_POST = ['contact_1_email' => 'kept@example.com'];

        $this->obscurer()->stripProtectedFields(5, new WP_Post(['ID' => 5]));

        // Early return before the strip loop.
        $this->assertSame('kept@example.com', $_POST['contact_1_email']);
    }

    // ─── emitAdminUi ───────────────────────────────────────────────

    /**
     * @test
     */
    public function emit_admin_ui_outputs_nothing_for_an_editor(): void
    {
        $GLOBALS['scrutiny_test_capabilities'][PersonalDataPolicy::EDIT_CAPABILITY] = true;

        $output = $this->captureEmit('tsml_group', 5);

        $this->assertSame('', $output);
    }

    /**
     * @test
     */
    public function emit_admin_ui_outputs_nothing_on_an_unsupported_post_type(): void
    {
        $output = $this->captureEmit('post', 5);

        $this->assertSame('', $output);
    }

    /**
     * @test
     */
    public function emit_admin_ui_shows_a_read_only_banner_for_a_view_only_user(): void
    {
        $GLOBALS['scrutiny_test_capabilities'][PersonalDataPolicy::VIEW_CAPABILITY] = true;

        $output = $this->captureEmit('tsml_group', 5);

        $this->assertStringContainsString('scrutiny-tsml-style', $output);
        $this->assertStringContainsString('scrutiny-tsml-script', $output);
        $this->assertStringContainsString('Named contact fields are read-only.', $output);
        // View-only users see real values, so masking is off.
        $this->assertStringContainsString('var APPLY_MASK = false;', $output);
    }

    /**
     * @test
     */
    public function emit_admin_ui_masks_values_for_a_user_with_no_access(): void
    {
        // A group post: contact meta lives on the group itself.
        $GLOBALS['scrutiny_test_post_meta'][5]['contact_1_email'] = 'secret@example.com';

        WP_Mock::userFunction('get_post_type')->with(5)->andReturn('tsml_group');

        $output = $this->captureEmit('tsml_group', 5);

        $this->assertStringContainsString('Named contact fields are hidden.', $output);
        $this->assertStringContainsString('var APPLY_MASK = true;', $output);
        // The masked preview, not the real value, is embedded. wp_json_encode
        // escapes the bullet placeholder to its \uXXXX unicode form.
        $encodedPlaceholder = trim(json_encode(PersonalDataPolicy::FIXED_PLACEHOLDER), '"');
        $this->assertStringContainsString($encodedPlaceholder, $output);
        $this->assertStringNotContainsString('secret@example.com', $output);
    }

    /**
     * @test
     */
    public function emit_admin_ui_for_a_meeting_reads_contact_meta_from_the_linked_group(): void
    {
        // Meeting 5 points at group 9 via group_id meta; the masked values
        // are read from the group, not the meeting.
        $GLOBALS['scrutiny_test_post_meta'][5]['group_id'] = '9';
        $GLOBALS['scrutiny_test_post_meta'][9]['contact_1_email'] = 'secret@example.com';

        WP_Mock::userFunction('get_post_type')->with(5)->andReturn('tsml_meeting');

        $output = $this->captureEmit('tsml_meeting', 5);

        $encodedPlaceholder = trim(json_encode(PersonalDataPolicy::FIXED_PLACEHOLDER), '"');
        $this->assertStringContainsString('var APPLY_MASK = true;', $output);
        $this->assertStringContainsString($encodedPlaceholder, $output);
    }

    /**
     * Run emitAdminUi with $post_type and the edited post's ID/$_GET set up,
     * capturing everything it echoes.
     */
    private function captureEmit(string $postType, int $postId): string
    {
        global $post_type, $post;
        $post_type = $postType;
        $post = null;
        $_GET['post'] = (string) $postId;

        ob_start();
        try {
            $this->obscurer()->emitAdminUi();
        } finally {
            $output = ob_get_clean();
        }

        return $output;
    }
}
