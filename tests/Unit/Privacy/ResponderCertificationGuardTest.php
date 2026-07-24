<?php

declare(strict_types=1);

namespace Scrutiny\Tests\Unit\Privacy;

use PHPUnit\Framework\TestCase;
use Scrutiny\Privacy\ResponderCertificationGuard;
use Unity\Core\Interfaces\Configuration;
use Unity\Members\Interfaces\Member;
use WP_Mock;

/**
 * Tests for ResponderCertificationGuard.
 *
 * The guard keeps the member responder-certification field visible but
 * read-only for users without the scrutiny_edit_responder_certification
 * capability: prepare_field disables the input, and update_value preserves
 * the stored value on save. REST writes (Integrity) are let through because
 * they authenticate with their own permission system and have no current
 * user for current_user_can() to test.
 */
class ResponderCertificationGuardTest extends TestCase
{
    private const FIELD_RESPONDER_CERTIFICATION = 'service-layout-group_responder-certification';
    private const KEY_RESPONDER_CERTIFICATION   = 'field_6a5a5d9e7dcec';

    protected function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();

        // The bootstrap's in-memory stubs back current_user_can() and
        // get_field(); reset them so capabilities and stored values do not
        // leak between cases.
        $GLOBALS['scrutiny_test_capabilities'] = [];
        $GLOBALS['scrutiny_test_acf_fields'] = [];

        // add_action is a bootstrap recorder; register() also wires an action,
        // so reset the recorder between cases.
        $GLOBALS['scrutiny_test_actions'] = [];
    }

    protected function tearDown(): void
    {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    private const POST_TYPE = 'member';

    /**
     * @param array<string, mixed>|null $config Config override; null uses the
     *                                           standard fully-populated config.
     */
    private function makeGuard(?array $config = null): ResponderCertificationGuard
    {
        $configuration = $this->createMock(Configuration::class);
        $configuration->method('getConfig')
            ->with(Member::class)
            ->willReturn($config ?? [
                'FIELD_RESPONDER_CERTIFICATION' => self::FIELD_RESPONDER_CERTIFICATION,
                'KEY_RESPONDER_CERTIFICATION'   => self::KEY_RESPONDER_CERTIFICATION,
                'POST_TYPE'                     => self::POST_TYPE,
            ]);

        return new ResponderCertificationGuard($configuration);
    }

    /**
     * A minimal ACF radio field array as prepare_field receives it — a
     * shortened choices set is enough to prove every value gets disabled.
     *
     * @return array<string, mixed>
     */
    private function radioField(): array
    {
        return [
            'name'    => self::FIELD_RESPONDER_CERTIFICATION,
            'key'     => self::KEY_RESPONDER_CERTIFICATION,
            'type'    => 'radio',
            'choices' => [
                'None'        => 'None',
                'Applied'     => 'Applied',
                'In Training' => 'In Training',
                'Certified'   => 'Certified',
            ],
        ];
    }

    /** @test */
    public function it_disables_every_radio_choice_for_users_without_the_capability(): void
    {
        // ACF radio reads $field['disabled'] as a list of choice values to
        // disable, not a boolean — so all choices must be listed for the
        // whole field to become read-only.
        $field = $this->makeGuard()->disableForReadOnlyUser($this->radioField());

        $this->assertIsArray($field);
        $this->assertSame(
            ['None', 'Applied', 'In Training', 'Certified'],
            $field['disabled'],
            'Every choice value must be disabled so no radio option can be changed.'
        );
        $this->assertStringContainsString(
            'scrutiny-cert-readonly',
            $field['wrapper']['class'],
            'The field wrapper must be tagged so the read-only stylesheet can grey it out.'
        );
    }

    /** @test */
    public function it_leaves_the_field_editable_for_users_with_the_capability(): void
    {
        $GLOBALS['scrutiny_test_capabilities'] = [
            ResponderCertificationGuard::EDIT_CAPABILITY => true,
        ];

        $field = $this->makeGuard()->disableForReadOnlyUser($this->radioField());

        $this->assertIsArray($field);
        $this->assertArrayNotHasKey('disabled', $field);
    }

    /** @test */
    public function it_passes_through_a_hidden_field_untouched(): void
    {
        // ACF passes false when the field is already hidden (e.g. by
        // conditional logic); the guard must not try to disable it.
        $this->assertFalse($this->makeGuard()->disableForReadOnlyUser(false));
    }

    /** @test */
    public function it_preserves_the_stored_value_when_user_cannot_edit(): void
    {
        // REST_REQUEST is intentionally not defined — admin form saves go
        // through admin-post.php, not REST.
        $GLOBALS['scrutiny_test_acf_fields'][23462][self::FIELD_RESPONDER_CERTIFICATION] = 'Certified';

        $result = $this->makeGuard()->preserveCertification(
            'Pending',
            23462,
            ['name' => self::FIELD_RESPONDER_CERTIFICATION, 'key' => self::KEY_RESPONDER_CERTIFICATION]
        );

        $this->assertSame(
            'Certified',
            $result,
            'A tampered POST from a user without the capability must not change the stored stage.'
        );
    }

    /** @test */
    public function it_lets_the_change_through_when_user_can_edit(): void
    {
        $GLOBALS['scrutiny_test_capabilities'] = [
            ResponderCertificationGuard::EDIT_CAPABILITY => true,
        ];
        $GLOBALS['scrutiny_test_acf_fields'][23462][self::FIELD_RESPONDER_CERTIFICATION] = 'Certified';

        $result = $this->makeGuard()->preserveCertification(
            'Pending',
            23462,
            ['name' => self::FIELD_RESPONDER_CERTIFICATION, 'key' => self::KEY_RESPONDER_CERTIFICATION]
        );

        $this->assertSame('Pending', $result);
    }

    /** @test */
    public function it_lets_the_initial_value_through_when_nothing_is_stored(): void
    {
        // No stored value and no capability: a create-time assignment by the
        // process that spawned the member should still land.
        $result = $this->makeGuard()->preserveCertification(
            'Applied',
            23462,
            ['name' => self::FIELD_RESPONDER_CERTIFICATION, 'key' => self::KEY_RESPONDER_CERTIFICATION]
        );

        $this->assertSame('Applied', $result);
    }

    /**
     * @test
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function it_lets_writes_through_during_rest_requests(): void
    {
        define('REST_REQUEST', true);

        // A stored value is present and the caller has no capability. If the
        // REST guard did not take effect first, the stored value would be
        // preserved instead of the new one.
        $GLOBALS['scrutiny_test_acf_fields'][23462][self::FIELD_RESPONDER_CERTIFICATION] = 'Certified';

        $result = $this->makeGuard()->preserveCertification(
            'Pending',
            23462,
            ['name' => self::FIELD_RESPONDER_CERTIFICATION, 'key' => self::KEY_RESPONDER_CERTIFICATION]
        );

        $this->assertSame('Pending', $result);
    }

    /** @test */
    public function register_wires_the_prepare_save_and_style_hooks_when_the_key_is_set(): void
    {
        $guard = $this->makeGuard();

        // prepare_field + update_value are filters (WP_Mock-tracked); the
        // enqueue hook is an action recorded by the bootstrap add_action stub.
        WP_Mock::expectFilterAdded('acf/prepare_field/key=' . self::KEY_RESPONDER_CERTIFICATION, [$guard, 'disableForReadOnlyUser']);
        WP_Mock::expectFilterAdded('acf/update_value/key=' . self::KEY_RESPONDER_CERTIFICATION, [$guard, 'preserveCertification'], 10, 3);

        $guard->register();

        WP_Mock::assertHooksAdded();
        $this->assertContains(
            'acf/input/admin_enqueue_scripts',
            array_column($GLOBALS['scrutiny_test_actions'], 'hook'),
        );
    }

    /** @test */
    public function register_is_a_noop_when_the_certification_key_is_absent(): void
    {
        // Without a configured field key there is nothing to hook: register()
        // returns before any add_filter/add_action call, so the action
        // recorder stays empty.
        $this->makeGuard(['POST_TYPE' => self::POST_TYPE])->register();

        $this->assertSame([], $GLOBALS['scrutiny_test_actions']);
    }

    /** @test */
    public function it_falls_back_to_a_boolean_disabled_for_a_non_radio_field(): void
    {
        // A field that is neither radio nor checkbox has no per-choice
        // disable semantics, so the guard uses the boolean form.
        $field = $this->makeGuard()->disableForReadOnlyUser([
            'name' => self::FIELD_RESPONDER_CERTIFICATION,
            'key'  => self::KEY_RESPONDER_CERTIFICATION,
            'type' => 'text',
        ]);

        $this->assertIsArray($field);
        $this->assertSame(1, $field['disabled']);
        $this->assertStringContainsString('scrutiny-cert-readonly', $field['wrapper']['class']);
    }

    /** @test */
    public function it_enqueues_the_readonly_style_on_the_member_screen_for_locked_users(): void
    {
        WP_Mock::userFunction('get_current_screen')
            ->andReturn((object) ['post_type' => self::POST_TYPE]);
        WP_Mock::userFunction('wp_register_style')->once();
        WP_Mock::userFunction('wp_enqueue_style')->once()->with('scrutiny-cert-readonly');
        WP_Mock::userFunction('wp_add_inline_style')
            ->once()
            ->with('scrutiny-cert-readonly', \Mockery::pattern('/scrutiny-cert-readonly/'));

        $this->makeGuard()->enqueueReadOnlyStyle();

        // The ->once() expectations are verified on tearDown; assert here too
        // so the test is not marked risky.
        $this->assertTrue(true);
    }

    /** @test */
    public function it_does_not_enqueue_the_style_for_users_who_can_edit(): void
    {
        $GLOBALS['scrutiny_test_capabilities'][ResponderCertificationGuard::EDIT_CAPABILITY] = true;

        // Returns before touching the screen or the style functions.
        WP_Mock::userFunction('wp_enqueue_style')->never();

        $this->makeGuard()->enqueueReadOnlyStyle();

        $this->assertTrue(true);
    }

    /** @test */
    public function it_does_not_enqueue_the_style_off_the_member_screen(): void
    {
        WP_Mock::userFunction('wp_enqueue_style')->never();

        // No screen resolved…
        WP_Mock::userFunction('get_current_screen')->andReturn(null);
        $this->makeGuard()->enqueueReadOnlyStyle();

        // …and a different post type.
        WP_Mock::userFunction('get_current_screen')->andReturn((object) ['post_type' => 'post']);
        $this->makeGuard()->enqueueReadOnlyStyle();

        $this->assertTrue(true);
    }
}
