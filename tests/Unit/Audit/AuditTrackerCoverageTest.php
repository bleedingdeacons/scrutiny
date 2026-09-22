<?php

declare(strict_types=1);

namespace Scrutiny\Tests\Unit\Audit;

use BleedingDeacons\WpMocks\WpState;
use Mockery;
use Scrutiny\Audit\AuditTracker;
use Scrutiny\Audit\Interfaces\AuditLogger;
use Scrutiny\Privacy\PersonalDataFields;
use Scrutiny\Privacy\PersonalDataPolicy;
use Unity\Contacts\Interfaces\Contact;
use Unity\Groups\Interfaces\Group;
use Unity\Groups\Interfaces\GroupRepository;
use Unity\Meetings\Interfaces\Meeting;
use Unity\Members\Interfaces\Member;
use Unity\Members\PreferredContact;
use Unity\Members\ResponderCertification;
use Unity\Positions\Interfaces\PositionRepository;

/*
 * Broad coverage for AuditTracker's view-tracking, group/contact change,
 * deletion, hide and import/export logging paths.
 */

covers(AuditTracker::class);

/**
 * Build a tracker with dependencies injected by reflection so no WP hooks are
 * registered (the constructor's add_action/add_filter calls are not under test
 * here).
 *
 * @param array<string, mixed>  $config
 * @param array<string, string> $acfMap
 */
function coverageTracker(AuditLogger $logger, array $config = [], array $acfMap = []): AuditTracker
{
    $ref = new \ReflectionClass(AuditTracker::class);
    $tracker = $ref->newInstanceWithoutConstructor();

    $set = static fn (string $name, mixed $value) => $ref->getProperty($name)->setValue($tracker, $value);

    $set('logger', $logger);
    $set('policy', new PersonalDataPolicy());
    $set('member_config', $config);
    $set('acfFieldMap', $acfMap);
    // Never consulted here: no case in this file moves a member between groups
    // or positions. AuditTrackerTest covers that path.
    $set('groupRepository', Mockery::mock(GroupRepository::class));
    $set('positionRepository', Mockery::mock(PositionRepository::class));

    return $tracker;
}

function grantViewCapability(): void
{
    $GLOBALS['scrutiny_test_capabilities'][PersonalDataPolicy::VIEW_CAPABILITY] = true;
}

/**
 * @param array<int, array{name?: string, email?: string, phone?: string}> $rows
 * @return Contact[]
 */
function contactsOf(array $rows): array
{
    return array_map(function (array $row): Contact {
        $c = Mockery::mock(Contact::class);
        $c->shouldReceive('getName')->andReturn($row['name'] ?? '');
        $c->shouldReceive('getEmail')->andReturn($row['email'] ?? '');
        $c->shouldReceive('getPhone')->andReturn($row['phone'] ?? '');
        return $c;
    }, $rows);
}

/**
 * A Group mock with id 5 carrying the given contacts and meetings.
 *
 * @param Contact[] $contacts
 * @param Meeting[] $meetings
 */
function groupWith(array $contacts, array $meetings = []): Group
{
    $group = Mockery::mock(Group::class);
    $group->shouldReceive('getId')->andReturn(5);
    $group->shouldReceive('getContacts')->andReturn($contacts);
    $group->shouldReceive('getMeetings')->andReturn($meetings);

    return $group;
}

/**
 * A Member mock answering every accessor the change tracker reads.
 *
 * @param array<string, mixed> $overrides
 */
function memberWith(array $overrides = []): Member
{
    $data = array_merge([
        'getId' => 42,
        'getPersonalEmail' => 'same@example.com',
        'getMobileNumber' => '07700 900000',
        'getLandlineNumber' => '0117 496 0000',
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
    ], $overrides);

    $member = Mockery::mock(Member::class);
    foreach ($data as $method => $value) {
        $member->shouldReceive($method)->andReturn($value);
    }
    return $member;
}

beforeEach(function () {
    $GLOBALS['scrutiny_test_capabilities'] = [];
    $_GET = [];

    $this->logger = Mockery::mock(AuditLogger::class);
});

afterEach(function () {
    $_GET = [];
});

// ─── import / export ────────────────────────────────────────────
it('logs one entry for each import and export hook', function () {
    foreach (
        [
            [AuditLogger::ACTION_IMPORT, AuditLogger::ENTITY_MEMBER, 'personal-email'],
            [AuditLogger::ACTION_EXPORT, AuditLogger::ENTITY_MEMBER, 'personal-email'],
            [AuditLogger::ACTION_IMPORT, AuditLogger::ENTITY_GROUP, 'group'],
            [AuditLogger::ACTION_EXPORT, AuditLogger::ENTITY_GROUP, 'group'],
            [AuditLogger::ACTION_IMPORT, AuditLogger::ENTITY_POSITION, 'position'],
            [AuditLogger::ACTION_EXPORT, AuditLogger::ENTITY_POSITION, 'position'],
        ] as [$action, $entity, $field]
    ) {
        $this->logger->shouldReceive('log')->once()->with($action, $entity, 0, $field, Mockery::type('string'));
    }

    $tracker = coverageTracker($this->logger);

    $tracker->onMemberImport(3, 'personal-email');
    $tracker->onMemberExport(4, 'personal-email');
    $tracker->onGroupImport(5, 'group');
    $tracker->onGroupExport(6, 'group');
    $tracker->onPositionImport(7, 'position');
    $tracker->onPositionExport(8, 'position');
});

// ─── deletion / hide ────────────────────────────────────────────
describe('deletion and hiding', function () {
    it('batches every personal and GDPR field when a member is deleted', function () {
        $expectedFields = array_merge(PersonalDataFields::ALL_FIELDS, PersonalDataFields::GDPR_FIELDS);

        $this->logger->shouldReceive('logBatch')->once()
            ->with(AuditLogger::ACTION_DELETE, AuditLogger::ENTITY_MEMBER, 99, $expectedFields, 'Member deleted');

        coverageTracker($this->logger)->onMemberDeleted(99, null);
    });

    it('batches the group contact fields when a group is deleted or hidden', function () {
        $this->logger->shouldReceive('logBatch')->once()
            ->with(AuditLogger::ACTION_DELETE, AuditLogger::ENTITY_GROUP, 7, PersonalDataFields::GROUP_CONTACT_FIELDS, 'Group deleted');
        $this->logger->shouldReceive('logBatch')->once()
            ->with(AuditLogger::ACTION_UPDATE, AuditLogger::ENTITY_GROUP, 7, PersonalDataFields::GROUP_CONTACT_FIELDS, Mockery::type('string'));

        $tracker = coverageTracker($this->logger);
        $tracker->onGroupDeleted(7, null);
        $tracker->onGroupHidden(7, null);
    });
});

// ─── group / contact change ─────────────────────────────────────
describe('group changes', function () {
    it('logs each differing contact field', function () {
        // Name and email differ; phone is unchanged.
        $this->logger->shouldReceive('log')->once()
            ->with(AuditLogger::ACTION_UPDATE, AuditLogger::ENTITY_GROUP, 5, PersonalDataFields::GROUP_CONTACT_NAME, Mockery::type('string'));
        $this->logger->shouldReceive('log')->once()
            ->with(AuditLogger::ACTION_UPDATE, AuditLogger::ENTITY_GROUP, 5, PersonalDataFields::GROUP_CONTACT_EMAIL, Mockery::type('string'));

        $original = groupWith(contactsOf([['name' => 'Alice', 'email' => 'alice@example.com', 'phone' => '111']]));
        $updated  = groupWith(contactsOf([['name' => 'Alicia', 'email' => 'alicia@example.com', 'phone' => '111']]));

        coverageTracker($this->logger)->onGroupChanged($updated, $original);
    });

    it('logs meeting contact changes too', function () {
        // Group contacts unchanged; a meeting's contact phone changed.
        $this->logger->shouldReceive('log')->once()
            ->with(AuditLogger::ACTION_UPDATE, AuditLogger::ENTITY_MEETING, 88, PersonalDataFields::MEETING_CONTACT_PHONE, Mockery::type('string'));

        $groupContacts = contactsOf([['name' => 'Al', 'email' => 'al@example.com', 'phone' => '111']]);

        $originalMeeting = Mockery::mock(Meeting::class);
        $originalMeeting->shouldReceive('getId')->andReturn(88);
        $originalMeeting->shouldReceive('getContacts')->andReturn(contactsOf([['phone' => '111']]));

        $updatedMeeting = Mockery::mock(Meeting::class);
        $updatedMeeting->shouldReceive('getId')->andReturn(88);
        $updatedMeeting->shouldReceive('getContacts')->andReturn(contactsOf([['phone' => '222']]));

        coverageTracker($this->logger)->onGroupChanged(
            groupWith($groupContacts, [$updatedMeeting]),
            groupWith($groupContacts, [$originalMeeting]),
        );
    });
});

// ─── member change ──────────────────────────────────────────────
describe('member changes', function () {
    it('logs a consent-recorded transition', function () {
        $this->logger->shouldReceive('log')->once()
            ->with(AuditLogger::ACTION_UPDATE, AuditLogger::ENTITY_MEMBER, 42, PersonalDataFields::GDPR_ACCEPTED, 'Consent recorded');

        coverageTracker($this->logger)->onMemberChanged(
            memberWith(['isGdprAccepted' => true]),
            memberWith(['isGdprAccepted' => false]),
        );
    });

    it('logs email and mobile updates', function () {
        $this->logger->shouldReceive('log')->once()
            ->with(AuditLogger::ACTION_UPDATE, AuditLogger::ENTITY_MEMBER, 42, PersonalDataFields::PERSONAL_EMAIL, Mockery::type('string'));
        $this->logger->shouldReceive('log')->once()
            ->with(AuditLogger::ACTION_UPDATE, AuditLogger::ENTITY_MEMBER, 42, PersonalDataFields::MOBILE_NUMBER, Mockery::type('string'));

        coverageTracker($this->logger)->onMemberChanged(
            memberWith(['getPersonalEmail' => 'new@example.com', 'getMobileNumber' => '222']),
            memberWith(['getPersonalEmail' => 'old@example.com', 'getMobileNumber' => '111']),
        );
    });

    it('logs nothing when no personal data differs', function () {
        $this->logger->shouldNotReceive('log');

        coverageTracker($this->logger)->onMemberChanged(memberWith(), memberWith());
    });
});

// ─── admin form view tracking ───────────────────────────────────
describe('admin form view tracking', function () {
    it('logs a view for a viewer editing a member', function () {
        grantViewCapability();

        $this->logger->shouldReceive('logBatch')->once()
            ->with(AuditLogger::ACTION_VIEW, AuditLogger::ENTITY_MEMBER, 23, PersonalDataFields::ALL_FIELDS, Mockery::type('string'));

        $_GET['post'] = '23';
        $screen = (object) ['base' => 'post', 'post_type' => 'unity_member'];

        $tracker = coverageTracker($this->logger, ['POST_TYPE' => 'unity_member']);
        $tracker->onMemberAdminFormDisplayed($screen);
        // A second call in the same request is de-duped.
        $tracker->onMemberAdminFormDisplayed($screen);
    });

    it('skips a non-viewer', function () {
        $this->logger->shouldNotReceive('logBatch');

        $_GET['post'] = '23';

        coverageTracker($this->logger, ['POST_TYPE' => 'unity_member'])
            ->onMemberAdminFormDisplayed((object) ['base' => 'post', 'post_type' => 'unity_member']);
    });

    it('ignores the new-post screen and other screens', function () {
        grantViewCapability();

        $this->logger->shouldNotReceive('logBatch');

        $tracker = coverageTracker($this->logger, ['POST_TYPE' => 'unity_member']);

        // Wrong screen base.
        $tracker->onMemberAdminFormDisplayed((object) ['base' => 'edit', 'post_type' => 'unity_member']);
        // Wrong post type.
        $tracker->onMemberAdminFormDisplayed((object) ['base' => 'post', 'post_type' => 'post']);
        // New-post screen (no ?post).
        $_GET = [];
        $tracker->onMemberAdminFormDisplayed((object) ['base' => 'post', 'post_type' => 'unity_member']);
    });
});

// ─── frontend ACF view tracking ─────────────────────────────────
describe('frontend field view tracking', function () {
    beforeEach(function () {
        $this->fieldTracker = coverageTracker(
            $this->logger,
            ['POST_TYPE' => 'unity_member'],
            ['field_email_key' => 'personal-email']
        );
    });

    it('logs a personal data view', function () {
        grantViewCapability();

        WpState::addPost(50, ['post_type' => 'unity_member']);
        WpState::$isAdmin = false;

        $this->logger->shouldReceive('log')->once()
            ->with(AuditLogger::ACTION_VIEW, AuditLogger::ENTITY_MEMBER, 50, 'personal-email', Mockery::type('string'));

        $field = ['key' => 'field_email_key'];

        // First load logs; second is de-duped.
        expect($this->fieldTracker->onPersonalDataFieldLoaded('val', 50, $field))->toBe('val')
            ->and($this->fieldTracker->onPersonalDataFieldLoaded('val', 50, $field))->toBe('val');
    });

    it('skips non-member posts, the admin context and unmapped fields', function () {
        grantViewCapability();

        $this->logger->shouldNotReceive('log');

        // Non-integer post id: returned untouched before any WP calls.
        expect($this->fieldTracker->onPersonalDataFieldLoaded('v', 'user_1', ['key' => 'field_email_key']))->toBe('v');

        // Wrong post type.
        WpState::addPost(50, ['post_type' => 'post']);
        expect($this->fieldTracker->onPersonalDataFieldLoaded('v', 50, ['key' => 'field_email_key']))->toBe('v');

        // Member, but in admin context.
        WpState::addPost(51, ['post_type' => 'unity_member']);
        WpState::$isAdmin = true;
        expect($this->fieldTracker->onPersonalDataFieldLoaded('v', 51, ['key' => 'field_email_key']))->toBe('v');
    });

    it('skips a user who cannot view, and unmapped keys', function () {
        // View capability withheld this time.
        $this->logger->shouldNotReceive('log');

        WpState::addPost(50, ['post_type' => 'unity_member']);
        WpState::$isAdmin = false;

        // Non-viewer: nothing logged.
        expect($this->fieldTracker->onPersonalDataFieldLoaded('v', 50, ['key' => 'field_email_key']))->toBe('v');

        // Now grant view but hand it an unmapped field key.
        grantViewCapability();
        expect($this->fieldTracker->onPersonalDataFieldLoaded('v', 50, ['key' => 'field_unknown']))->toBe('v');
    });
});
