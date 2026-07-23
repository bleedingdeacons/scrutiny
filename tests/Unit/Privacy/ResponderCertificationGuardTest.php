<?php

declare(strict_types=1);

namespace Scrutiny\Tests\Unit\Privacy;

use PHPUnit\Framework\TestCase;
use Scrutiny\Privacy\ResponderCertificationGuard;
use Unity\Core\Interfaces\Configuration;
use Unity\Members\Interfaces\Member;

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

        // The bootstrap's in-memory stubs back current_user_can() and
        // get_field(); reset them so capabilities and stored values do not
        // leak between cases.
        $GLOBALS['scrutiny_test_capabilities'] = [];
        $GLOBALS['scrutiny_test_acf_fields'] = [];
    }

    private function makeGuard(): ResponderCertificationGuard
    {
        $configuration = $this->createMock(Configuration::class);
        $configuration->method('getConfig')
            ->with(Member::class)
            ->willReturn([
                'FIELD_RESPONDER_CERTIFICATION' => self::FIELD_RESPONDER_CERTIFICATION,
                'KEY_RESPONDER_CERTIFICATION'   => self::KEY_RESPONDER_CERTIFICATION,
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
            'Denied',
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
            'Recertification Required',
            23462,
            ['name' => self::FIELD_RESPONDER_CERTIFICATION, 'key' => self::KEY_RESPONDER_CERTIFICATION]
        );

        $this->assertSame('Recertification Required', $result);
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
            'Recertification Required',
            23462,
            ['name' => self::FIELD_RESPONDER_CERTIFICATION, 'key' => self::KEY_RESPONDER_CERTIFICATION]
        );

        $this->assertSame('Recertification Required', $result);
    }
}
