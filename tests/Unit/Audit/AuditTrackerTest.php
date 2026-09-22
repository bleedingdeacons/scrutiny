<?php

declare(strict_types=1);

namespace Scrutiny\Tests\Unit\Audit;

use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Scrutiny\Audit\AuditTracker;
use Scrutiny\Audit\Interfaces\AuditLogger;
use Scrutiny\Privacy\PersonalDataFields;
use Unity\Groups\Interfaces\Group;
use Unity\Groups\Interfaces\GroupRepository;
use Unity\Members\Interfaces\Member;
use Unity\Members\PreferredContact;
use Unity\Members\ResponderCertification;
use Unity\Positions\Interfaces\Position;
use Unity\Positions\Interfaces\PositionRepository;

/*
 * Tests for AuditTracker change detection.
 */

// Verification here is entirely Mockery expectations, and this file runs on
// plain PHPUnit rather than wp-mocks' TestCase. Without the trait the
// expectations are never checked or counted: PHPUnit sees no assertions and
// every test passes whatever the tracker logs.
uses(MockeryPHPUnitIntegration::class);

/**
 * Create an AuditTracker without WP hooks by using reflection.
 *
 * The repositories are only consulted once a service role actually changes,
 * so cases that leave home group and position alone can take the bare mocks
 * and never touch them.
 */
function changeTracker(
    AuditLogger $logger,
    ?GroupRepository $groupRepository = null,
    ?PositionRepository $positionRepository = null
): AuditTracker {
    $reflection = new \ReflectionClass(AuditTracker::class);
    $instance = $reflection->newInstanceWithoutConstructor();

    $reflection->getProperty('logger')->setValue($instance, $logger);
    $reflection->getProperty('groupRepository')
        ->setValue($instance, $groupRepository ?? Mockery::mock(GroupRepository::class));
    $reflection->getProperty('positionRepository')
        ->setValue($instance, $positionRepository ?? Mockery::mock(PositionRepository::class));

    return $instance;
}

/**
 * A group repository resolving the given IDs to the given titles.
 *
 * Any ID outside the map resolves to null, standing in for a group that has
 * since been deleted.
 *
 * @param array<int, string> $titles Map of group ID to group title
 */
function titledGroups(array $titles): GroupRepository
{
    $repository = Mockery::mock(GroupRepository::class);
    $repository->shouldReceive('findById')->andReturnUsing(
        static function (int $id) use ($titles): ?Group {
            if (!isset($titles[$id])) {
                return null;
            }

            $group = Mockery::mock(Group::class);
            $group->shouldReceive('getTitle')->andReturn($titles[$id]);

            return $group;
        }
    );

    return $repository;
}

/**
 * A position repository resolving the given IDs to the given long names.
 *
 * @param array<int, string> $names Map of position ID to long name
 */
function namedPositions(array $names): PositionRepository
{
    $repository = Mockery::mock(PositionRepository::class);
    $repository->shouldReceive('findById')->andReturnUsing(
        static function (int $id) use ($names): ?Position {
            if (!isset($names[$id])) {
                return null;
            }

            $position = Mockery::mock(Position::class);
            $position->shouldReceive('getLongName')->andReturn($names[$id]);

            return $position;
        }
    );

    return $repository;
}

/**
 * @param array<string, mixed> $overrides
 */
function trackedMember(array $overrides = []): Member
{
    $data = array_merge([
        'getId' => 42,
        'getPersonalEmail' => 'john@example.com',
        'getMobileNumber' => '07700 900123',
        'getLandlineNumber' => '0117 496 0123',
        'getPreferredContact' => PreferredContact::Mobile,
        'getResponderCertification' => ResponderCertification::None,
        'getHomeGroup' => 0,
        'getIntergroupPosition' => 0,
        'isGSR' => false,
        'getIntergroupPositionRotation' => '',
        'isTwelfthStepper' => false,
        'isTelephoneResponder' => false,
        'showAnonymousName' => false,
        'showMemberProfile' => false,
        'getArea' => '',
        'getAccepts' => [],
        'getAnonymousProfile' => '',
        'getMeetingPO' => null,
        'isGdprAccepted' => false,
        'getGdprAcceptedAt' => '',
        'getGdprAcceptanceVersion' => '',
        'getGdprAcceptanceMethod' => '',
        'getGdprAcceptanceStatement' => '',
    ], $overrides);

    $member = Mockery::mock(Member::class);

    foreach ($data as $method => $value) {
        $member->shouldReceive($method)->andReturn($value);
    }

    return $member;
}

beforeEach(function () {
    $this->logger = Mockery::mock(AuditLogger::class);

    // Expect exactly one member entry for this field and detail. Updates by
    // default; creation and deletion pass their own action.
    $this->expectEntry = fn (string $field, string $detail, string $action = AuditLogger::ACTION_UPDATE, int $id = 42) => $this->logger
        ->shouldReceive('log')
        ->once()
        ->with($action, AuditLogger::ENTITY_MEMBER, $id, $field, $detail);

    // Run a change from $before to $after through a tracker over the given
    // repositories.
    $this->change = fn (array $before, array $after, ?GroupRepository $groups = null, ?PositionRepository $positions = null) => changeTracker($this->logger, $groups, $positions)
        ->onMemberChanged(trackedMember($after), trackedMember($before));
});

// ─── Personal data ─────────────────────────────────────────────────
describe('personal data', function () {
    it('does not log when only the anonymous name changes', function () {
        // The anonymous name is not personal data as this plugin defines it:
        // PersonalDataFields has no constant for it and onMemberChanged does
        // not inspect it. Renaming a member on its own is not an audit event.
        $this->logger->shouldNotReceive('log');

        ($this->change)(['getAnonymousName' => 'John S'], ['getAnonymousName' => 'John T']);
    });

    it('logs a changed personal email', function () {
        ($this->expectEntry)(PersonalDataFields::PERSONAL_EMAIL, 'Value changed');

        ($this->change)(['getPersonalEmail' => 'old@example.com'], ['getPersonalEmail' => 'new@example.com']);
    });

    it('logs a changed mobile number', function () {
        ($this->expectEntry)(PersonalDataFields::MOBILE_NUMBER, 'Value changed');

        ($this->change)(['getMobileNumber' => '07700 900123'], ['getMobileNumber' => '07700 900456']);
    });

    it('logs a changed landline number', function () {
        // A landline is personal data on the same footing as a mobile, so the
        // entry records that it moved and nothing more.
        ($this->expectEntry)(PersonalDataFields::LANDLINE_NUMBER, 'Value changed');

        ($this->change)(['getLandlineNumber' => '0117 496 0123'], ['getLandlineNumber' => '0117 496 0456']);
    });

    it('names the new choice when the preferred contact changes', function () {
        // Unlike the two numbers it chooses between, this entry names its
        // value: it identifies nobody, and which line the helpline was pointed
        // at is the whole question an auditor would be asking.
        ($this->expectEntry)(PersonalDataFields::PREFERRED_CONTACT, 'Changed to Landline');

        ($this->change)(
            ['getPreferredContact' => PreferredContact::Mobile],
            ['getPreferredContact' => PreferredContact::Landline],
        );
    });

    // Unity moves a member back to Mobile when their landline goes, rather
    // than anyone touching the setting. Both entries should land: the log
    // should say the helpline stopped ringing a number, whichever edit caused
    // it.
    it('logs both the number and the preference when a landline is cleared', function () {
        ($this->expectEntry)(PersonalDataFields::LANDLINE_NUMBER, 'Value changed');
        ($this->expectEntry)(PersonalDataFields::PREFERRED_CONTACT, 'Changed to Mobile');

        ($this->change)(
            ['getLandlineNumber' => '0117 496 0123', 'getPreferredContact' => PreferredContact::Landline],
            ['getLandlineNumber' => '', 'getPreferredContact' => PreferredContact::Mobile],
        );
    });

    it('logs both tracked fields when they change together', function () {
        // Two, not three: the anonymous name changes here as well, and is
        // deliberately not audited.
        $this->logger->shouldReceive('log')->times(2);

        ($this->change)(
            ['getAnonymousName' => 'John S', 'getMobileNumber' => '07700 900123'],
            ['getAnonymousName' => 'Jane D', 'getPersonalEmail' => 'new@example.com', 'getMobileNumber' => '07700 900456'],
        );
    });

    it('does not log when no personal data changes', function () {
        $this->logger->shouldNotReceive('log');

        ($this->change)([], []);
    });
});

// ─── Responder certification ───────────────────────────────────────
describe('responder certification', function () {
    it('logs the new value when it changes', function () {
        // Unlike the personal-data fields, the certification entry names the
        // stage the member was moved to — it is a service status, not PII.
        ($this->expectEntry)(PersonalDataFields::RESPONDER_CERTIFICATION, 'Changed to Certified');

        ($this->change)(
            ['getResponderCertification' => ResponderCertification::Pending],
            ['getResponderCertification' => ResponderCertification::Certified],
        );
    });

    it('does not log when it is unchanged', function () {
        $this->logger->shouldNotReceive('log');

        ($this->change)(
            ['getResponderCertification' => ResponderCertification::Certified],
            ['getResponderCertification' => ResponderCertification::Certified],
        );
    });
});

// ─── GDPR consent ──────────────────────────────────────────────────
describe('GDPR consent', function () {
    it('logs consent recorded when the flag flips to true', function () {
        ($this->expectEntry)(PersonalDataFields::GDPR_ACCEPTED, 'Consent recorded');

        ($this->change)(['isGdprAccepted' => false], ['isGdprAccepted' => true]);
    });

    it('logs consent revoked when the flag flips to false', function () {
        ($this->expectEntry)(PersonalDataFields::GDPR_ACCEPTED, 'Consent revoked');

        ($this->change)(['isGdprAccepted' => true], ['isGdprAccepted' => false]);
    });

    it('logs consent once when a full acceptance is recorded', function () {
        // One event, not five. AuditTracker::logGdprChanges() deliberately
        // records only the acceptance flag: the timestamp, version, method and
        // statement are all stored against the member anyway, and logging each
        // of them was judged to be audit-log spam. The four tests that asserted
        // a log per sub-field were removed with this one left to state the rule.
        ($this->expectEntry)(PersonalDataFields::GDPR_ACCEPTED, 'Consent recorded');

        ($this->change)([], [
            'isGdprAccepted'             => true,
            'getGdprAcceptedAt'          => '2026-04-27 15:45:00',
            'getGdprAcceptanceVersion'   => '2.1',
            'getGdprAcceptanceMethod'    => 'api',
            'getGdprAcceptanceStatement' => 'I agree to the privacy policy.',
        ]);
    });

    it('does not log the GDPR fields when they are unchanged', function () {
        $this->logger->shouldNotReceive('log');

        $accepted = [
            'isGdprAccepted'             => true,
            'getGdprAcceptedAt'          => '2026-04-27 15:45:00',
            'getGdprAcceptanceVersion'   => '2.1',
            'getGdprAcceptanceMethod'    => 'api',
            'getGdprAcceptanceStatement' => 'I agree to the privacy policy.',
        ];

        ($this->change)($accepted, $accepted);
    });
});

// ─── Member creation ───────────────────────────────────────────────
describe('member creation', function () {
    it('logs a single create entry', function () {
        ($this->expectEntry)(PersonalDataFields::ALL_FIELDS_SENTINEL, 'Member created', AuditLogger::ACTION_CREATE);
        $this->logger->shouldNotReceive('logBatch');

        changeTracker($this->logger)->onMemberCreated(trackedMember());
    });

    it("uses the member's own id", function () {
        ($this->expectEntry)(PersonalDataFields::ALL_FIELDS_SENTINEL, 'Member created', AuditLogger::ACTION_CREATE, 999);

        changeTracker($this->logger)->onMemberCreated(trackedMember(['getId' => 999]));
    });

    it('emits no per-field log calls', function () {
        $this->logger->shouldReceive('log')->once();
        $this->logger->shouldNotReceive('logBatch');

        changeTracker($this->logger)->onMemberCreated(trackedMember());
    });
});

// ─── Member deletion ───────────────────────────────────────────────
describe('member deletion', function () {
    it('logs a batch delete entry', function (?bool $withMember) {
        $this->logger->shouldReceive('logBatch')
            ->once()
            ->with(
                AuditLogger::ACTION_DELETE,
                AuditLogger::ENTITY_MEMBER,
                42,
                array_merge(PersonalDataFields::ALL_FIELDS, PersonalDataFields::GDPR_FIELDS),
                'Member deleted'
            );

        changeTracker($this->logger)->onMemberDeleted(42, $withMember ? trackedMember() : null);
    })->with([
        'with the member object'           => [true],
        'even when the member object is null' => [false],
    ]);

    it('uses the supplied post id', function () {
        $this->logger->shouldReceive('logBatch')
            ->once()
            ->with(AuditLogger::ACTION_DELETE, AuditLogger::ENTITY_MEMBER, 7777, Mockery::type('array'), 'Member deleted');

        changeTracker($this->logger)->onMemberDeleted(7777, null);
    });

    it('emits no per-field log calls', function () {
        // The member here holds neither a home group nor a position, so the
        // batch entry is the whole of it.
        $this->logger->shouldReceive('logBatch')->once();
        $this->logger->shouldNotReceive('log');

        changeTracker($this->logger)->onMemberDeleted(42, trackedMember());
    });
});

// ─── Home group ────────────────────────────────────────────────────
describe('home group', function () {
    it('names the group when a home group is assigned', function () {
        // Home group is a service role, not personal data, so the entry says
        // which group — not merely that something changed.
        ($this->expectEntry)(PersonalDataFields::HOME_GROUP, 'Assigned to Thursday Big Book');

        ($this->change)(['getHomeGroup' => 0], ['getHomeGroup' => 7], titledGroups([7 => 'Thursday Big Book']));
    });

    it('names the group left behind when a home group is cleared', function () {
        ($this->expectEntry)(PersonalDataFields::HOME_GROUP, 'Removed from Thursday Big Book');

        ($this->change)(['getHomeGroup' => 7], ['getHomeGroup' => 0], titledGroups([7 => 'Thursday Big Book']));
    });

    it('names both groups when a home group moves', function () {
        ($this->expectEntry)(PersonalDataFields::HOME_GROUP, 'Changed from Thursday Big Book to Sunday Steps');

        ($this->change)(
            ['getHomeGroup' => 7],
            ['getHomeGroup' => 8],
            titledGroups([7 => 'Thursday Big Book', 8 => 'Sunday Steps']),
        );
    });

    it('falls back to the id when a group no longer resolves', function () {
        // A group deleted since the assignment still has to be traceable — an
        // entry reading "Removed from " and nothing else would not be.
        ($this->expectEntry)(PersonalDataFields::HOME_GROUP, 'Removed from #7');

        ($this->change)(['getHomeGroup' => 7], ['getHomeGroup' => 0], titledGroups([]));
    });

    it('does not log a home group that did not change', function () {
        $this->logger->shouldNotReceive('log');

        ($this->change)(['getHomeGroup' => 7], ['getHomeGroup' => 7]);
    });

    it('truncates a name too long for the detail column', function () {
        // The detail column is VARCHAR(255) and a move holds two names at
        // once, so each is capped well inside it.
        ($this->expectEntry)(PersonalDataFields::HOME_GROUP, 'Assigned to ' . str_repeat('A', 99) . '…');

        ($this->change)(['getHomeGroup' => 0], ['getHomeGroup' => 7], titledGroups([7 => str_repeat('A', 150)]));
    });
});

// ─── Intergroup position ───────────────────────────────────────────
describe('intergroup position', function () {
    it('names the position when one is assigned', function () {
        ($this->expectEntry)(PersonalDataFields::INTERGROUP_POSITION, 'Assigned to Telephone Liaison Officer');

        ($this->change)(
            ['getIntergroupPosition' => 0],
            ['getIntergroupPosition' => 3],
            null,
            namedPositions([3 => 'Telephone Liaison Officer']),
        );
    });

    it('names the position vacated when one is removed', function () {
        ($this->expectEntry)(PersonalDataFields::INTERGROUP_POSITION, 'Removed from Telephone Liaison Officer');

        ($this->change)(
            ['getIntergroupPosition' => 3],
            ['getIntergroupPosition' => 0],
            null,
            namedPositions([3 => 'Telephone Liaison Officer']),
        );
    });

    it('logs a home group and a position that change together', function () {
        ($this->expectEntry)(PersonalDataFields::HOME_GROUP, 'Assigned to Thursday Big Book');
        ($this->expectEntry)(PersonalDataFields::INTERGROUP_POSITION, 'Assigned to Telephone Liaison Officer');

        ($this->change)(
            ['getHomeGroup' => 0, 'getIntergroupPosition' => 0],
            ['getHomeGroup' => 7, 'getIntergroupPosition' => 3],
            titledGroups([7 => 'Thursday Big Book']),
            namedPositions([3 => 'Telephone Liaison Officer']),
        );
    });
});

// ─── Service roles at creation and deletion ────────────────────────
describe('service roles at creation and deletion', function () {
    it('records the service roles a member is created holding', function () {
        ($this->expectEntry)(PersonalDataFields::ALL_FIELDS_SENTINEL, 'Member created', AuditLogger::ACTION_CREATE);
        ($this->expectEntry)(PersonalDataFields::HOME_GROUP, 'Assigned to Thursday Big Book', AuditLogger::ACTION_CREATE);
        ($this->expectEntry)(PersonalDataFields::INTERGROUP_POSITION, 'Assigned to Telephone Liaison Officer', AuditLogger::ACTION_CREATE);

        changeTracker(
            $this->logger,
            titledGroups([7 => 'Thursday Big Book']),
            namedPositions([3 => 'Telephone Liaison Officer'])
        )->onMemberCreated(trackedMember(['getHomeGroup' => 7, 'getIntergroupPosition' => 3]));
    });

    it('records a member created as a GSR', function () {
        ($this->expectEntry)(PersonalDataFields::ALL_FIELDS_SENTINEL, 'Member created', AuditLogger::ACTION_CREATE);
        ($this->expectEntry)(PersonalDataFields::HOME_GROUP, 'Assigned to Thursday Big Book', AuditLogger::ACTION_CREATE);
        ($this->expectEntry)(PersonalDataFields::GSR, 'Assigned to Thursday Big Book', AuditLogger::ACTION_CREATE);

        changeTracker($this->logger, titledGroups([7 => 'Thursday Big Book']))
            ->onMemberCreated(trackedMember(['getHomeGroup' => 7, 'isGSR' => true]));
    });

    it('records the service roles a deleted member still held', function () {
        // Phrased exactly as an ordinary removal: the entry's own action
        // column is what marks it as a deletion.
        $this->logger->shouldReceive('logBatch')->once();
        ($this->expectEntry)(PersonalDataFields::HOME_GROUP, 'Removed from Thursday Big Book', AuditLogger::ACTION_DELETE);
        ($this->expectEntry)(PersonalDataFields::INTERGROUP_POSITION, 'Removed from Telephone Liaison Officer', AuditLogger::ACTION_DELETE);

        changeTracker(
            $this->logger,
            titledGroups([7 => 'Thursday Big Book']),
            namedPositions([3 => 'Telephone Liaison Officer'])
        )->onMemberDeleted(42, trackedMember(['getHomeGroup' => 7, 'getIntergroupPosition' => 3]));
    });

    it('records the GSR role a deleted member still held', function () {
        $this->logger->shouldReceive('logBatch')->once();
        ($this->expectEntry)(PersonalDataFields::HOME_GROUP, 'Removed from Thursday Big Book', AuditLogger::ACTION_DELETE);
        ($this->expectEntry)(PersonalDataFields::GSR, 'Removed from Thursday Big Book', AuditLogger::ACTION_DELETE);

        changeTracker($this->logger, titledGroups([7 => 'Thursday Big Book']))
            ->onMemberDeleted(42, trackedMember(['getHomeGroup' => 7, 'isGSR' => true]));
    });
});

// ─── Service availability ──────────────────────────────────────────
describe('service availability', function () {
    it('logs the new rotation date when it changes', function () {
        ($this->expectEntry)(PersonalDataFields::POSITION_ROTATION, 'Changed to 2027-01-01');

        ($this->change)(['getIntergroupPositionRotation' => '2026-01-01'], ['getIntergroupPositionRotation' => '2027-01-01']);
    });

    it('says cleared when a rotation date is emptied', function () {
        // 'Changed to ' with nothing after it would read as a truncated cell.
        ($this->expectEntry)(PersonalDataFields::POSITION_ROTATION, 'Cleared');

        ($this->change)(['getIntergroupPositionRotation' => '2026-01-01'], ['getIntergroupPositionRotation' => '']);
    });

    it('logs the twelfth-step flag in both directions', function (bool $before, bool $after, string $detail) {
        ($this->expectEntry)(PersonalDataFields::TWELFTH_STEPPER, $detail);

        ($this->change)(['isTwelfthStepper' => $before], ['isTwelfthStepper' => $after]);
    })->with([
        'switched on'  => [false, true, 'Available for 12th-step calls'],
        'switched off' => [true, false, 'No longer available for 12th-step calls'],
    ]);

    it('logs the telephone responder flag', function () {
        ($this->expectEntry)(PersonalDataFields::TELEPHONE_RESPONDER, 'Available as a telephone responder');

        ($this->change)(['isTelephoneResponder' => false], ['isTelephoneResponder' => true]);
    });
});

// ─── Visibility toggles ────────────────────────────────────────────
describe('visibility toggles', function () {
    it('names the new setting when name visibility changes', function () {
        // A privacy toggle's value is a yes or a no and identifies nobody, so
        // the entry records which way it went.
        ($this->expectEntry)(PersonalDataFields::SHOW_ANONYMOUS_NAME, 'Name hidden');

        ($this->change)(['showAnonymousName' => true], ['showAnonymousName' => false]);
    });

    it('names the new setting when profile visibility changes', function () {
        ($this->expectEntry)(PersonalDataFields::SHOW_MEMBER_PROFILE, 'Profile shown publicly');

        ($this->change)(['showMemberProfile' => false], ['showMemberProfile' => true]);
    });
});

// ─── Fields recorded without their values ──────────────────────────
describe('fields recorded without their values', function () {
    it('records an area change without the area', function () {
        // Coarse, but still where a named individual is.
        ($this->expectEntry)(PersonalDataFields::AREA, 'Value changed');

        ($this->change)(['getArea' => 'Bristol North'], ['getArea' => 'Bristol South']);
    });

    it('records an accepts change without the selection', function () {
        ($this->expectEntry)(PersonalDataFields::ACCEPTS, 'Value changed');

        ($this->change)(['getAccepts' => ['men']], ['getAccepts' => ['men', 'women']]);
    });

    it('ignores a reordered accepts selection', function () {
        // An unordered checkbox set: same selection, different order, so
        // nothing changed.
        $this->logger->shouldNotReceive('log');

        ($this->change)(['getAccepts' => ['men', 'women']], ['getAccepts' => ['women', 'men']]);
    });

    it('records a profile change without the prose', function () {
        ($this->expectEntry)(PersonalDataFields::ANONYMOUS_PROFILE, 'Value changed');

        ($this->change)(['getAnonymousProfile' => ''], ['getAnonymousProfile' => 'Sober since 1912.']);
    });

    it('records a meeting PO change without the value', function () {
        ($this->expectEntry)(PersonalDataFields::MEETING_PO, 'Value changed');

        ($this->change)(['getMeetingPO' => null], ['getMeetingPO' => 77]);
    });

    it('still does not log the updated timestamp', function () {
        // getUpdated() moves on every save. Auditing it would put a second,
        // empty row beside every real one, so it is deliberately never read.
        $this->logger->shouldNotReceive('log');

        ($this->change)(['getUpdated' => '2026-08-26 20:00:00'], ['getUpdated' => '2026-08-26 21:00:00']);
    });
});

// ─── GSR ───────────────────────────────────────────────────────────
describe('GSR', function () {
    it('names the group when a member becomes its GSR', function () {
        // "GSR" alone would not say what the member is GSR for, so the entry
        // names the group the role is held on behalf of.
        ($this->expectEntry)(PersonalDataFields::GSR, 'Assigned to Thursday Big Book');

        ($this->change)(
            ['getHomeGroup' => 7, 'isGSR' => false],
            ['getHomeGroup' => 7, 'isGSR' => true],
            titledGroups([7 => 'Thursday Big Book']),
        );
    });

    it('names the group when a member stops being its GSR', function () {
        ($this->expectEntry)(PersonalDataFields::GSR, 'Removed from Thursday Big Book');

        ($this->change)(
            ['getHomeGroup' => 7, 'isGSR' => true],
            ['getHomeGroup' => 7, 'isGSR' => false],
            titledGroups([7 => 'Thursday Big Book']),
        );
    });

    it('logs a GSR who carries the role to a new home group', function () {
        // The flag does not change here, so comparing isGSR() alone would log
        // nothing and leave the member looking like the old group's GSR still.
        ($this->expectEntry)(PersonalDataFields::HOME_GROUP, 'Changed from Thursday Big Book to Sunday Steps');
        ($this->expectEntry)(PersonalDataFields::GSR, 'Changed from Thursday Big Book to Sunday Steps');

        ($this->change)(
            ['getHomeGroup' => 7, 'isGSR' => true],
            ['getHomeGroup' => 8, 'isGSR' => true],
            titledGroups([7 => 'Thursday Big Book', 8 => 'Sunday Steps']),
        );
    });

    it('does not log a GSR flag that did not change', function () {
        $this->logger->shouldNotReceive('log');

        ($this->change)(
            ['getHomeGroup' => 7, 'isGSR' => true],
            ['getHomeGroup' => 7, 'isGSR' => true],
            titledGroups([7 => 'Thursday Big Book']),
        );
    });

    it('records a GSR flag set without a home group behind it', function () {
        // Meaningless data — the role is held on behalf of a group — but it
        // is still a change, and dropping it silently is the gap this tracking
        // exists to close.
        ($this->expectEntry)(PersonalDataFields::GSR, 'Assigned to (no home group)');

        ($this->change)(
            ['getHomeGroup' => 0, 'isGSR' => false],
            ['getHomeGroup' => 0, 'isGSR' => true],
            titledGroups([]),
        );
    });
});
