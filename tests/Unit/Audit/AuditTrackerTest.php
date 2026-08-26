<?php

declare(strict_types=1);

namespace Scrutiny\Tests\Unit\Audit;

use PHPUnit\Framework\TestCase;
use Scrutiny\Audit\AuditTracker;
use Scrutiny\Audit\Interfaces\AuditLogger;
use Scrutiny\Privacy\PersonalDataFields;
use Unity\Groups\Interfaces\Group;
use Unity\Groups\Interfaces\GroupRepository;
use Unity\Members\Interfaces\Member;
use Unity\Members\ResponderCertification;
use Unity\Positions\Interfaces\Position;
use Unity\Positions\Interfaces\PositionRepository;
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
     *
     * The repositories are only consulted once a service role actually
     * changes, so cases that leave home group and position alone can take
     * the bare mocks and never touch them.
     */
    private function createTracker(
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
     * Any ID outside the map resolves to null, standing in for a group that
     * has since been deleted.
     *
     * @param array<int, string> $titles Map of group ID to group title
     */
    private function groupRepository(array $titles): GroupRepository
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
    private function positionRepository(array $names): PositionRepository
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

    private function createMember(array $overrides = []): Member
    {
        $defaults = [
            'getId' => 42,
            'getPersonalEmail' => 'john@example.com',
            'getMobileNumber' => '07700 900123',
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
        // The member here holds neither a home group nor a position, so the
        // batch entry is the whole of it.
        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldReceive('logBatch')->once();
        $logger->shouldNotReceive('log');

        $tracker = $this->createTracker($logger);

        $tracker->onMemberDeleted(42, $this->createMember());
    }

    // ─── Home group ────────────────────────────────────────────────────

    /** @test */
    public function it_names_the_group_when_a_home_group_is_assigned(): void
    {
        // Home group is a service role, not personal data, so the entry says
        // which group — not merely that something changed.
        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldReceive('log')
            ->once()
            ->with(
                AuditLogger::ACTION_UPDATE,
                AuditLogger::ENTITY_MEMBER,
                42,
                PersonalDataFields::HOME_GROUP,
                'Assigned to Thursday Big Book'
            );

        $tracker = $this->createTracker(
            $logger,
            $this->groupRepository([7 => 'Thursday Big Book'])
        );

        $original = $this->createMember(['getHomeGroup' => 0]);
        $updated = $this->createMember(['getHomeGroup' => 7]);

        $tracker->onMemberChanged($updated, $original);
    }

    /** @test */
    public function it_names_the_group_left_behind_when_a_home_group_is_cleared(): void
    {
        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldReceive('log')
            ->once()
            ->with(
                AuditLogger::ACTION_UPDATE,
                AuditLogger::ENTITY_MEMBER,
                42,
                PersonalDataFields::HOME_GROUP,
                'Removed from Thursday Big Book'
            );

        $tracker = $this->createTracker(
            $logger,
            $this->groupRepository([7 => 'Thursday Big Book'])
        );

        $original = $this->createMember(['getHomeGroup' => 7]);
        $updated = $this->createMember(['getHomeGroup' => 0]);

        $tracker->onMemberChanged($updated, $original);
    }

    /** @test */
    public function it_names_both_groups_when_a_home_group_moves(): void
    {
        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldReceive('log')
            ->once()
            ->with(
                AuditLogger::ACTION_UPDATE,
                AuditLogger::ENTITY_MEMBER,
                42,
                PersonalDataFields::HOME_GROUP,
                'Changed from Thursday Big Book to Sunday Steps'
            );

        $tracker = $this->createTracker(
            $logger,
            $this->groupRepository([7 => 'Thursday Big Book', 8 => 'Sunday Steps'])
        );

        $original = $this->createMember(['getHomeGroup' => 7]);
        $updated = $this->createMember(['getHomeGroup' => 8]);

        $tracker->onMemberChanged($updated, $original);
    }

    /** @test */
    public function it_falls_back_to_the_id_when_a_group_no_longer_resolves(): void
    {
        // A group deleted since the assignment still has to be traceable —
        // an entry reading "Removed from " and nothing else would not be.
        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldReceive('log')
            ->once()
            ->with(
                AuditLogger::ACTION_UPDATE,
                AuditLogger::ENTITY_MEMBER,
                42,
                PersonalDataFields::HOME_GROUP,
                'Removed from #7'
            );

        $tracker = $this->createTracker($logger, $this->groupRepository([]));

        $original = $this->createMember(['getHomeGroup' => 7]);
        $updated = $this->createMember(['getHomeGroup' => 0]);

        $tracker->onMemberChanged($updated, $original);
    }

    /** @test */
    public function it_does_not_log_a_home_group_that_did_not_change(): void
    {
        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldNotReceive('log');

        $tracker = $this->createTracker($logger);

        $original = $this->createMember(['getHomeGroup' => 7]);
        $updated = $this->createMember(['getHomeGroup' => 7]);

        $tracker->onMemberChanged($updated, $original);

        self::assertTrue(true, 'onMemberChanged completed without logging');
    }

    // ─── Intergroup position ───────────────────────────────────────────

    /** @test */
    public function it_names_the_position_when_an_intergroup_position_is_assigned(): void
    {
        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldReceive('log')
            ->once()
            ->with(
                AuditLogger::ACTION_UPDATE,
                AuditLogger::ENTITY_MEMBER,
                42,
                PersonalDataFields::INTERGROUP_POSITION,
                'Assigned to Telephone Liaison Officer'
            );

        $tracker = $this->createTracker(
            $logger,
            null,
            $this->positionRepository([3 => 'Telephone Liaison Officer'])
        );

        $original = $this->createMember(['getIntergroupPosition' => 0]);
        $updated = $this->createMember(['getIntergroupPosition' => 3]);

        $tracker->onMemberChanged($updated, $original);
    }

    /** @test */
    public function it_names_the_position_vacated_when_an_intergroup_position_is_removed(): void
    {
        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldReceive('log')
            ->once()
            ->with(
                AuditLogger::ACTION_UPDATE,
                AuditLogger::ENTITY_MEMBER,
                42,
                PersonalDataFields::INTERGROUP_POSITION,
                'Removed from Telephone Liaison Officer'
            );

        $tracker = $this->createTracker(
            $logger,
            null,
            $this->positionRepository([3 => 'Telephone Liaison Officer'])
        );

        $original = $this->createMember(['getIntergroupPosition' => 3]);
        $updated = $this->createMember(['getIntergroupPosition' => 0]);

        $tracker->onMemberChanged($updated, $original);
    }

    /** @test */
    public function it_logs_a_home_group_and_a_position_that_change_together(): void
    {
        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldReceive('log')
            ->once()
            ->with(
                AuditLogger::ACTION_UPDATE,
                AuditLogger::ENTITY_MEMBER,
                42,
                PersonalDataFields::HOME_GROUP,
                'Assigned to Thursday Big Book'
            );
        $logger->shouldReceive('log')
            ->once()
            ->with(
                AuditLogger::ACTION_UPDATE,
                AuditLogger::ENTITY_MEMBER,
                42,
                PersonalDataFields::INTERGROUP_POSITION,
                'Assigned to Telephone Liaison Officer'
            );

        $tracker = $this->createTracker(
            $logger,
            $this->groupRepository([7 => 'Thursday Big Book']),
            $this->positionRepository([3 => 'Telephone Liaison Officer'])
        );

        $original = $this->createMember(['getHomeGroup' => 0, 'getIntergroupPosition' => 0]);
        $updated = $this->createMember(['getHomeGroup' => 7, 'getIntergroupPosition' => 3]);

        $tracker->onMemberChanged($updated, $original);
    }

    // ─── Service roles at creation and deletion ────────────────────────

    /** @test */
    public function it_records_the_service_roles_a_member_is_created_holding(): void
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
        $logger->shouldReceive('log')
            ->once()
            ->with(
                AuditLogger::ACTION_CREATE,
                AuditLogger::ENTITY_MEMBER,
                42,
                PersonalDataFields::HOME_GROUP,
                'Assigned to Thursday Big Book'
            );
        $logger->shouldReceive('log')
            ->once()
            ->with(
                AuditLogger::ACTION_CREATE,
                AuditLogger::ENTITY_MEMBER,
                42,
                PersonalDataFields::INTERGROUP_POSITION,
                'Assigned to Telephone Liaison Officer'
            );

        $tracker = $this->createTracker(
            $logger,
            $this->groupRepository([7 => 'Thursday Big Book']),
            $this->positionRepository([3 => 'Telephone Liaison Officer'])
        );

        $tracker->onMemberCreated($this->createMember([
            'getHomeGroup' => 7,
            'getIntergroupPosition' => 3,
        ]));
    }

    /** @test */
    public function it_records_a_member_created_as_a_gsr(): void
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
        $logger->shouldReceive('log')
            ->once()
            ->with(
                AuditLogger::ACTION_CREATE,
                AuditLogger::ENTITY_MEMBER,
                42,
                PersonalDataFields::HOME_GROUP,
                'Assigned to Thursday Big Book'
            );
        $logger->shouldReceive('log')
            ->once()
            ->with(
                AuditLogger::ACTION_CREATE,
                AuditLogger::ENTITY_MEMBER,
                42,
                PersonalDataFields::GSR,
                'Assigned to Thursday Big Book'
            );

        $tracker = $this->createTracker(
            $logger,
            $this->groupRepository([7 => 'Thursday Big Book'])
        );

        $tracker->onMemberCreated($this->createMember([
            'getHomeGroup' => 7,
            'isGSR' => true,
        ]));
    }

    /** @test */
    public function it_records_the_service_roles_a_deleted_member_still_held(): void
    {
        // Phrased exactly as an ordinary removal: the entry's own action
        // column is what marks it as a deletion.
        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldReceive('logBatch')->once();
        $logger->shouldReceive('log')
            ->once()
            ->with(
                AuditLogger::ACTION_DELETE,
                AuditLogger::ENTITY_MEMBER,
                42,
                PersonalDataFields::HOME_GROUP,
                'Removed from Thursday Big Book'
            );
        $logger->shouldReceive('log')
            ->once()
            ->with(
                AuditLogger::ACTION_DELETE,
                AuditLogger::ENTITY_MEMBER,
                42,
                PersonalDataFields::INTERGROUP_POSITION,
                'Removed from Telephone Liaison Officer'
            );

        $tracker = $this->createTracker(
            $logger,
            $this->groupRepository([7 => 'Thursday Big Book']),
            $this->positionRepository([3 => 'Telephone Liaison Officer'])
        );

        $tracker->onMemberDeleted(42, $this->createMember([
            'getHomeGroup' => 7,
            'getIntergroupPosition' => 3,
        ]));
    }

    // ─── Service availability ──────────────────────────────────────────

    /** @test */
    public function it_logs_the_new_rotation_date_when_it_changes(): void
    {
        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldReceive('log')
            ->once()
            ->with(
                AuditLogger::ACTION_UPDATE,
                AuditLogger::ENTITY_MEMBER,
                42,
                PersonalDataFields::POSITION_ROTATION,
                'Changed to 2027-01-01'
            );

        $tracker = $this->createTracker($logger);

        $original = $this->createMember(['getIntergroupPositionRotation' => '2026-01-01']);
        $updated = $this->createMember(['getIntergroupPositionRotation' => '2027-01-01']);

        $tracker->onMemberChanged($updated, $original);
    }

    /** @test */
    public function it_says_cleared_when_a_rotation_date_is_emptied(): void
    {
        // 'Changed to ' with nothing after it would read as a truncated cell.
        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldReceive('log')
            ->once()
            ->with(
                AuditLogger::ACTION_UPDATE,
                AuditLogger::ENTITY_MEMBER,
                42,
                PersonalDataFields::POSITION_ROTATION,
                'Cleared'
            );

        $tracker = $this->createTracker($logger);

        $original = $this->createMember(['getIntergroupPositionRotation' => '2026-01-01']);
        $updated = $this->createMember(['getIntergroupPositionRotation' => '']);

        $tracker->onMemberChanged($updated, $original);
    }

    /** @test */
    public function it_logs_both_directions_of_the_twelfth_step_flag(): void
    {
        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldReceive('log')
            ->once()
            ->with(
                AuditLogger::ACTION_UPDATE,
                AuditLogger::ENTITY_MEMBER,
                42,
                PersonalDataFields::TWELFTH_STEPPER,
                'Available for 12th-step calls'
            );

        $tracker = $this->createTracker($logger);

        $tracker->onMemberChanged(
            $this->createMember(['isTwelfthStepper' => true]),
            $this->createMember(['isTwelfthStepper' => false])
        );

        $logger2 = Mockery::mock(AuditLogger::class);
        $logger2->shouldReceive('log')
            ->once()
            ->with(
                AuditLogger::ACTION_UPDATE,
                AuditLogger::ENTITY_MEMBER,
                42,
                PersonalDataFields::TWELFTH_STEPPER,
                'No longer available for 12th-step calls'
            );

        $this->createTracker($logger2)->onMemberChanged(
            $this->createMember(['isTwelfthStepper' => false]),
            $this->createMember(['isTwelfthStepper' => true])
        );
    }

    /** @test */
    public function it_logs_the_telephone_responder_flag(): void
    {
        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldReceive('log')
            ->once()
            ->with(
                AuditLogger::ACTION_UPDATE,
                AuditLogger::ENTITY_MEMBER,
                42,
                PersonalDataFields::TELEPHONE_RESPONDER,
                'Available as a telephone responder'
            );

        $tracker = $this->createTracker($logger);

        $tracker->onMemberChanged(
            $this->createMember(['isTelephoneResponder' => true]),
            $this->createMember(['isTelephoneResponder' => false])
        );
    }

    // ─── Visibility toggles ────────────────────────────────────────────

    /** @test */
    public function it_names_the_new_setting_when_name_visibility_changes(): void
    {
        // A privacy toggle's value is a yes or a no and identifies nobody, so
        // the entry records which way it went.
        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldReceive('log')
            ->once()
            ->with(
                AuditLogger::ACTION_UPDATE,
                AuditLogger::ENTITY_MEMBER,
                42,
                PersonalDataFields::SHOW_ANONYMOUS_NAME,
                'Name hidden'
            );

        $tracker = $this->createTracker($logger);

        $tracker->onMemberChanged(
            $this->createMember(['showAnonymousName' => false]),
            $this->createMember(['showAnonymousName' => true])
        );
    }

    /** @test */
    public function it_names_the_new_setting_when_profile_visibility_changes(): void
    {
        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldReceive('log')
            ->once()
            ->with(
                AuditLogger::ACTION_UPDATE,
                AuditLogger::ENTITY_MEMBER,
                42,
                PersonalDataFields::SHOW_MEMBER_PROFILE,
                'Profile shown publicly'
            );

        $tracker = $this->createTracker($logger);

        $tracker->onMemberChanged(
            $this->createMember(['showMemberProfile' => true]),
            $this->createMember(['showMemberProfile' => false])
        );
    }

    // ─── Fields recorded without their values ──────────────────────────

    /** @test */
    public function it_records_an_area_change_without_the_area(): void
    {
        // Coarse, but still where a named individual is.
        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldReceive('log')
            ->once()
            ->with(
                AuditLogger::ACTION_UPDATE,
                AuditLogger::ENTITY_MEMBER,
                42,
                PersonalDataFields::AREA,
                'Value changed'
            );

        $tracker = $this->createTracker($logger);

        $tracker->onMemberChanged(
            $this->createMember(['getArea' => 'Bristol South']),
            $this->createMember(['getArea' => 'Bristol North'])
        );
    }

    /** @test */
    public function it_records_an_accepts_change_without_the_selection(): void
    {
        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldReceive('log')
            ->once()
            ->with(
                AuditLogger::ACTION_UPDATE,
                AuditLogger::ENTITY_MEMBER,
                42,
                PersonalDataFields::ACCEPTS,
                'Value changed'
            );

        $tracker = $this->createTracker($logger);

        $tracker->onMemberChanged(
            $this->createMember(['getAccepts' => ['men', 'women']]),
            $this->createMember(['getAccepts' => ['men']])
        );
    }

    /** @test */
    public function it_ignores_a_reordered_accepts_selection(): void
    {
        // An unordered checkbox set: same selection, different order, so
        // nothing changed.
        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldNotReceive('log');

        $tracker = $this->createTracker($logger);

        $tracker->onMemberChanged(
            $this->createMember(['getAccepts' => ['women', 'men']]),
            $this->createMember(['getAccepts' => ['men', 'women']])
        );

        self::assertTrue(true, 'onMemberChanged completed without logging');
    }

    /** @test */
    public function it_records_a_profile_change_without_the_prose(): void
    {
        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldReceive('log')
            ->once()
            ->with(
                AuditLogger::ACTION_UPDATE,
                AuditLogger::ENTITY_MEMBER,
                42,
                PersonalDataFields::ANONYMOUS_PROFILE,
                'Value changed'
            );

        $tracker = $this->createTracker($logger);

        $tracker->onMemberChanged(
            $this->createMember(['getAnonymousProfile' => 'Sober since 1912.']),
            $this->createMember(['getAnonymousProfile' => ''])
        );
    }

    /** @test */
    public function it_records_a_meeting_po_change_without_the_value(): void
    {
        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldReceive('log')
            ->once()
            ->with(
                AuditLogger::ACTION_UPDATE,
                AuditLogger::ENTITY_MEMBER,
                42,
                PersonalDataFields::MEETING_PO,
                'Value changed'
            );

        $tracker = $this->createTracker($logger);

        $tracker->onMemberChanged(
            $this->createMember(['getMeetingPO' => 77]),
            $this->createMember(['getMeetingPO' => null])
        );
    }

    /** @test */
    public function it_still_does_not_log_the_updated_timestamp(): void
    {
        // getUpdated() moves on every save. Auditing it would put a second,
        // empty row beside every real one, so it is deliberately never read.
        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldNotReceive('log');

        $tracker = $this->createTracker($logger);

        $tracker->onMemberChanged(
            $this->createMember(['getUpdated' => '2026-08-26 21:00:00']),
            $this->createMember(['getUpdated' => '2026-08-26 20:00:00'])
        );

        self::assertTrue(true, 'onMemberChanged completed without logging');
    }

    // ─── GSR ───────────────────────────────────────────────────────────

    /** @test */
    public function it_names_the_group_when_a_member_becomes_its_gsr(): void
    {
        // "GSR" alone would not say what the member is GSR for, so the entry
        // names the group the role is held on behalf of.
        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldReceive('log')
            ->once()
            ->with(
                AuditLogger::ACTION_UPDATE,
                AuditLogger::ENTITY_MEMBER,
                42,
                PersonalDataFields::GSR,
                'Assigned to Thursday Big Book'
            );

        $tracker = $this->createTracker(
            $logger,
            $this->groupRepository([7 => 'Thursday Big Book'])
        );

        $original = $this->createMember(['getHomeGroup' => 7, 'isGSR' => false]);
        $updated = $this->createMember(['getHomeGroup' => 7, 'isGSR' => true]);

        $tracker->onMemberChanged($updated, $original);
    }

    /** @test */
    public function it_names_the_group_when_a_member_stops_being_its_gsr(): void
    {
        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldReceive('log')
            ->once()
            ->with(
                AuditLogger::ACTION_UPDATE,
                AuditLogger::ENTITY_MEMBER,
                42,
                PersonalDataFields::GSR,
                'Removed from Thursday Big Book'
            );

        $tracker = $this->createTracker(
            $logger,
            $this->groupRepository([7 => 'Thursday Big Book'])
        );

        $original = $this->createMember(['getHomeGroup' => 7, 'isGSR' => true]);
        $updated = $this->createMember(['getHomeGroup' => 7, 'isGSR' => false]);

        $tracker->onMemberChanged($updated, $original);
    }

    /** @test */
    public function it_logs_a_gsr_who_carries_the_role_to_a_new_home_group(): void
    {
        // The flag does not change here, so comparing isGSR() alone would log
        // nothing and leave the member looking like the old group's GSR still.
        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldReceive('log')
            ->once()
            ->with(
                AuditLogger::ACTION_UPDATE,
                AuditLogger::ENTITY_MEMBER,
                42,
                PersonalDataFields::HOME_GROUP,
                'Changed from Thursday Big Book to Sunday Steps'
            );
        $logger->shouldReceive('log')
            ->once()
            ->with(
                AuditLogger::ACTION_UPDATE,
                AuditLogger::ENTITY_MEMBER,
                42,
                PersonalDataFields::GSR,
                'Changed from Thursday Big Book to Sunday Steps'
            );

        $tracker = $this->createTracker(
            $logger,
            $this->groupRepository([7 => 'Thursday Big Book', 8 => 'Sunday Steps'])
        );

        $original = $this->createMember(['getHomeGroup' => 7, 'isGSR' => true]);
        $updated = $this->createMember(['getHomeGroup' => 8, 'isGSR' => true]);

        $tracker->onMemberChanged($updated, $original);
    }

    /** @test */
    public function it_does_not_log_a_gsr_flag_that_did_not_change(): void
    {
        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldNotReceive('log');

        $tracker = $this->createTracker(
            $logger,
            $this->groupRepository([7 => 'Thursday Big Book'])
        );

        $original = $this->createMember(['getHomeGroup' => 7, 'isGSR' => true]);
        $updated = $this->createMember(['getHomeGroup' => 7, 'isGSR' => true]);

        $tracker->onMemberChanged($updated, $original);

        self::assertTrue(true, 'onMemberChanged completed without logging');
    }

    /** @test */
    public function it_records_a_gsr_flag_set_without_a_home_group_behind_it(): void
    {
        // Meaningless data — the role is held on behalf of a group — but it
        // is still a change, and dropping it silently is the gap this
        // tracking exists to close.
        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldReceive('log')
            ->once()
            ->with(
                AuditLogger::ACTION_UPDATE,
                AuditLogger::ENTITY_MEMBER,
                42,
                PersonalDataFields::GSR,
                'Assigned to (no home group)'
            );

        $tracker = $this->createTracker($logger, $this->groupRepository([]));

        $original = $this->createMember(['getHomeGroup' => 0, 'isGSR' => false]);
        $updated = $this->createMember(['getHomeGroup' => 0, 'isGSR' => true]);

        $tracker->onMemberChanged($updated, $original);
    }

    /** @test */
    public function it_records_the_gsr_role_a_deleted_member_still_held(): void
    {
        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldReceive('logBatch')->once();
        $logger->shouldReceive('log')
            ->once()
            ->with(
                AuditLogger::ACTION_DELETE,
                AuditLogger::ENTITY_MEMBER,
                42,
                PersonalDataFields::HOME_GROUP,
                'Removed from Thursday Big Book'
            );
        $logger->shouldReceive('log')
            ->once()
            ->with(
                AuditLogger::ACTION_DELETE,
                AuditLogger::ENTITY_MEMBER,
                42,
                PersonalDataFields::GSR,
                'Removed from Thursday Big Book'
            );

        $tracker = $this->createTracker(
            $logger,
            $this->groupRepository([7 => 'Thursday Big Book'])
        );

        $tracker->onMemberDeleted(42, $this->createMember([
            'getHomeGroup' => 7,
            'isGSR' => true,
        ]));
    }

    /** @test */
    public function it_truncates_a_name_too_long_for_the_detail_column(): void
    {
        // The detail column is VARCHAR(255) and a move holds two names at
        // once, so each is capped well inside it.
        $longName = str_repeat('A', 150);

        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldReceive('log')
            ->once()
            ->with(
                AuditLogger::ACTION_UPDATE,
                AuditLogger::ENTITY_MEMBER,
                42,
                PersonalDataFields::HOME_GROUP,
                'Assigned to ' . str_repeat('A', 99) . '…'
            );

        $tracker = $this->createTracker($logger, $this->groupRepository([7 => $longName]));

        $original = $this->createMember(['getHomeGroup' => 0]);
        $updated = $this->createMember(['getHomeGroup' => 7]);

        $tracker->onMemberChanged($updated, $original);
    }
}
