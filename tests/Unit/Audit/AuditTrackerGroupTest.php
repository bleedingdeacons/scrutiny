<?php

declare(strict_types=1);

namespace Scrutiny\Tests\Unit\Audit;

use PHPUnit\Framework\TestCase;
use Scrutiny\Audit\AuditTracker;
use Scrutiny\Audit\Interfaces\AuditLogger;
use Scrutiny\Privacy\PersonalDataFields;
use Unity\Contacts\Interfaces\Contact;
use Unity\Groups\Interfaces\Group;
use Unity\Meetings\Interfaces\Meeting;
use Mockery;

/**
 * Tests for AuditTracker group and meeting contact change detection
 */
class AuditTrackerGroupTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Create an AuditTracker without WP hooks by using reflection
     */
    private function createTracker(AuditLogger $logger): AuditTracker
    {
        $reflection = new \ReflectionClass(AuditTracker::class);
        $instance = $reflection->newInstanceWithoutConstructor();

        $prop = $reflection->getProperty('logger');
        $prop->setAccessible(true);
        $prop->setValue($instance, $logger);

        return $instance;
    }

    /**
     * Create a mock Contact
     */
    private function createContact(string $name = '', string $email = '', string $phone = ''): Contact
    {
        $contact = Mockery::mock(Contact::class);
        $contact->shouldReceive('getName')->andReturn($name);
        $contact->shouldReceive('getEmail')->andReturn($email);
        $contact->shouldReceive('getPhone')->andReturn($phone);
        return $contact;
    }

    /**
     * Create a mock Meeting with contacts
     */
    private function createMeeting(int $id, array $contacts = []): Meeting
    {
        $meeting = Mockery::mock(Meeting::class);
        $meeting->shouldReceive('getId')->andReturn($id);
        $meeting->shouldReceive('getContacts')->andReturn($contacts);
        return $meeting;
    }

    /**
     * Create a mock Group with contacts and meetings
     */
    private function createGroup(int $id, array $contacts = [], array $meetings = []): Group
    {
        $group = Mockery::mock(Group::class);
        $group->shouldReceive('getId')->andReturn($id);
        $group->shouldReceive('getContacts')->andReturn($contacts);
        $group->shouldReceive('getMeetings')->andReturn($meetings);
        return $group;
    }

    // ---------------------------------------------------------------
    // Group contact changes
    // ---------------------------------------------------------------

    /** @test */
    public function it_logs_when_group_contact_email_changes(): void
    {
        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldReceive('log')
            ->once()
            ->with(
                AuditLogger::ACTION_UPDATE,
                AuditLogger::ENTITY_GROUP,
                10,
                PersonalDataFields::GROUP_CONTACT_EMAIL,
                'Contact email changed'
            );

        $tracker = $this->createTracker($logger);

        $original = $this->createGroup(10, [
            $this->createContact('Alice', 'alice@old.com', '111'),
        ]);
        $updated = $this->createGroup(10, [
            $this->createContact('Alice', 'alice@new.com', '111'),
        ]);

        $tracker->onGroupChanged($updated, $original);
    }

    /** @test */
    public function it_logs_when_group_contact_name_changes(): void
    {
        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldReceive('log')
            ->once()
            ->with(
                AuditLogger::ACTION_UPDATE,
                AuditLogger::ENTITY_GROUP,
                10,
                PersonalDataFields::GROUP_CONTACT_NAME,
                'Contact name changed'
            );

        $tracker = $this->createTracker($logger);

        $original = $this->createGroup(10, [
            $this->createContact('Alice', 'alice@example.com', '111'),
        ]);
        $updated = $this->createGroup(10, [
            $this->createContact('Bob', 'alice@example.com', '111'),
        ]);

        $tracker->onGroupChanged($updated, $original);
    }

    /** @test */
    public function it_logs_when_group_contact_phone_changes(): void
    {
        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldReceive('log')
            ->once()
            ->with(
                AuditLogger::ACTION_UPDATE,
                AuditLogger::ENTITY_GROUP,
                10,
                PersonalDataFields::GROUP_CONTACT_PHONE,
                'Contact phone changed'
            );

        $tracker = $this->createTracker($logger);

        $original = $this->createGroup(10, [
            $this->createContact('Alice', 'alice@example.com', '111'),
        ]);
        $updated = $this->createGroup(10, [
            $this->createContact('Alice', 'alice@example.com', '222'),
        ]);

        $tracker->onGroupChanged($updated, $original);
    }

    /** @test */
    public function it_logs_all_group_contact_fields_when_all_change(): void
    {
        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldReceive('log')->times(3);

        $tracker = $this->createTracker($logger);

        $original = $this->createGroup(10, [
            $this->createContact('Alice', 'alice@old.com', '111'),
        ]);
        $updated = $this->createGroup(10, [
            $this->createContact('Bob', 'bob@new.com', '222'),
        ]);

        $tracker->onGroupChanged($updated, $original);
    }

    /** @test */
    public function it_does_not_log_when_group_contacts_are_unchanged(): void
    {
        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldNotReceive('log');

        $tracker = $this->createTracker($logger);

        $original = $this->createGroup(10, [
            $this->createContact('Alice', 'alice@example.com', '111'),
        ]);
        $updated = $this->createGroup(10, [
            $this->createContact('Alice', 'alice@example.com', '111'),
        ]);

        $tracker->onGroupChanged($updated, $original);
    }

    /** @test */
    public function it_logs_when_a_group_contact_is_added(): void
    {
        $logger = Mockery::mock(AuditLogger::class);
        // Adding a contact changes name, email, and phone lists
        $logger->shouldReceive('log')->times(3);

        $tracker = $this->createTracker($logger);

        $original = $this->createGroup(10, []);
        $updated = $this->createGroup(10, [
            $this->createContact('Alice', 'alice@example.com', '111'),
        ]);

        $tracker->onGroupChanged($updated, $original);
    }

    /** @test */
    public function it_logs_when_a_group_contact_is_removed(): void
    {
        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldReceive('log')->times(3);

        $tracker = $this->createTracker($logger);

        $original = $this->createGroup(10, [
            $this->createContact('Alice', 'alice@example.com', '111'),
        ]);
        $updated = $this->createGroup(10, []);

        $tracker->onGroupChanged($updated, $original);
    }

    // ---------------------------------------------------------------
    // Meeting contact changes
    // ---------------------------------------------------------------

    /** @test */
    public function it_logs_when_meeting_contact_email_changes(): void
    {
        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldReceive('log')
            ->once()
            ->with(
                AuditLogger::ACTION_UPDATE,
                AuditLogger::ENTITY_MEETING,
                99,
                PersonalDataFields::MEETING_CONTACT_EMAIL,
                'Contact email changed'
            );

        $tracker = $this->createTracker($logger);

        $originalMeeting = $this->createMeeting(99, [
            $this->createContact('Alice', 'alice@old.com', '111'),
        ]);
        $updatedMeeting = $this->createMeeting(99, [
            $this->createContact('Alice', 'alice@new.com', '111'),
        ]);

        $original = $this->createGroup(10, [], [$originalMeeting]);
        $updated = $this->createGroup(10, [], [$updatedMeeting]);

        $tracker->onGroupChanged($updated, $original);
    }

    /** @test */
    public function it_logs_when_meeting_contact_name_changes(): void
    {
        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldReceive('log')
            ->once()
            ->with(
                AuditLogger::ACTION_UPDATE,
                AuditLogger::ENTITY_MEETING,
                99,
                PersonalDataFields::MEETING_CONTACT_NAME,
                'Contact name changed'
            );

        $tracker = $this->createTracker($logger);

        $originalMeeting = $this->createMeeting(99, [
            $this->createContact('Alice', 'alice@example.com', '111'),
        ]);
        $updatedMeeting = $this->createMeeting(99, [
            $this->createContact('Bob', 'alice@example.com', '111'),
        ]);

        $original = $this->createGroup(10, [], [$originalMeeting]);
        $updated = $this->createGroup(10, [], [$updatedMeeting]);

        $tracker->onGroupChanged($updated, $original);
    }

    /** @test */
    public function it_logs_when_meeting_contact_phone_changes(): void
    {
        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldReceive('log')
            ->once()
            ->with(
                AuditLogger::ACTION_UPDATE,
                AuditLogger::ENTITY_MEETING,
                99,
                PersonalDataFields::MEETING_CONTACT_PHONE,
                'Contact phone changed'
            );

        $tracker = $this->createTracker($logger);

        $originalMeeting = $this->createMeeting(99, [
            $this->createContact('Alice', 'alice@example.com', '111'),
        ]);
        $updatedMeeting = $this->createMeeting(99, [
            $this->createContact('Alice', 'alice@example.com', '222'),
        ]);

        $original = $this->createGroup(10, [], [$originalMeeting]);
        $updated = $this->createGroup(10, [], [$updatedMeeting]);

        $tracker->onGroupChanged($updated, $original);
    }

    /** @test */
    public function it_does_not_log_when_meeting_contacts_are_unchanged(): void
    {
        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldNotReceive('log');

        $tracker = $this->createTracker($logger);

        $originalMeeting = $this->createMeeting(99, [
            $this->createContact('Alice', 'alice@example.com', '111'),
        ]);
        $updatedMeeting = $this->createMeeting(99, [
            $this->createContact('Alice', 'alice@example.com', '111'),
        ]);

        $original = $this->createGroup(10, [], [$originalMeeting]);
        $updated = $this->createGroup(10, [], [$updatedMeeting]);

        $tracker->onGroupChanged($updated, $original);
    }

    /** @test */
    public function it_logs_contacts_for_newly_added_meeting_with_contacts(): void
    {
        $logger = Mockery::mock(AuditLogger::class);
        // New meeting has contacts → name, email, phone all logged
        $logger->shouldReceive('log')
            ->times(3)
            ->with(
                AuditLogger::ACTION_UPDATE,
                AuditLogger::ENTITY_MEETING,
                99,
                Mockery::type('string'),
                Mockery::type('string')
            );

        $tracker = $this->createTracker($logger);

        $newMeeting = $this->createMeeting(99, [
            $this->createContact('Alice', 'alice@example.com', '111'),
        ]);

        $original = $this->createGroup(10, [], []);
        $updated = $this->createGroup(10, [], [$newMeeting]);

        $tracker->onGroupChanged($updated, $original);
    }

    // ---------------------------------------------------------------
    // Combined group + meeting contact changes
    // ---------------------------------------------------------------

    /** @test */
    public function it_logs_both_group_and_meeting_contact_changes(): void
    {
        $logger = Mockery::mock(AuditLogger::class);

        // Group contact email changed (1 log)
        $logger->shouldReceive('log')
            ->once()
            ->with(
                AuditLogger::ACTION_UPDATE,
                AuditLogger::ENTITY_GROUP,
                10,
                PersonalDataFields::GROUP_CONTACT_EMAIL,
                'Contact email changed'
            );

        // Meeting contact phone changed (1 log)
        $logger->shouldReceive('log')
            ->once()
            ->with(
                AuditLogger::ACTION_UPDATE,
                AuditLogger::ENTITY_MEETING,
                99,
                PersonalDataFields::MEETING_CONTACT_PHONE,
                'Contact phone changed'
            );

        $tracker = $this->createTracker($logger);

        $originalMeeting = $this->createMeeting(99, [
            $this->createContact('Alice', 'alice@example.com', '111'),
        ]);
        $updatedMeeting = $this->createMeeting(99, [
            $this->createContact('Alice', 'alice@example.com', '222'),
        ]);

        $original = $this->createGroup(10, [
            $this->createContact('Bob', 'bob@old.com', '333'),
        ], [$originalMeeting]);
        $updated = $this->createGroup(10, [
            $this->createContact('Bob', 'bob@new.com', '333'),
        ], [$updatedMeeting]);

        $tracker->onGroupChanged($updated, $original);
    }

    /** @test */
    public function it_handles_multiple_meetings_independently(): void
    {
        $logger = Mockery::mock(AuditLogger::class);

        // Meeting 99 has a contact email change
        $logger->shouldReceive('log')
            ->once()
            ->with(
                AuditLogger::ACTION_UPDATE,
                AuditLogger::ENTITY_MEETING,
                99,
                PersonalDataFields::MEETING_CONTACT_EMAIL,
                'Contact email changed'
            );

        // Meeting 100 should NOT trigger any logs (unchanged)

        $tracker = $this->createTracker($logger);

        $originalMeeting1 = $this->createMeeting(99, [
            $this->createContact('Alice', 'alice@old.com', '111'),
        ]);
        $updatedMeeting1 = $this->createMeeting(99, [
            $this->createContact('Alice', 'alice@new.com', '111'),
        ]);

        $originalMeeting2 = $this->createMeeting(100, [
            $this->createContact('Bob', 'bob@example.com', '222'),
        ]);
        $updatedMeeting2 = $this->createMeeting(100, [
            $this->createContact('Bob', 'bob@example.com', '222'),
        ]);

        $original = $this->createGroup(10, [], [$originalMeeting1, $originalMeeting2]);
        $updated = $this->createGroup(10, [], [$updatedMeeting1, $updatedMeeting2]);

        $tracker->onGroupChanged($updated, $original);
    }

    /** @test */
    public function it_does_not_log_when_contacts_are_reordered(): void
    {
        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldNotReceive('log');

        $tracker = $this->createTracker($logger);

        $original = $this->createGroup(10, [
            $this->createContact('Alice', 'alice@example.com', '111'),
            $this->createContact('Bob', 'bob@example.com', '222'),
        ]);
        $updated = $this->createGroup(10, [
            $this->createContact('Bob', 'bob@example.com', '222'),
            $this->createContact('Alice', 'alice@example.com', '111'),
        ]);

        $tracker->onGroupChanged($updated, $original);
    }
}