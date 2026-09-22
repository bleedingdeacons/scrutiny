<?php

declare(strict_types=1);

namespace Scrutiny\Tests\Unit\Audit;

use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Scrutiny\Audit\AuditTracker;
use Scrutiny\Audit\Interfaces\AuditLogger;
use Scrutiny\Privacy\PersonalDataFields;
use Unity\Contacts\Interfaces\Contact;
use Unity\Groups\Interfaces\Group;
use Unity\Meetings\Interfaces\Meeting;

/*
 * Tests for AuditTracker group and meeting contact change detection.
 */

// Verification here is entirely Mockery expectations, and this file runs on
// plain PHPUnit rather than wp-mocks' TestCase. Without the trait the
// expectations are never checked or counted: PHPUnit sees no assertions and
// every test passes whatever the tracker logs.
uses(MockeryPHPUnitIntegration::class);

/**
 * Create an AuditTracker without WP hooks by using reflection.
 */
function trackerLoggingTo(AuditLogger $logger): AuditTracker
{
    $reflection = new \ReflectionClass(AuditTracker::class);
    $instance = $reflection->newInstanceWithoutConstructor();

    $reflection->getProperty('logger')->setValue($instance, $logger);

    return $instance;
}

function contactNamed(string $name = '', string $email = '', string $phone = ''): Contact
{
    $contact = Mockery::mock(Contact::class);
    $contact->shouldReceive('getName')->andReturn($name);
    $contact->shouldReceive('getEmail')->andReturn($email);
    $contact->shouldReceive('getPhone')->andReturn($phone);
    return $contact;
}

/**
 * @param Contact[] $contacts
 */
function meetingOf(int $id, array $contacts = []): Meeting
{
    $meeting = Mockery::mock(Meeting::class);
    $meeting->shouldReceive('getId')->andReturn($id);
    $meeting->shouldReceive('getContacts')->andReturn($contacts);
    return $meeting;
}

/**
 * @param Contact[] $contacts
 * @param Meeting[] $meetings
 */
function groupOf(int $id, array $contacts = [], array $meetings = []): Group
{
    $group = Mockery::mock(Group::class);
    $group->shouldReceive('getId')->andReturn($id);
    $group->shouldReceive('getContacts')->andReturn($contacts);
    $group->shouldReceive('getMeetings')->andReturn($meetings);
    return $group;
}

beforeEach(function () {
    $this->logger = Mockery::mock(AuditLogger::class);

    // Expect exactly one update log for this entity, field and detail.
    $this->expectLog = fn (string $entity, int $id, string $field, string $detail) => $this->logger
        ->shouldReceive('log')
        ->once()
        ->with(AuditLogger::ACTION_UPDATE, $entity, $id, $field, $detail);
});

// ---------------------------------------------------------------
// Group contact changes
// ---------------------------------------------------------------
describe('group contacts', function () {
    it('logs a changed email', function () {
        ($this->expectLog)(AuditLogger::ENTITY_GROUP, 10, PersonalDataFields::GROUP_CONTACT_EMAIL, 'Contact email changed');

        trackerLoggingTo($this->logger)->onGroupChanged(
            groupOf(10, [contactNamed('Alice', 'alice@new.com', '111')]),
            groupOf(10, [contactNamed('Alice', 'alice@old.com', '111')]),
        );
    });

    it('logs a changed name', function () {
        ($this->expectLog)(AuditLogger::ENTITY_GROUP, 10, PersonalDataFields::GROUP_CONTACT_NAME, 'Contact name changed');

        trackerLoggingTo($this->logger)->onGroupChanged(
            groupOf(10, [contactNamed('Bob', 'alice@example.com', '111')]),
            groupOf(10, [contactNamed('Alice', 'alice@example.com', '111')]),
        );
    });

    it('logs a changed phone', function () {
        ($this->expectLog)(AuditLogger::ENTITY_GROUP, 10, PersonalDataFields::GROUP_CONTACT_PHONE, 'Contact phone changed');

        trackerLoggingTo($this->logger)->onGroupChanged(
            groupOf(10, [contactNamed('Alice', 'alice@example.com', '222')]),
            groupOf(10, [contactNamed('Alice', 'alice@example.com', '111')]),
        );
    });

    it('logs every field when all of them change', function () {
        $this->logger->shouldReceive('log')->times(3);

        trackerLoggingTo($this->logger)->onGroupChanged(
            groupOf(10, [contactNamed('Bob', 'bob@new.com', '222')]),
            groupOf(10, [contactNamed('Alice', 'alice@old.com', '111')]),
        );
    });

    it('logs nothing when the contacts are unchanged', function () {
        $this->logger->shouldNotReceive('log');

        trackerLoggingTo($this->logger)->onGroupChanged(
            groupOf(10, [contactNamed('Alice', 'alice@example.com', '111')]),
            groupOf(10, [contactNamed('Alice', 'alice@example.com', '111')]),
        );
    });

    it('logs an added contact', function () {
        // Adding a contact changes name, email, and phone lists.
        $this->logger->shouldReceive('log')->times(3);

        trackerLoggingTo($this->logger)->onGroupChanged(
            groupOf(10, [contactNamed('Alice', 'alice@example.com', '111')]),
            groupOf(10, []),
        );
    });

    it('logs a removed contact', function () {
        $this->logger->shouldReceive('log')->times(3);

        trackerLoggingTo($this->logger)->onGroupChanged(
            groupOf(10, []),
            groupOf(10, [contactNamed('Alice', 'alice@example.com', '111')]),
        );
    });

    it('logs nothing when the contacts are only reordered', function () {
        $this->logger->shouldNotReceive('log');

        trackerLoggingTo($this->logger)->onGroupChanged(
            groupOf(10, [
                contactNamed('Bob', 'bob@example.com', '222'),
                contactNamed('Alice', 'alice@example.com', '111'),
            ]),
            groupOf(10, [
                contactNamed('Alice', 'alice@example.com', '111'),
                contactNamed('Bob', 'bob@example.com', '222'),
            ]),
        );
    });
});

// ---------------------------------------------------------------
// Meeting contact changes
// ---------------------------------------------------------------
describe('meeting contacts', function () {
    it('logs a changed email', function () {
        ($this->expectLog)(AuditLogger::ENTITY_MEETING, 99, PersonalDataFields::MEETING_CONTACT_EMAIL, 'Contact email changed');

        trackerLoggingTo($this->logger)->onGroupChanged(
            groupOf(10, [], [meetingOf(99, [contactNamed('Alice', 'alice@new.com', '111')])]),
            groupOf(10, [], [meetingOf(99, [contactNamed('Alice', 'alice@old.com', '111')])]),
        );
    });

    it('logs a changed name', function () {
        ($this->expectLog)(AuditLogger::ENTITY_MEETING, 99, PersonalDataFields::MEETING_CONTACT_NAME, 'Contact name changed');

        trackerLoggingTo($this->logger)->onGroupChanged(
            groupOf(10, [], [meetingOf(99, [contactNamed('Bob', 'alice@example.com', '111')])]),
            groupOf(10, [], [meetingOf(99, [contactNamed('Alice', 'alice@example.com', '111')])]),
        );
    });

    it('logs a changed phone', function () {
        ($this->expectLog)(AuditLogger::ENTITY_MEETING, 99, PersonalDataFields::MEETING_CONTACT_PHONE, 'Contact phone changed');

        trackerLoggingTo($this->logger)->onGroupChanged(
            groupOf(10, [], [meetingOf(99, [contactNamed('Alice', 'alice@example.com', '222')])]),
            groupOf(10, [], [meetingOf(99, [contactNamed('Alice', 'alice@example.com', '111')])]),
        );
    });

    it('logs nothing when the contacts are unchanged', function () {
        $this->logger->shouldNotReceive('log');

        trackerLoggingTo($this->logger)->onGroupChanged(
            groupOf(10, [], [meetingOf(99, [contactNamed('Alice', 'alice@example.com', '111')])]),
            groupOf(10, [], [meetingOf(99, [contactNamed('Alice', 'alice@example.com', '111')])]),
        );
    });

    it('logs the contacts of a newly added meeting', function () {
        // New meeting has contacts → name, email, phone all logged.
        $this->logger->shouldReceive('log')
            ->times(3)
            ->with(
                AuditLogger::ACTION_UPDATE,
                AuditLogger::ENTITY_MEETING,
                99,
                Mockery::type('string'),
                Mockery::type('string')
            );

        trackerLoggingTo($this->logger)->onGroupChanged(
            groupOf(10, [], [meetingOf(99, [contactNamed('Alice', 'alice@example.com', '111')])]),
            groupOf(10, [], []),
        );
    });

    it('handles several meetings independently', function () {
        // Meeting 99 has a contact email change.
        ($this->expectLog)(AuditLogger::ENTITY_MEETING, 99, PersonalDataFields::MEETING_CONTACT_EMAIL, 'Contact email changed');

        // Meeting 100 should NOT trigger any logs (unchanged).

        trackerLoggingTo($this->logger)->onGroupChanged(
            groupOf(10, [], [
                meetingOf(99, [contactNamed('Alice', 'alice@new.com', '111')]),
                meetingOf(100, [contactNamed('Bob', 'bob@example.com', '222')]),
            ]),
            groupOf(10, [], [
                meetingOf(99, [contactNamed('Alice', 'alice@old.com', '111')]),
                meetingOf(100, [contactNamed('Bob', 'bob@example.com', '222')]),
            ]),
        );
    });
});

// ---------------------------------------------------------------
// Combined group + meeting contact changes
// ---------------------------------------------------------------
it('logs both group and meeting contact changes', function () {
    // Group contact email changed (1 log).
    ($this->expectLog)(AuditLogger::ENTITY_GROUP, 10, PersonalDataFields::GROUP_CONTACT_EMAIL, 'Contact email changed');

    // Meeting contact phone changed (1 log).
    ($this->expectLog)(AuditLogger::ENTITY_MEETING, 99, PersonalDataFields::MEETING_CONTACT_PHONE, 'Contact phone changed');

    trackerLoggingTo($this->logger)->onGroupChanged(
        groupOf(10, [contactNamed('Bob', 'bob@new.com', '333')], [
            meetingOf(99, [contactNamed('Alice', 'alice@example.com', '222')]),
        ]),
        groupOf(10, [contactNamed('Bob', 'bob@old.com', '333')], [
            meetingOf(99, [contactNamed('Alice', 'alice@example.com', '111')]),
        ]),
    );
});
