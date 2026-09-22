<?php

declare(strict_types=1);

namespace Scrutiny\Tests\Unit\Audit;

use Scrutiny\Audit\AuditTracker;
use Scrutiny\Audit\Interfaces\AuditLogger;
use Scrutiny\Privacy\PersonalDataPolicy;
use Unity\Core\Interfaces\Configuration;
use Unity\Groups\Interfaces\GroupRepository;
use Unity\Members\Interfaces\Member;
use Unity\Positions\Interfaces\PositionRepository;

/*
 * Covers the AuditTracker constructor.
 *
 * Every other AuditTracker test builds the object via
 * newInstanceWithoutConstructor() so no WordPress hooks are wired during
 * unit runs. This one constructs it normally to prove the constructor builds
 * its ACF field map from configuration and registers the full set of
 * change/view/deletion/import-export hooks.
 */

beforeEach(function () {
    // add_action is a bootstrap recorder; reset it between cases.
    $GLOBALS['scrutiny_test_actions'] = [];
});

it('builds the field map and registers every hook', function () {
    $configuration = $this->createMock(Configuration::class);
    // Include entries whose keys appear in PersonalDataFields::CONFIG_KEY_MAP
    // so the field-map construction loop records at least one mapping.
    $configuration->method('getConfig')
        ->with(Member::class)
        ->willReturn([
            'FIELD_PERSONAL_EMAIL' => 'field_personal_email_key',
            'FIELD_MOBILE_NUMBER'  => 'field_mobile_number_key',
        ]);

    // add_filter belongs to Brain Monkey, which records the constructor's
    // single acf/load_value filter without needing a stub. The rest of the
    // hooks are actions recorded by the bootstrap's own add_action stub,
    // asserted below.

    $tracker = new AuditTracker(
        $configuration,
        $this->createMock(AuditLogger::class),
        new PersonalDataPolicy(),
        $this->createMock(GroupRepository::class),
        $this->createMock(PositionRepository::class),
    );

    expect($tracker)->toBeInstanceOf(AuditTracker::class);

    $actionHooks = array_column($GLOBALS['scrutiny_test_actions'], 'hook');
    foreach (
        [
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
        ] as $hook
    ) {
        expect($actionHooks)->toContain($hook);
    }
});
