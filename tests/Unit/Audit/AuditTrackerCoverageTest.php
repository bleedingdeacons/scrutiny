<?php

declare(strict_types=1);

namespace Scrutiny\Tests\Unit\Audit;

use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Scrutiny\Audit\AuditTracker;
use Scrutiny\Audit\Interfaces\AuditLogger;
use Scrutiny\Privacy\PersonalDataFields;
use Scrutiny\Privacy\PersonalDataPolicy;
use Unity\Contacts\Interfaces\Contact;
use Unity\Groups\Interfaces\Group;
use Unity\Meetings\Interfaces\Meeting;
use Unity\Members\Interfaces\Member;
use Unity\Members\ResponderCertification;
use WP_Mock;

/**
 * Broad coverage for AuditTracker's view-tracking, group/contact change,
 * deletion, hide and import/export logging paths.
 *
 * @covers \Scrutiny\Audit\AuditTracker
 */
class AuditTrackerCoverageTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();
        $GLOBALS['scrutiny_test_capabilities'] = [];
        $_GET = [];
    }

    protected function tearDown(): void
    {
        WP_Mock::tearDown();
        Mockery::close();
        $_GET = [];
        parent::tearDown();
    }

    /**
     * Build a tracker with dependencies injected by reflection so no WP
     * hooks are registered (the constructor's add_action/add_filter calls
     * are not under test here).
     *
     * @param array<string, mixed>  $config
     * @param array<string, string> $acfMap
     */
    private function tracker(AuditLogger $logger, array $config = [], array $acfMap = []): AuditTracker
    {
        $ref = new \ReflectionClass(AuditTracker::class);
        $tracker = $ref->newInstanceWithoutConstructor();

        $this->setProp($tracker, 'logger', $logger);
        $this->setProp($tracker, 'policy', new PersonalDataPolicy());
        $this->setProp($tracker, 'member_config', $config);
        $this->setProp($tracker, 'acfFieldMap', $acfMap);

        return $tracker;
    }

    private function setProp(object $object, string $name, mixed $value): void
    {
        $prop = (new \ReflectionClass($object))->getProperty($name);
        $prop->setAccessible(true);
        $prop->setValue($object, $value);
    }

    private function grantView(): void
    {
        $GLOBALS['scrutiny_test_capabilities'][PersonalDataPolicy::VIEW_CAPABILITY] = true;
    }

    /**
     * @param array<int, array{name?: string, email?: string, phone?: string}> $rows
     * @return Contact[]
     */
    private function contacts(array $rows): array
    {
        return array_map(function (array $row): Contact {
            $c = Mockery::mock(Contact::class);
            $c->shouldReceive('getName')->andReturn($row['name'] ?? '');
            $c->shouldReceive('getEmail')->andReturn($row['email'] ?? '');
            $c->shouldReceive('getPhone')->andReturn($row['phone'] ?? '');
            return $c;
        }, $rows);
    }

    // ─── import / export ────────────────────────────────────────────

    /**
     * @test
     */
    public function import_export_hooks_log_one_entry_each(): void
    {
        $logger = Mockery::mock(AuditLogger::class);

        $logger->shouldReceive('log')->once()
            ->with(AuditLogger::ACTION_IMPORT, AuditLogger::ENTITY_MEMBER, 0, 'personal-email', Mockery::type('string'));
        $logger->shouldReceive('log')->once()
            ->with(AuditLogger::ACTION_EXPORT, AuditLogger::ENTITY_MEMBER, 0, 'personal-email', Mockery::type('string'));
        $logger->shouldReceive('log')->once()
            ->with(AuditLogger::ACTION_IMPORT, AuditLogger::ENTITY_GROUP, 0, 'group', Mockery::type('string'));
        $logger->shouldReceive('log')->once()
            ->with(AuditLogger::ACTION_EXPORT, AuditLogger::ENTITY_GROUP, 0, 'group', Mockery::type('string'));
        $logger->shouldReceive('log')->once()
            ->with(AuditLogger::ACTION_IMPORT, AuditLogger::ENTITY_POSITION, 0, 'position', Mockery::type('string'));
        $logger->shouldReceive('log')->once()
            ->with(AuditLogger::ACTION_EXPORT, AuditLogger::ENTITY_POSITION, 0, 'position', Mockery::type('string'));

        $tracker = $this->tracker($logger);

        $tracker->onMemberImport(3, 'personal-email');
        $tracker->onMemberExport(4, 'personal-email');
        $tracker->onGroupImport(5, 'group');
        $tracker->onGroupExport(6, 'group');
        $tracker->onPositionImport(7, 'position');
        $tracker->onPositionExport(8, 'position');
    }

    // ─── deletion / hide ────────────────────────────────────────────

    /**
     * @test
     */
    public function member_deletion_batches_every_personal_and_gdpr_field(): void
    {
        $expectedFields = array_merge(PersonalDataFields::ALL_FIELDS, PersonalDataFields::GDPR_FIELDS);

        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldReceive('logBatch')->once()
            ->with(AuditLogger::ACTION_DELETE, AuditLogger::ENTITY_MEMBER, 99, $expectedFields, 'Member deleted');

        $this->tracker($logger)->onMemberDeleted(99, null);
    }

    /**
     * @test
     */
    public function group_deletion_and_hide_batch_the_group_contact_fields(): void
    {
        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldReceive('logBatch')->once()
            ->with(AuditLogger::ACTION_DELETE, AuditLogger::ENTITY_GROUP, 7, PersonalDataFields::GROUP_CONTACT_FIELDS, 'Group deleted');
        $logger->shouldReceive('logBatch')->once()
            ->with(AuditLogger::ACTION_UPDATE, AuditLogger::ENTITY_GROUP, 7, PersonalDataFields::GROUP_CONTACT_FIELDS, Mockery::type('string'));

        $tracker = $this->tracker($logger);
        $tracker->onGroupDeleted(7, null);
        $tracker->onGroupHidden(7, null);
    }

    // ─── group / contact change ─────────────────────────────────────

    /**
     * @test
     */
    public function group_change_logs_each_differing_contact_field(): void
    {
        $logger = Mockery::mock(AuditLogger::class);
        // Name and email differ; phone is unchanged.
        $logger->shouldReceive('log')->once()
            ->with(AuditLogger::ACTION_UPDATE, AuditLogger::ENTITY_GROUP, 5, PersonalDataFields::GROUP_CONTACT_NAME, Mockery::type('string'));
        $logger->shouldReceive('log')->once()
            ->with(AuditLogger::ACTION_UPDATE, AuditLogger::ENTITY_GROUP, 5, PersonalDataFields::GROUP_CONTACT_EMAIL, Mockery::type('string'));

        $original = Mockery::mock(Group::class);
        $original->shouldReceive('getId')->andReturn(5);
        $original->shouldReceive('getContacts')->andReturn($this->contacts([
            ['name' => 'Alice', 'email' => 'alice@example.com', 'phone' => '111'],
        ]));
        $original->shouldReceive('getMeetings')->andReturn([]);

        $updated = Mockery::mock(Group::class);
        $updated->shouldReceive('getId')->andReturn(5);
        $updated->shouldReceive('getContacts')->andReturn($this->contacts([
            ['name' => 'Alicia', 'email' => 'alicia@example.com', 'phone' => '111'],
        ]));
        $updated->shouldReceive('getMeetings')->andReturn([]);

        $this->tracker($logger)->onGroupChanged($updated, $original);
    }

    /**
     * @test
     */
    public function group_change_logs_meeting_contact_changes_too(): void
    {
        $logger = Mockery::mock(AuditLogger::class);
        // Group contacts unchanged; a meeting's contact phone changed.
        $logger->shouldReceive('log')->once()
            ->with(AuditLogger::ACTION_UPDATE, AuditLogger::ENTITY_MEETING, 88, PersonalDataFields::MEETING_CONTACT_PHONE, Mockery::type('string'));

        $groupContacts = $this->contacts([['name' => 'Al', 'email' => 'al@example.com', 'phone' => '111']]);

        $originalMeeting = Mockery::mock(Meeting::class);
        $originalMeeting->shouldReceive('getId')->andReturn(88);
        $originalMeeting->shouldReceive('getContacts')->andReturn($this->contacts([['phone' => '111']]));

        $updatedMeeting = Mockery::mock(Meeting::class);
        $updatedMeeting->shouldReceive('getId')->andReturn(88);
        $updatedMeeting->shouldReceive('getContacts')->andReturn($this->contacts([['phone' => '222']]));

        $original = Mockery::mock(Group::class);
        $original->shouldReceive('getId')->andReturn(5);
        $original->shouldReceive('getContacts')->andReturn($groupContacts);
        $original->shouldReceive('getMeetings')->andReturn([$originalMeeting]);

        $updated = Mockery::mock(Group::class);
        $updated->shouldReceive('getId')->andReturn(5);
        $updated->shouldReceive('getContacts')->andReturn($groupContacts);
        $updated->shouldReceive('getMeetings')->andReturn([$updatedMeeting]);

        $this->tracker($logger)->onGroupChanged($updated, $original);
    }

    // ─── GDPR consent change ────────────────────────────────────────

    /**
     * @test
     */
    public function member_change_logs_a_consent_recorded_transition(): void
    {
        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldReceive('log')->once()
            ->with(AuditLogger::ACTION_UPDATE, AuditLogger::ENTITY_MEMBER, 42, PersonalDataFields::GDPR_ACCEPTED, 'Consent recorded');

        $original = $this->member(['isGdprAccepted' => false]);
        $updated  = $this->member(['isGdprAccepted' => true]);

        $this->tracker($logger)->onMemberChanged($updated, $original);
    }

    /**
     * @test
     */
    public function member_change_logs_email_and_mobile_updates(): void
    {
        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldReceive('log')->once()
            ->with(AuditLogger::ACTION_UPDATE, AuditLogger::ENTITY_MEMBER, 42, PersonalDataFields::PERSONAL_EMAIL, Mockery::type('string'));
        $logger->shouldReceive('log')->once()
            ->with(AuditLogger::ACTION_UPDATE, AuditLogger::ENTITY_MEMBER, 42, PersonalDataFields::MOBILE_NUMBER, Mockery::type('string'));

        $original = $this->member(['getPersonalEmail' => 'old@example.com', 'getMobileNumber' => '111']);
        $updated  = $this->member(['getPersonalEmail' => 'new@example.com', 'getMobileNumber' => '222']);

        $this->tracker($logger)->onMemberChanged($updated, $original);
    }

    /**
     * @test
     */
    public function member_change_with_no_personal_data_diff_logs_nothing(): void
    {
        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldNotReceive('log');

        $original = $this->member();
        $updated  = $this->member();

        $this->tracker($logger)->onMemberChanged($updated, $original);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function member(array $overrides = []): Member
    {
        $defaults = [
            'getId' => 42,
            'getPersonalEmail' => 'same@example.com',
            'getMobileNumber' => '07700 900000',
            'getResponderCertification' => ResponderCertification::None,
            'isGdprAccepted' => false,
        ];
        $data = array_merge($defaults, $overrides);

        $member = Mockery::mock(Member::class);
        foreach ($data as $method => $value) {
            $member->shouldReceive($method)->andReturn($value);
        }
        return $member;
    }

    // ─── admin form view tracking ───────────────────────────────────

    /**
     * @test
     */
    public function admin_form_view_is_logged_for_a_viewer_editing_a_member(): void
    {
        $this->grantView();

        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldReceive('logBatch')->once()
            ->with(AuditLogger::ACTION_VIEW, AuditLogger::ENTITY_MEMBER, 23, PersonalDataFields::ALL_FIELDS, Mockery::type('string'));

        $_GET['post'] = '23';
        $screen = (object) ['base' => 'post', 'post_type' => 'unity_member'];

        $tracker = $this->tracker($logger, ['POST_TYPE' => 'unity_member']);
        $tracker->onMemberAdminFormDisplayed($screen);
        // A second call in the same request is de-duped.
        $tracker->onMemberAdminFormDisplayed($screen);
    }

    /**
     * @test
     */
    public function admin_form_view_is_skipped_for_a_non_viewer(): void
    {
        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldNotReceive('logBatch');

        $_GET['post'] = '23';
        $screen = (object) ['base' => 'post', 'post_type' => 'unity_member'];

        $this->tracker($logger, ['POST_TYPE' => 'unity_member'])
            ->onMemberAdminFormDisplayed($screen);
    }

    /**
     * @test
     */
    public function admin_form_view_ignores_the_new_post_screen_and_other_screens(): void
    {
        $this->grantView();

        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldNotReceive('logBatch');

        $tracker = $this->tracker($logger, ['POST_TYPE' => 'unity_member']);

        // Wrong screen base.
        $tracker->onMemberAdminFormDisplayed((object) ['base' => 'edit', 'post_type' => 'unity_member']);
        // Wrong post type.
        $tracker->onMemberAdminFormDisplayed((object) ['base' => 'post', 'post_type' => 'post']);
        // New-post screen (no ?post).
        $_GET = [];
        $tracker->onMemberAdminFormDisplayed((object) ['base' => 'post', 'post_type' => 'unity_member']);
    }

    // ─── frontend ACF view tracking ─────────────────────────────────

    /**
     * @test
     */
    public function frontend_field_load_logs_a_personal_data_view(): void
    {
        $this->grantView();

        WP_Mock::userFunction('get_post_type')->with(50)->andReturn('unity_member');
        WP_Mock::userFunction('is_admin')->andReturn(false);

        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldReceive('log')->once()
            ->with(AuditLogger::ACTION_VIEW, AuditLogger::ENTITY_MEMBER, 50, 'personal-email', Mockery::type('string'));

        $tracker = $this->tracker(
            $logger,
            ['POST_TYPE' => 'unity_member'],
            ['field_email_key' => 'personal-email']
        );

        $field = ['key' => 'field_email_key'];
        // First load logs; second is de-duped.
        $this->assertSame('val', $tracker->onPersonalDataFieldLoaded('val', 50, $field));
        $this->assertSame('val', $tracker->onPersonalDataFieldLoaded('val', 50, $field));
    }

    /**
     * @test
     */
    public function frontend_field_load_skips_non_member_admin_and_unmapped_fields(): void
    {
        $this->grantView();

        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldNotReceive('log');

        $tracker = $this->tracker(
            $logger,
            ['POST_TYPE' => 'unity_member'],
            ['field_email_key' => 'personal-email']
        );

        // Non-integer post id: returned untouched before any WP calls.
        $this->assertSame('v', $tracker->onPersonalDataFieldLoaded('v', 'user_1', ['key' => 'field_email_key']));

        // Wrong post type.
        WP_Mock::userFunction('get_post_type')->with(50)->andReturn('post');
        $this->assertSame('v', $tracker->onPersonalDataFieldLoaded('v', 50, ['key' => 'field_email_key']));

        // Member, but in admin context.
        WP_Mock::userFunction('get_post_type')->with(51)->andReturn('unity_member');
        WP_Mock::userFunction('is_admin')->andReturn(true);
        $this->assertSame('v', $tracker->onPersonalDataFieldLoaded('v', 51, ['key' => 'field_email_key']));
    }

    /**
     * @test
     */
    public function frontend_field_load_skips_a_user_who_cannot_view_and_unmapped_keys(): void
    {
        // View capability withheld this time.
        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldNotReceive('log');

        WP_Mock::userFunction('get_post_type')->with(50)->andReturn('unity_member');
        WP_Mock::userFunction('is_admin')->andReturn(false);

        $tracker = $this->tracker(
            $logger,
            ['POST_TYPE' => 'unity_member'],
            ['field_email_key' => 'personal-email']
        );

        // Non-viewer: nothing logged.
        $this->assertSame('v', $tracker->onPersonalDataFieldLoaded('v', 50, ['key' => 'field_email_key']));

        // Now grant view but hand it an unmapped field key.
        $this->grantView();
        $this->assertSame('v', $tracker->onPersonalDataFieldLoaded('v', 50, ['key' => 'field_unknown']));
    }
}
