<?php

declare(strict_types=1);

namespace Scrutiny\Tests\Unit\Audit;

use PHPUnit\Framework\TestCase;
use Scrutiny\Audit\AuditTracker;
use Scrutiny\Audit\Interfaces\AuditLogger;
use Scrutiny\Privacy\PersonalDataFields;
use Unity\Members\Interfaces\Member;
use Unity\Members\ResponderCertification;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

/**
 * Tests for AuditTracker change detection
 */
class AuditTrackerTest extends TestCase
{
    // Verification here is entirely Mockery expectations. Without this
    // trait PHPUnit sees no assertions and marks every test risky —
    // and failOnRisky would then fail the suite.
    use MockeryPHPUnitIntegration;
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
            'getResponderCertification' => ResponderCertification::None,
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
    public function it_does_not_log_when_only_the_anonymous_name_changes(): void
    {
        // The anonymous name is not personal data as this plugin defines it:
        // PersonalDataFields has no constant for it and onMemberChanged does
        // not inspect it. Renaming a member on its own is not an audit event.
        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldNotReceive('log');

        $tracker = $this->createTracker($logger);

        $original = $this->createMember(['getAnonymousName' => 'John S']);
        $updated = $this->createMember(['getAnonymousName' => 'John T']);

        $tracker->onMemberChanged($updated, $original);

        self::assertTrue(true, 'onMemberChanged completed without logging');
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
    public function it_logs_the_new_value_when_responder_certification_changes(): void
    {
        // Unlike the personal-data fields, the certification entry names the
        // stage the member was moved to — it is a service status, not PII.
        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldReceive('log')
            ->once()
            ->with(
                AuditLogger::ACTION_UPDATE,
                AuditLogger::ENTITY_MEMBER,
                42,
                PersonalDataFields::RESPONDER_CERTIFICATION,
                'Changed to Certified'
            );

        $tracker = $this->createTracker($logger);

        $original = $this->createMember(['getResponderCertification' => ResponderCertification::Pending]);
        $updated = $this->createMember(['getResponderCertification' => ResponderCertification::Certified]);

        $tracker->onMemberChanged($updated, $original);
    }

    /** @test */
    public function it_does_not_log_when_responder_certification_is_unchanged(): void
    {
        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldNotReceive('log');

        $tracker = $this->createTracker($logger);

        $original = $this->createMember(['getResponderCertification' => ResponderCertification::Certified]);
        $updated = $this->createMember(['getResponderCertification' => ResponderCertification::Certified]);

        $tracker->onMemberChanged($updated, $original);

        self::assertTrue(true, 'onMemberChanged completed without logging');
    }

    /** @test */
    public function it_logs_both_tracked_fields_when_they_change_together(): void
    {
        // Two, not three: the anonymous name changes here as well, and is
        // deliberately not audited.
        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldReceive('log')->times(2);

        $tracker = $this->createTracker($logger);

        $original = $this->createMember([
            'getAnonymousName' => 'John S',
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
    public function it_logs_consent_once_when_a_full_acceptance_is_recorded(): void
    {
        // One event, not five. AuditTracker::logGdprChanges() deliberately
        // records only the acceptance flag: the timestamp, version, method and
        // statement are all stored against the member anyway, and logging each
        // of them was judged to be audit-log spam. The four tests that asserted
        // a log per sub-field were removed with this one left to state the rule.
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
    public function it_logs_a_single_create_entry_when_a_member_is_created(): void
    {
        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldReceive('log')
            ->once()
            ->with(
                AuditLogger::ACTION_CREATE,
                AuditLogger::ENTITY_MEMBER,
                42,
                PersonalDataFields::ALL_FIELDS_SENTINEL,
                'Member created'
            );
        $logger->shouldNotReceive('logBatch');

        $tracker = $this->createTracker($logger);

        $tracker->onMemberCreated($this->createMember());
    }

    /** @test */
    public function it_uses_the_members_own_id_when_logging_a_creation(): void
    {
        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldReceive('log')
            ->once()
            ->with(
                AuditLogger::ACTION_CREATE,
                AuditLogger::ENTITY_MEMBER,
                999,
                PersonalDataFields::ALL_FIELDS_SENTINEL,
                'Member created'
            );

        $tracker = $this->createTracker($logger);

        $tracker->onMemberCreated($this->createMember(['getId' => 999]));
    }

    /** @test */
    public function it_does_not_emit_per_field_log_calls_when_a_member_is_created(): void
    {
        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldReceive('log')->once();
        $logger->shouldNotReceive('logBatch');

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