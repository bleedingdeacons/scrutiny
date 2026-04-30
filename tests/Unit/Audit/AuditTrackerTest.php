<?php

declare(strict_types=1);

namespace Scrutiny\Tests\Unit\Audit;

use PHPUnit\Framework\TestCase;
use Scrutiny\Audit\AuditTracker;
use Scrutiny\Audit\Interfaces\AuditLogger;
use Scrutiny\Privacy\PersonalDataFields;
use Unity\Members\Interfaces\Member;
use Mockery;

/**
 * Tests for AuditTracker change detection
 */
class AuditTrackerTest extends TestCase
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

    private function createMember(array $overrides = []): Member
    {
        $defaults = [
            'getId' => 42,
            'getPersonalEmail' => 'john@example.com',
            'getMobileNumber' => '07700 900123',
            'isGdprAccepted' => false,
            'getGdprAcceptedAt' => '',
            'getGdprAcceptanceVersion' => '',
            'getGdprAcceptanceMethod' => '',
            'getGdprAcceptanceStatement' => '',
        ];

        $data = array_merge($defaults, $overrides);
        $member = Mockery::mock(Member::class);

        foreach ($data as $method => $value) {
            $member->shouldReceive($method)->andReturn($value);
        }

        return $member;
    }

    /** @test */
    public function it_logs_when_anonymous_name_changes(): void
    {
        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldReceive('log')
            ->once()
            ->with(
                AuditLogger::ACTION_UPDATE,
                AuditLogger::ENTITY_MEMBER,
                42,
                'Value changed'
            );

        $tracker = $this->createTracker($logger);

        $original = $this->createMember(['getAnonymousName' => 'John S']);
        $updated = $this->createMember(['getAnonymousName' => 'John T']);

        $tracker->onMemberChanged($updated, $original);
    }

    /** @test */
    public function it_logs_when_personal_email_changes(): void
    {
        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldReceive('log')
            ->once()
            ->with(
                AuditLogger::ACTION_UPDATE,
                AuditLogger::ENTITY_MEMBER,
                42,
                PersonalDataFields::PERSONAL_EMAIL,
                'Value changed'
            );

        $tracker = $this->createTracker($logger);

        $original = $this->createMember(['getPersonalEmail' => 'old@example.com']);
        $updated = $this->createMember(['getPersonalEmail' => 'new@example.com']);

        $tracker->onMemberChanged($updated, $original);
    }

    /** @test */
    public function it_logs_when_mobile_number_changes(): void
    {
        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldReceive('log')
            ->once()
            ->with(
                AuditLogger::ACTION_UPDATE,
                AuditLogger::ENTITY_MEMBER,
                42,
                PersonalDataFields::MOBILE_NUMBER,
                'Value changed'
            );

        $tracker = $this->createTracker($logger);

        $original = $this->createMember(['getMobileNumber' => '07700 900123']);
        $updated = $this->createMember(['getMobileNumber' => '07700 900456']);

        $tracker->onMemberChanged($updated, $original);
    }

    /** @test */
    public function it_logs_all_three_fields_when_all_change(): void
    {
        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldReceive('log')->times(3);

        $tracker = $this->createTracker($logger);

        $original = $this->createMember([
            'getAnonymousName' => 'old@example.com',
            'getMobileNumber' => '07700 900123',
        ]);
        $updated = $this->createMember([
            'getAnonymousName' => 'Jane D',
            'getPersonalEmail' => 'new@example.com',
            'getMobileNumber' => '07700 900456',
        ]);

        $tracker->onMemberChanged($updated, $original);
    }

    /** @test */
    public function it_does_not_log_when_no_personal_data_changes(): void
    {
        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldNotReceive('log');

        $tracker = $this->createTracker($logger);

        $original = $this->createMember();
        $updated = $this->createMember();

        $tracker->onMemberChanged($updated, $original);
    }

    /** @test */
    public function it_logs_consent_recorded_when_gdpr_accepted_flips_to_true(): void
    {
        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldReceive('log')
            ->once()
            ->with(
                AuditLogger::ACTION_UPDATE,
                AuditLogger::ENTITY_MEMBER,
                42,
                PersonalDataFields::GDPR_ACCEPTED,
                'Consent recorded'
            );

        $tracker = $this->createTracker($logger);

        $original = $this->createMember(['isGdprAccepted' => false]);
        $updated  = $this->createMember(['isGdprAccepted' => true]);

        $tracker->onMemberChanged($updated, $original);
    }

    /** @test */
    public function it_logs_consent_revoked_when_gdpr_accepted_flips_to_false(): void
    {
        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldReceive('log')
            ->once()
            ->with(
                AuditLogger::ACTION_UPDATE,
                AuditLogger::ENTITY_MEMBER,
                42,
                PersonalDataFields::GDPR_ACCEPTED,
                'Consent revoked'
            );

        $tracker = $this->createTracker($logger);

        $original = $this->createMember(['isGdprAccepted' => true]);
        $updated  = $this->createMember(['isGdprAccepted' => false]);

        $tracker->onMemberChanged($updated, $original);
    }

    /** @test */
    public function it_logs_when_gdpr_accepted_at_changes(): void
    {
        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldReceive('log')
            ->once()
            ->with(
                AuditLogger::ACTION_UPDATE,
                AuditLogger::ENTITY_MEMBER,
                42,
                PersonalDataFields::GDPR_ACCEPTED_AT,
                'Value changed'
            );

        $tracker = $this->createTracker($logger);

        $original = $this->createMember(['getGdprAcceptedAt' => '']);
        $updated  = $this->createMember(['getGdprAcceptedAt' => '2026-04-27 15:45:00']);

        $tracker->onMemberChanged($updated, $original);
    }

    /** @test */
    public function it_logs_when_gdpr_acceptance_version_changes(): void
    {
        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldReceive('log')
            ->once()
            ->with(
                AuditLogger::ACTION_UPDATE,
                AuditLogger::ENTITY_MEMBER,
                42,
                PersonalDataFields::GDPR_ACCEPTANCE_VERSION,
                'Value changed'
            );

        $tracker = $this->createTracker($logger);

        $original = $this->createMember(['getGdprAcceptanceVersion' => '1.0']);
        $updated  = $this->createMember(['getGdprAcceptanceVersion' => '2.0']);

        $tracker->onMemberChanged($updated, $original);
    }

    /** @test */
    public function it_logs_when_gdpr_acceptance_method_changes(): void
    {
        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldReceive('log')
            ->once()
            ->with(
                AuditLogger::ACTION_UPDATE,
                AuditLogger::ENTITY_MEMBER,
                42,
                PersonalDataFields::GDPR_ACCEPTANCE_METHOD,
                'Value changed'
            );

        $tracker = $this->createTracker($logger);

        $original = $this->createMember(['getGdprAcceptanceMethod' => 'web-form']);
        $updated  = $this->createMember(['getGdprAcceptanceMethod' => 'api']);

        $tracker->onMemberChanged($updated, $original);
    }

    /** @test */
    public function it_logs_when_gdpr_acceptance_statement_changes(): void
    {
        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldReceive('log')
            ->once()
            ->with(
                AuditLogger::ACTION_UPDATE,
                AuditLogger::ENTITY_MEMBER,
                42,
                PersonalDataFields::GDPR_ACCEPTANCE_STATEMENT,
                'Value changed'
            );

        $tracker = $this->createTracker($logger);

        $original = $this->createMember(['getGdprAcceptanceStatement' => 'Old wording.']);
        $updated  = $this->createMember(['getGdprAcceptanceStatement' => 'New wording.']);

        $tracker->onMemberChanged($updated, $original);
    }

    /** @test */
    public function it_logs_all_gdpr_fields_when_a_full_acceptance_is_recorded(): void
    {
        $logger = Mockery::mock(AuditLogger::class);
        // accepted (Consent recorded) + accepted_at + version + method + statement = 5
        $logger->shouldReceive('log')->times(5);

        $tracker = $this->createTracker($logger);

        $original = $this->createMember();
        $updated  = $this->createMember([
            'isGdprAccepted'              => true,
            'getGdprAcceptedAt'           => '2026-04-27 15:45:00',
            'getGdprAcceptanceVersion'    => '2.1',
            'getGdprAcceptanceMethod'     => 'api',
            'getGdprAcceptanceStatement'  => 'I agree to the privacy policy.',
        ]);

        $tracker->onMemberChanged($updated, $original);
    }

    /** @test */
    public function it_does_not_log_gdpr_fields_when_unchanged(): void
    {
        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldNotReceive('log');

        $tracker = $this->createTracker($logger);

        $accepted = [
            'isGdprAccepted'              => true,
            'getGdprAcceptedAt'           => '2026-04-27 15:45:00',
            'getGdprAcceptanceVersion'    => '2.1',
            'getGdprAcceptanceMethod'     => 'api',
            'getGdprAcceptanceStatement'  => 'I agree to the privacy policy.',
        ];

        $original = $this->createMember($accepted);
        $updated  = $this->createMember($accepted);

        $tracker->onMemberChanged($updated, $original);
    }

    // ─── Member creation ───────────────────────────────────────────────

    /** @test */
    public function it_logs_a_batch_create_entry_when_a_member_is_created(): void
    {
        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldReceive('logBatch')
            ->once()
            ->with(
                AuditLogger::ACTION_CREATE,
                AuditLogger::ENTITY_MEMBER,
                42,
                array_merge(PersonalDataFields::ALL_FIELDS, PersonalDataFields::GDPR_FIELDS),
                'Member created'
            );

        $tracker = $this->createTracker($logger);

        $tracker->onMemberCreated($this->createMember());
    }

    /** @test */
    public function it_uses_the_members_own_id_when_logging_a_creation(): void
    {
        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldReceive('logBatch')
            ->once()
            ->with(
                AuditLogger::ACTION_CREATE,
                AuditLogger::ENTITY_MEMBER,
                999,
                Mockery::type('array'),
                'Member created'
            );

        $tracker = $this->createTracker($logger);

        $tracker->onMemberCreated($this->createMember(['getId' => 999]));
    }

    /** @test */
    public function it_does_not_emit_per_field_log_calls_when_a_member_is_created(): void
    {
        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldReceive('logBatch')->once();
        $logger->shouldNotReceive('log');

        $tracker = $this->createTracker($logger);

        $tracker->onMemberCreated($this->createMember());
    }

    // ─── Member deletion ───────────────────────────────────────────────

    /** @test */
    public function it_logs_a_batch_delete_entry_when_a_member_is_deleted(): void
    {
        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldReceive('logBatch')
            ->once()
            ->with(
                AuditLogger::ACTION_DELETE,
                AuditLogger::ENTITY_MEMBER,
                42,
                array_merge(PersonalDataFields::ALL_FIELDS, PersonalDataFields::GDPR_FIELDS),
                'Member deleted'
            );

        $tracker = $this->createTracker($logger);

        $tracker->onMemberDeleted(42, $this->createMember());
    }

    /** @test */
    public function it_logs_member_deletion_even_when_the_member_object_is_null(): void
    {
        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldReceive('logBatch')
            ->once()
            ->with(
                AuditLogger::ACTION_DELETE,
                AuditLogger::ENTITY_MEMBER,
                42,
                array_merge(PersonalDataFields::ALL_FIELDS, PersonalDataFields::GDPR_FIELDS),
                'Member deleted'
            );

        $tracker = $this->createTracker($logger);

        $tracker->onMemberDeleted(42, null);
    }

    /** @test */
    public function it_uses_the_supplied_post_id_when_logging_a_deletion(): void
    {
        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldReceive('logBatch')
            ->once()
            ->with(
                AuditLogger::ACTION_DELETE,
                AuditLogger::ENTITY_MEMBER,
                7777,
                Mockery::type('array'),
                'Member deleted'
            );

        $tracker = $this->createTracker($logger);

        $tracker->onMemberDeleted(7777, null);
    }

    /** @test */
    public function it_does_not_emit_per_field_log_calls_when_a_member_is_deleted(): void
    {
        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldReceive('logBatch')->once();
        $logger->shouldNotReceive('log');

        $tracker = $this->createTracker($logger);

        $tracker->onMemberDeleted(42, $this->createMember());
    }
}