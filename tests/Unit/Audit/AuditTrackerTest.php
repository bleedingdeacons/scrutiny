<?php

declare(strict_types=1);

namespace Scrutiny\Tests\Unit\Audit;

use PHPUnit\Framework\TestCase;
use Scrutiny\Audit\AuditTracker;
use Scrutiny\Audit\Interfaces\AuditLoggerInterface;
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
    private function createTracker(AuditLoggerInterface $logger): AuditTracker
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
        ];

        $data = array_merge($defaults, $overrides);
        $member = Mockery::mock(Member::class);

        foreach ($data as $method => $value) {
            $member->shouldReceive($method)->andReturn($value);
        }

        return $member;
    }

    /** @test */
    public function it_logs_when_private_name_changes(): void
    {
        $logger = Mockery::mock(AuditLoggerInterface::class);
        $logger->shouldReceive('log')
            ->once()
            ->with(
                AuditLoggerInterface::ACTION_UPDATE,
                AuditLoggerInterface::ENTITY_MEMBER,
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
        $logger = Mockery::mock(AuditLoggerInterface::class);
        $logger->shouldReceive('log')
            ->once()
            ->with(
                AuditLoggerInterface::ACTION_UPDATE,
                AuditLoggerInterface::ENTITY_MEMBER,
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
        $logger = Mockery::mock(AuditLoggerInterface::class);
        $logger->shouldReceive('log')
            ->once()
            ->with(
                AuditLoggerInterface::ACTION_UPDATE,
                AuditLoggerInterface::ENTITY_MEMBER,
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
        $logger = Mockery::mock(AuditLoggerInterface::class);
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
        $logger = Mockery::mock(AuditLoggerInterface::class);
        $logger->shouldNotReceive('log');

        $tracker = $this->createTracker($logger);

        $original = $this->createMember();
        $updated = $this->createMember();

        $tracker->onMemberChanged($updated, $original);
    }
}
