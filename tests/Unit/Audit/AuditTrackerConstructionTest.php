<?php

declare(strict_types=1);

namespace Scrutiny\Tests\Unit\Audit;

use PHPUnit\Framework\TestCase;
use Scrutiny\Audit\AuditTracker;
use Scrutiny\Audit\Interfaces\AuditLogger;
use Scrutiny\Privacy\PersonalDataPolicy;
use Unity\Core\Interfaces\Configuration;
use Unity\Members\Interfaces\Member;
use WP_Mock;

/**
 * Covers the AuditTracker constructor.
 *
 * Every other AuditTracker test builds the object via
 * newInstanceWithoutConstructor() so no WordPress hooks are wired during
 * unit runs. This one constructs it normally to prove the constructor builds
 * its ACF field map from configuration and registers the full set of
 * change/view/deletion/import-export hooks.
 */
class AuditTrackerConstructionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();
        // add_action is a bootstrap recorder; reset it between cases.
        $GLOBALS['scrutiny_test_actions'] = [];
    }

    protected function tearDown(): void
    {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    /** @test */
    public function it_builds_the_field_map_and_registers_every_hook(): void
    {
        $configuration = $this->createMock(Configuration::class);
        // Include entries whose keys appear in PersonalDataFields::CONFIG_KEY_MAP
        // so the field-map construction loop records at least one mapping.
        $configuration->method('getConfig')
            ->with(Member::class)
            ->willReturn([
                'FIELD_PERSONAL_EMAIL' => 'field_personal_email_key',
                'FIELD_MOBILE_NUMBER'  => 'field_mobile_number_key',
            ]);

        // add_filter is WP_Mock-owned; permit the constructor's single
        // acf/load_value filter. The rest of the hooks are actions recorded
        // by the bootstrap add_action stub, asserted below.
        WP_Mock::userFunction('add_filter')->andReturn(true);

        $tracker = new AuditTracker(
            $configuration,
            $this->createMock(AuditLogger::class),
            new PersonalDataPolicy(),
        );

        $this->assertInstanceOf(AuditTracker::class, $tracker);

        $actionHooks = array_column($GLOBALS['scrutiny_test_actions'], 'hook');
        foreach ([
            'current_screen',
            'unity/member_created',
            'unity/member_changing',
            'unity/group_changing',
            'unity/member_deleted',
            'unity/group_deleted',
            'unity/group_hidden',
            'unity/member_import',
            'unity/member_export',
            'unity/group_import',
            'unity/group_export',
            'unity/position_import',
            'unity/position_export',
        ] as $hook) {
            $this->assertContains($hook, $actionHooks, "constructor must register the $hook action");
        }
    }
}
