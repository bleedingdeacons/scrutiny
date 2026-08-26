<?php

declare(strict_types=1);

namespace Scrutiny\Audit;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Scrutiny\Audit\Interfaces\AuditLogger;
use Scrutiny\Privacy\PersonalDataFields;
use Scrutiny\Privacy\PersonalDataPolicy;

use Unity\Core\Interfaces\Configuration;
use Unity\Groups\Interfaces\Group;
use Unity\Groups\Interfaces\GroupRepository;
use Unity\Members\Interfaces\Member;
use Unity\Positions\Interfaces\PositionRepository;
use function add_action;
use function add_filter;
use function get_post_type;
use function is_admin;

/**
 * Audit Tracker
 *
 * Hooks into Unity's member and group lifecycle events and ACF field loading to automatically log
 * creation, updates, viewing, and deletion of personal data fields.
 *
 * View events are only logged when the current user holds the
 * `scrutiny_view_personal_data` capability — only users who can
 * actually see the unobscured values are tracked, since users
 * without the capability only ever see masked placeholders.
 *
 * Three service roles are tracked alongside the personal data: the member's
 * home group, their intergroup position, and whether they are their home
 * group's GSR. None is personal data — each names a public entity rather
 * than the member — so their entries record the group or position by name,
 * rather than the opaque "Value changed" used for the fields that are.
 * Assignments are logged at creation, changes and removals as they happen,
 * and the standing roles once more when the member is deleted.
 *
 * Listens to:
 *   - current_screen             (fired when admin screen loads - used for admin form view tracking)
 *   - acf/load_value             (fired when ACF loads a field value - used for frontend view tracking)
 *   - unity/member_created       (fired by MemberRepository when a member is inserted)
 *   - unity/member_changing      (fired by MemberChangeTracker when member fields change)
 *   - unity/member_deleted       (fired by MemberChangeTracker when a member is trashed or deleted)
 *   - unity/group_changing       (fired by GroupChangeTracker when group fields change)
 *   - unity/group_deleted        (fired by GroupChangeTracker when a group is trashed or deleted)
 *   - unity/group_hidden         (fired by GroupChangeTracker when a group is set to private)
 *   - unity/member_import       (fired by Reconcile when members are imported from a spreadsheet)
 *   - unity/member_export       (fired by Reconcile when members are exported to a spreadsheet)
 *   - unity/group_import        (fired by Reconcile when groups are imported from a spreadsheet)
 *   - unity/group_export        (fired by Reconcile when groups are exported to a spreadsheet)
 *   - unity/position_import     (fired by Reconcile when positions are imported from a spreadsheet)
 *   - unity/position_export     (fired by Reconcile when positions are exported to a spreadsheet)
 */
class AuditTracker
{
    /**
     * Longest group or position name an audit detail will carry.
     *
     * The detail column is VARCHAR(255), and the wordiest string built here
     * is "Changed from <old> to <new>" — two names plus 17 characters — so
     * capping each name at 100 keeps every entry comfortably inside it.
     */
    private const MAX_NAME_LENGTH = 100;

    private AuditLogger $logger;
    private PersonalDataPolicy $policy;
    private GroupRepository $groupRepository;
    private PositionRepository $positionRepository;

    /**
     * Track which member fields have been logged in this request to prevent duplicates
     * Key format: "{post_id}_{field_name}"
     * @var array<string, bool>
     */
    private array $loggedMemberViews = [];

    /** @var array<string, mixed> */
    private readonly array $member_config;

    /**
     * Map from ACF field keys to logical personal data field names,
     * built at runtime from configuration.
     *
     * @var array<string, string>
     */
    private readonly array $acfFieldMap;

    public function __construct(
        Configuration $configuration,
        AuditLogger $logger,
        PersonalDataPolicy $policy,
        GroupRepository $groupRepository,
        PositionRepository $positionRepository
    ) {
        $this->logger = $logger;
        $this->policy = $policy;
        $this->groupRepository = $groupRepository;
        $this->positionRepository = $positionRepository;

        // getConfig() returns null when no Member config is registered; an
        // empty map is the same thing to every reader below.
        $this->member_config = $configuration->getConfig(Member::class) ?? [];

        // Build the ACF field map from configuration and PersonalDataFields::CONFIG_KEY_MAP
        $map = [];
        foreach (PersonalDataFields::CONFIG_KEY_MAP as $configKey => $logicalName) {
            if (isset($this->member_config[$configKey])) {
                $map[$this->member_config[$configKey]] = $logicalName;
            }
        }
        $this->acfFieldMap = $map;

        // Log when a member edit form is displayed in admin
        add_action('current_screen', [$this, 'onMemberAdminFormDisplayed'], 10, 1);

        // Log when personal data fields are accessed via ACF on frontend
        add_filter('acf/load_value', [$this, 'onPersonalDataFieldLoaded'], 10, 3);

        // Log personal data creation when a member is inserted
        add_action('unity/member_created', [$this, 'onMemberCreated'], 10, 1);

        // Log personal data changes when a member is updated
        add_action('unity/member_changing', [$this, 'onMemberChanged'], 10, 2);

        // Log contact data changes when a group is updated
        add_action('unity/group_changing', [$this, 'onGroupChanged'], 10, 2);

        // Log personal data deletion when a member is deleted or trashed
        add_action('unity/member_deleted', [$this, 'onMemberDeleted'], 10, 2);

        // Log contact data deletion when a group is deleted or trashed
        add_action('unity/group_deleted', [$this, 'onGroupDeleted'], 10, 2);

        // Log when a group is hidden (set to private)
        add_action('unity/group_hidden', [$this, 'onGroupHidden'], 10, 2);

        // Log import/export events fired by Reconcile
        add_action('unity/member_import', [$this, 'onMemberImport'], 10, 2);
        add_action('unity/member_export', [$this, 'onMemberExport'], 10, 2);
        add_action('unity/group_import', [$this, 'onGroupImport'], 10, 2);
        add_action('unity/group_export', [$this, 'onGroupExport'], 10, 2);
        add_action('unity/position_import', [$this, 'onPositionImport'], 10, 2);
        add_action('unity/position_export', [$this, 'onPositionExport'], 10, 2);
    }

    /**
     * Log when a member edit form is displayed in the WordPress admin
     *
     * Triggered on 'current_screen' when the admin screen is set up.
     * Logs when the edit screen for a member post is accessed.
     *
     * @param \WP_Screen $screen The current screen object
     * @return void
     */
    public function onMemberAdminFormDisplayed($screen): void
    {
        // Check if we're on a post edit screen
        if (!$screen || $screen->base !== 'post') {
            return;
        }

        // Verify it's a member post type
        if ($screen->post_type !== $this->member_config['POST_TYPE']) {
            return;
        }

        // Get the post ID if we're editing an existing post
        $postId = isset($_GET['post']) ? (int) $_GET['post'] : 0;

        // Only log for existing posts (not new post screen)
        if ($postId <= 0) {
            return;
        }

        // Only log views for users who can actually see the unobscured personal data
        if (!$this->policy->currentUserCanView()) {
            return;
        }

        // Create unique key for this post
        $logKey = 'admin_' . $postId;

        // Prevent duplicate logging in the same request
        if (isset($this->loggedMemberViews[$logKey])) {
            return;
        }

        // Mark this member as logged for this request
        $this->loggedMemberViews[$logKey] = true;

        // Log viewing of all personal data fields
        $this->logger->logBatch(
            AuditLogger::ACTION_VIEW,
            AuditLogger::ENTITY_MEMBER,
            $postId,
            PersonalDataFields::ALL_FIELDS,
            'Member edit form displayed in admin'
        );
    }

    /**
     * Log when a personal data field is loaded via ACF
     *
     * Triggered on 'acf/load_value' when ACF loads a field value.
     * Only logs personal data fields on member posts when viewed on the frontend.
     * Prevents duplicate logging per field per post per request.
     *
     * @param mixed $value The field value
     * @param int|string $postId The post ID
     * @param array<string, mixed> $field The field array
     * @return mixed The unchanged field value
     */
    public function onPersonalDataFieldLoaded($value, $postId, array $field)
    {
        // Only track integer post IDs (not user_123, term_456, etc.)
        if (!is_int($postId) || $postId <= 0) {
            return $value;
        }

        // Verify it's a member post type
        if (get_post_type($postId) !== $this->member_config['POST_TYPE']) {
            return $value;
        }

        // Only log on frontend, not in admin
        if (is_admin()) {
            return $value;
        }

        // Only log views for users who can actually see the unobscured personal data
        if (!$this->policy->currentUserCanView()) {
            return $value;
        }

        // Check if this is a personal data field
        $fieldKey = $field['key'] ?? '';
        if (!isset($this->acfFieldMap[$fieldKey])) {
            return $value;
        }

        // Get the logical field name
        $fieldName = $this->acfFieldMap[$fieldKey];

        // Create unique key for this field + post combination
        $logKey = $postId . '_' . $fieldName;

        // Prevent duplicate logging in the same request
        if (isset($this->loggedMemberViews[$logKey])) {
            return $value;
        }

        // Mark this field as logged for this request
        $this->loggedMemberViews[$logKey] = true;

        // Log the field view
        $this->logger->log(
            AuditLogger::ACTION_VIEW,
            AuditLogger::ENTITY_MEMBER,
            $postId,
            $fieldName,
            'Personal data field accessed'
        );

        return $value;
    }

    /**
     * Log personal data creation when a member is inserted
     *
     * Triggered by the unity/member_created hook fired from MemberRepository
     * when a new member is persisted. A single entry is recorded against the
     * sentinel "all fields" field name — at creation time every personal-data
     * field is, by definition, being written, so per-field rows would just
     * spam the audit log with no extra information.
     *
     * A member created straight into a home group or an intergroup position
     * gets one further entry per role. That is not the spam the sentinel
     * guards against: it is at most two rows, written only when the role was
     * actually filled, and without them the log would show a position being
     * vacated by someone it never recorded taking it.
     *
     * @param Member $member The freshly created member
     * @return void
     */
    public function onMemberCreated(Member $member): void
    {
        $memberId = $member->getId();

        $this->logger->log(
            AuditLogger::ACTION_CREATE,
            AuditLogger::ENTITY_MEMBER,
            $memberId,
            PersonalDataFields::ALL_FIELDS_SENTINEL,
            'Member created'
        );

        [$homeGroup, $position, $gsr] = $this->serviceRoleNames($member);

        if ($homeGroup !== '') {
            $this->logger->log(
                AuditLogger::ACTION_CREATE,
                AuditLogger::ENTITY_MEMBER,
                $memberId,
                PersonalDataFields::HOME_GROUP,
                self::assignmentDetail('', $homeGroup)
            );
        }

        if ($position !== '') {
            $this->logger->log(
                AuditLogger::ACTION_CREATE,
                AuditLogger::ENTITY_MEMBER,
                $memberId,
                PersonalDataFields::INTERGROUP_POSITION,
                self::assignmentDetail('', $position)
            );
        }

        if ($gsr !== '') {
            $this->logger->log(
                AuditLogger::ACTION_CREATE,
                AuditLogger::ENTITY_MEMBER,
                $memberId,
                PersonalDataFields::GSR,
                self::assignmentDetail('', $gsr)
            );
        }
    }

    /**
     * Log changes to tracked fields when a member is updated
     *
     * Compares original and updated member to detect which tracked fields
     * changed, and logs each change individually. Personal-data fields
     * record only that the value changed; the responder-certification
     * stage records the new value, since it is a service status rather
     * than personal data.
     *
     * @param Member $updatedMember The member after changes
     * @param Member $originalMember The member before changes
     * @return void
     */
    public function onMemberChanged(Member $updatedMember, Member $originalMember): void
    {
        $memberId = $updatedMember->getId();

        if ($originalMember->getPersonalEmail() !== $updatedMember->getPersonalEmail()) {
            $this->logger->log(
                AuditLogger::ACTION_UPDATE,
                AuditLogger::ENTITY_MEMBER,
                $memberId,
                PersonalDataFields::PERSONAL_EMAIL,
                'Value changed'
            );
        }

        if ($originalMember->getMobileNumber() !== $updatedMember->getMobileNumber()) {
            $this->logger->log(
                AuditLogger::ACTION_UPDATE,
                AuditLogger::ENTITY_MEMBER,
                $memberId,
                PersonalDataFields::MOBILE_NUMBER,
                'Value changed'
            );
        }

        // The certification stage is a service status, not personal data, so
        // the new value is recorded outright rather than the opaque
        // "Value changed" used for the fields above. Who cleared a responder
        // for the helpline — and to what stage — is the point of the entry.
        $updatedCertification = $updatedMember->getResponderCertification();
        if ($originalMember->getResponderCertification() !== $updatedCertification) {
            $this->logger->log(
                AuditLogger::ACTION_UPDATE,
                AuditLogger::ENTITY_MEMBER,
                $memberId,
                PersonalDataFields::RESPONDER_CERTIFICATION,
                'Changed to ' . $updatedCertification->label()
            );
        }

        $this->logServiceRoleChanges($memberId, $originalMember, $updatedMember);

        $this->logGdprChanges($memberId, $originalMember, $updatedMember);
    }

    /**
     * Log assignment, reassignment and removal of a member's service roles.
     *
     * Home group and intergroup position are both public service roles rather
     * than personal data, so — like the responder-certification stage — these
     * entries name the group or position outright. An audit log recording only
     * that "a position changed" answers nothing an auditor would ask of it.
     *
     * One entry per role, written only when that role's ID actually changed,
     * so an edit touching neither is silent.
     *
     * @param int    $memberId       The member post ID
     * @param Member $originalMember The member before changes
     * @param Member $updatedMember  The member after changes
     * @return void
     */
    private function logServiceRoleChanges(int $memberId, Member $originalMember, Member $updatedMember): void
    {
        if ($originalMember->getHomeGroup() !== $updatedMember->getHomeGroup()) {
            $this->logger->log(
                AuditLogger::ACTION_UPDATE,
                AuditLogger::ENTITY_MEMBER,
                $memberId,
                PersonalDataFields::HOME_GROUP,
                self::assignmentDetail(
                    $this->groupName($originalMember->getHomeGroup()),
                    $this->groupName($updatedMember->getHomeGroup())
                )
            );
        }

        if ($originalMember->getIntergroupPosition() !== $updatedMember->getIntergroupPosition()) {
            $this->logger->log(
                AuditLogger::ACTION_UPDATE,
                AuditLogger::ENTITY_MEMBER,
                $memberId,
                PersonalDataFields::INTERGROUP_POSITION,
                self::assignmentDetail(
                    $this->positionName($originalMember->getIntergroupPosition()),
                    $this->positionName($updatedMember->getIntergroupPosition())
                )
            );
        }

        // GSR is compared as the group the member is GSR *for*, which folds
        // three transitions into one test: taking the role, giving it up, and
        // carrying it to a new home group. That last one changes no flag, so
        // comparing isGSR() alone would miss it and leave the log showing a
        // member moving group while apparently still GSR of the old one.
        $originalGsr = $this->gsrRole($originalMember);
        $updatedGsr = $this->gsrRole($updatedMember);

        if ($originalGsr !== $updatedGsr) {
            $this->logger->log(
                AuditLogger::ACTION_UPDATE,
                AuditLogger::ENTITY_MEMBER,
                $memberId,
                PersonalDataFields::GSR,
                self::assignmentDetail($originalGsr, $updatedGsr)
            );
        }
    }

    /**
     * Phrase a service-role transition from the names either side of it.
     *
     * Each form says in words what happened, rather than leaning on the
     * action and field columns beside it to supply the sense:
     *
     *     Assigned to New              taken
     *     Changed from Old to New      moved
     *     Removed from Old             given up
     *
     * An empty name means "no role". Both empty cannot reach here — every
     * caller logs only once the two sides differ, and only an unfilled role
     * resolves to an empty name.
     *
     * @param string $from The role held before the change ('' when none)
     * @param string $to   The role held after the change ('' when none)
     * @return string The detail string to store
     */
    private static function assignmentDetail(string $from, string $to): string
    {
        if ($from === '') {
            return 'Assigned to ' . $to;
        }

        if ($to === '') {
            return 'Removed from ' . $from;
        }

        return 'Changed from ' . $from . ' to ' . $to;
    }

    /**
     * Resolve each of a member's service roles to a display name.
     *
     * @param Member $member The member to read
     * @return array{0: string, 1: string, 2: string} Home group, position and
     *                                                GSR group, each '' when
     *                                                the role is unfilled
     */
    private function serviceRoleNames(Member $member): array
    {
        return [
            $this->groupName($member->getHomeGroup()),
            $this->positionName($member->getIntergroupPosition()),
            $this->gsrRole($member),
        ];
    }

    /**
     * The group a member is GSR for, or '' when they are not a GSR.
     *
     * A GSR flag with no home group behind it is meaningless — the role is
     * held on behalf of that group — but setting or clearing it is still a
     * change, and dropping it silently is the gap this tracking exists to
     * close. Such entries name the absence instead.
     *
     * @param Member $member The member to read
     * @return string The group name, or '' when the member is not a GSR
     */
    private function gsrRole(Member $member): string
    {
        if (!$member->isGSR()) {
            return '';
        }

        $group = $this->groupName($member->getHomeGroup());

        return $group !== '' ? $group : '(no home group)';
    }

    /**
     * Resolve a group ID to the name to record for it.
     *
     * @param int $groupId The group post ID, 0 when the member has no home group
     * @return string The group's title, or '' when there is no group
     */
    private function groupName(int $groupId): string
    {
        if ($groupId <= 0) {
            return '';
        }

        $group = $this->groupRepository->findById($groupId);

        return self::nameOrId($group !== null ? $group->getTitle() : '', $groupId);
    }

    /**
     * Resolve an intergroup position ID to the name to record for it.
     *
     * Uses the long name, which is what Amber and the position views display.
     *
     * @param int $positionId The position post ID, 0 when the member holds none
     * @return string The position's long name, or '' when there is no position
     */
    private function positionName(int $positionId): string
    {
        if ($positionId <= 0) {
            return '';
        }

        $position = $this->positionRepository->findById($positionId);

        return self::nameOrId($position !== null ? $position->getLongName() : '', $positionId);
    }

    /**
     * Fall back to "#<id>" when a record has no usable name.
     *
     * The record may have been deleted since, or saved without a title. An
     * entry naming an ID is still traceable; one naming nothing is not.
     * Names are capped at {@see self::MAX_NAME_LENGTH} so the detail fits
     * its column.
     *
     * @param string $name The resolved name, possibly empty
     * @param int    $id   The record ID to fall back to
     * @return string A non-empty label for the record
     */
    private static function nameOrId(string $name, int $id): string
    {
        $name = trim($name);

        if ($name === '') {
            return '#' . $id;
        }

        if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            return mb_substr($name, 0, self::MAX_NAME_LENGTH - 1) . '…';
        }

        return $name;
    }

    /**
     * Log changes to GDPR compliance fields when a member is updated.
     *
     * The acceptance flag transitions get a more descriptive detail string
     * (`Consent recorded` / `Consent revoked`) than the catch-all
     * `Value changed`, since whether consent was given or withdrawn is
     * the single most important fact in any GDPR audit. Other GDPR
     * fields (timestamp, version, method, statement) fall back to the
     * generic message — they're metadata about the consent record and
     * an auditor will already have the entity_id + logged_at to
     * correlate them with the corresponding accept/revoke entry.
     *
     * @param int    $memberId       The member post ID
     * @param Member $originalMember The member before changes
     * @param Member $updatedMember  The member after changes
     * @return void
     */
    private function logGdprChanges(int $memberId, Member $originalMember, Member $updatedMember): void
    {
        if ($originalMember->isGdprAccepted() !== $updatedMember->isGdprAccepted()) {
            $this->logger->log(
                AuditLogger::ACTION_UPDATE,
                AuditLogger::ENTITY_MEMBER,
                $memberId,
                PersonalDataFields::GDPR_ACCEPTED,
                $updatedMember->isGdprAccepted() ? 'Consent recorded' : 'Consent revoked'
            );
        }

        // We only log the change of acceptance as all fields are recorded against the member.
        // Logging out anymore will only just spam the audit log.

//        if ($originalMember->getGdprAcceptedAt() !== $updatedMember->getGdprAcceptedAt()) {
//            $this->logger->log(
//                AuditLogger::ACTION_UPDATE,
//                AuditLogger::ENTITY_MEMBER,
//                $memberId,
//                PersonalDataFields::GDPR_ACCEPTED_AT,
//                'Value changed'
//            );
//        }
//
//        if ($originalMember->getGdprAcceptanceVersion() !== $updatedMember->getGdprAcceptanceVersion()) {
//            $this->logger->log(
//                AuditLogger::ACTION_UPDATE,
//                AuditLogger::ENTITY_MEMBER,
//                $memberId,
//                PersonalDataFields::GDPR_ACCEPTANCE_VERSION,
//                'Value changed'
//            );
//        }
//
//        if ($originalMember->getGdprAcceptanceMethod() !== $updatedMember->getGdprAcceptanceMethod()) {
//            $this->logger->log(
//                AuditLogger::ACTION_UPDATE,
//                AuditLogger::ENTITY_MEMBER,
//                $memberId,
//                PersonalDataFields::GDPR_ACCEPTANCE_METHOD,
//                'Value changed'
//            );
//        }
//
//        if ($originalMember->getGdprAcceptanceStatement() !== $updatedMember->getGdprAcceptanceStatement()) {
//            $this->logger->log(
//                AuditLogger::ACTION_UPDATE,
//                AuditLogger::ENTITY_MEMBER,
//                $memberId,
//                PersonalDataFields::GDPR_ACCEPTANCE_STATEMENT,
//                'Value changed'
//            );
//        }
    }

    /**
     * Log changes to contact data when a group is updated
     *
     * Compares the original and updated group's contacts, and also compares
     * contacts on each meeting within the group. Logs each change individually.
     *
     * @param Group $updatedGroup The group after changes
     * @param Group $originalGroup The group before changes
     * @return void
     */
    public function onGroupChanged(Group $updatedGroup, Group $originalGroup): void
    {
        $this->logContactChanges(
            AuditLogger::ENTITY_GROUP,
            $updatedGroup->getId(),
            $originalGroup->getContacts(),
            $updatedGroup->getContacts(),
            PersonalDataFields::GROUP_CONTACT_NAME,
            PersonalDataFields::GROUP_CONTACT_EMAIL,
            PersonalDataFields::GROUP_CONTACT_PHONE
        );

        $this->logMeetingContactChanges($originalGroup, $updatedGroup);
    }

    /**
     * Compare contacts on meetings within the original and updated group,
     * logging changes for each meeting whose contacts differ.
     *
     * Meetings are matched by ID. Meetings that only appear in the updated group
     * are treated as newly added; meetings that only appear in the original are ignored
     * here (deletion is handled elsewhere).
     *
     * @param Group $originalGroup The group before changes
     * @param Group $updatedGroup The group after changes
     * @return void
     */
    private function logMeetingContactChanges(Group $originalGroup, Group $updatedGroup): void
    {
        $originalMeetings = [];
        foreach ($originalGroup->getMeetings() as $meeting) {
            $originalMeetings[$meeting->getId()] = $meeting;
        }

        foreach ($updatedGroup->getMeetings() as $updatedMeeting) {
            $meetingId = $updatedMeeting->getId();
            $originalContacts = isset($originalMeetings[$meetingId])
                ? $originalMeetings[$meetingId]->getContacts()
                : [];

            $this->logContactChanges(
                AuditLogger::ENTITY_MEETING,
                $meetingId,
                $originalContacts,
                $updatedMeeting->getContacts(),
                PersonalDataFields::MEETING_CONTACT_NAME,
                PersonalDataFields::MEETING_CONTACT_EMAIL,
                PersonalDataFields::MEETING_CONTACT_PHONE
            );
        }
    }

    /**
     * Compare two arrays of contacts and log an update for each field type
     * (name, email, phone) that differs between them.
     *
     * Contacts are normalised into sorted "name|email|phone" keys so that
     * reordering alone does not trigger a log entry.
     *
     * @param string $entityType The entity type constant (group or meeting)
     * @param int    $entityId   The entity post ID
     * @param array<int, mixed>  $originalContacts Contacts before the change
     * @param array<int, mixed>  $updatedContacts  Contacts after the change
     * @param string $nameField  The PersonalDataFields constant for the name field
     * @param string $emailField The PersonalDataFields constant for the email field
     * @param string $phoneField The PersonalDataFields constant for the phone field
     * @return void
     */
    private function logContactChanges(
        string $entityType,
        int $entityId,
        array $originalContacts,
        array $updatedContacts,
        string $nameField,
        string $emailField,
        string $phoneField
    ): void {
        $normalize = static function (array $contacts): array {
            $names = [];
            $emails = [];
            $phones = [];
            foreach ($contacts as $contact) {
                $names[] = $contact->getName();
                $emails[] = $contact->getEmail();
                $phones[] = $contact->getPhone();
            }
            sort($names);
            sort($emails);
            sort($phones);
            return ['names' => $names, 'emails' => $emails, 'phones' => $phones];
        };

        $original = $normalize($originalContacts);
        $updated = $normalize($updatedContacts);

        if ($original['names'] !== $updated['names']) {
            $this->logger->log(
                AuditLogger::ACTION_UPDATE,
                $entityType,
                $entityId,
                $nameField,
                'Contact name changed'
            );
        }

        if ($original['emails'] !== $updated['emails']) {
            $this->logger->log(
                AuditLogger::ACTION_UPDATE,
                $entityType,
                $entityId,
                $emailField,
                'Contact email changed'
            );
        }

        if ($original['phones'] !== $updated['phones']) {
            $this->logger->log(
                AuditLogger::ACTION_UPDATE,
                $entityType,
                $entityId,
                $phoneField,
                'Contact phone changed'
            );
        }
    }

    /**
     * Log personal data deletion when a member is deleted or trashed
     *
     * Triggered by the unity/member_deleted hook fired from MemberChangeTracker.
     *
     * Any service role the member still held is recorded as vacated, so the
     * position's history closes properly rather than trailing off at the last
     * assignment. These read the same as an ordinary removal — the row's
     * own action column is what marks them as a deletion. The hook passes
     * null when the member could no longer be read, and there is then
     * nothing to name.
     *
     * @param int $postId The post ID being deleted or trashed
     * @param Member|null $member The member at the time of deletion (may be null)
     * @return void
     */
    public function onMemberDeleted(int $postId, ?Member $member = null): void
    {
        $this->logger->logBatch(
            AuditLogger::ACTION_DELETE,
            AuditLogger::ENTITY_MEMBER,
            $postId,
            array_merge(PersonalDataFields::ALL_FIELDS, PersonalDataFields::GDPR_FIELDS),
            'Member deleted'
        );

        if ($member === null) {
            return;
        }

        [$homeGroup, $position, $gsr] = $this->serviceRoleNames($member);

        if ($homeGroup !== '') {
            $this->logger->log(
                AuditLogger::ACTION_DELETE,
                AuditLogger::ENTITY_MEMBER,
                $postId,
                PersonalDataFields::HOME_GROUP,
                self::assignmentDetail($homeGroup, '')
            );
        }

        if ($position !== '') {
            $this->logger->log(
                AuditLogger::ACTION_DELETE,
                AuditLogger::ENTITY_MEMBER,
                $postId,
                PersonalDataFields::INTERGROUP_POSITION,
                self::assignmentDetail($position, '')
            );
        }

        if ($gsr !== '') {
            $this->logger->log(
                AuditLogger::ACTION_DELETE,
                AuditLogger::ENTITY_MEMBER,
                $postId,
                PersonalDataFields::GSR,
                self::assignmentDetail($gsr, '')
            );
        }
    }

    /**
     * Log contact data deletion when a group is deleted or trashed
     *
     * Triggered by the unity/group_deleted hook fired from GroupChangeTracker.
     *
     * @param int $postId The post ID being deleted or trashed
     * @param Group|null $group The group at the time of deletion (may be null)
     * @return void
     */
    public function onGroupDeleted(int $postId, ?Group $group = null): void
    {
        $this->logger->logBatch(
            AuditLogger::ACTION_DELETE,
            AuditLogger::ENTITY_GROUP,
            $postId,
            PersonalDataFields::GROUP_CONTACT_FIELDS,
            'Group deleted'
        );
    }

    /**
     * Log when a group is hidden (post status set to private)
     *
     * Triggered by the unity/group_hidden hook fired from GroupChangeTracker.
     *
     * @param int $postId The post ID that was hidden
     * @param Group|null $group The group at the time of hiding (may be null)
     * @return void
     */
    public function onGroupHidden(int $postId, ?Group $group = null): void
    {
        $this->logger->logBatch(
            AuditLogger::ACTION_UPDATE,
            AuditLogger::ENTITY_GROUP,
            $postId,
            PersonalDataFields::GROUP_CONTACT_FIELDS,
            'Group hidden (set to private)'
        );
    }

    // ── Import / Export hooks (fired by Reconcile) ─────────────────────

    /**
     * Log when members are imported from a spreadsheet.
     *
     * Triggered by the unity/member_import hook.
     *
     * @param int    $count      Number of members imported
     * @param string $fieldNames Comma-separated personal data field names
     */
    public function onMemberImport(int $count, string $fieldNames): void
    {
        $this->logger->log(
            AuditLogger::ACTION_IMPORT,
            AuditLogger::ENTITY_MEMBER,
            0,
            $fieldNames,
            $count . ' member(s) imported from spreadsheet.'
        );
    }

    /**
     * Log when members are exported to a spreadsheet.
     *
     * Triggered by the unity/member_export hook.
     *
     * @param int    $count      Number of members exported
     * @param string $fieldNames Comma-separated personal data field names
     */
    public function onMemberExport(int $count, string $fieldNames): void
    {
        $this->logger->log(
            AuditLogger::ACTION_EXPORT,
            AuditLogger::ENTITY_MEMBER,
            0,
            $fieldNames,
            $count . ' member(s) exported to spreadsheet.'
        );
    }

    /**
     * Log when groups are imported from a spreadsheet.
     *
     * Triggered by the unity/group_import hook.
     *
     * @param int    $count      Number of groups imported
     * @param string $fieldNames Comma-separated personal data field names
     */
    public function onGroupImport(int $count, string $fieldNames): void
    {
        $this->logger->log(
            AuditLogger::ACTION_IMPORT,
            AuditLogger::ENTITY_GROUP,
            0,
            $fieldNames,
            $count . ' group(s) imported from spreadsheet.'
        );
    }

    /**
     * Log when groups are exported to a spreadsheet.
     *
     * Triggered by the unity/group_export hook.
     *
     * @param int    $count      Number of groups exported
     * @param string $fieldNames Comma-separated personal data field names
     */
    public function onGroupExport(int $count, string $fieldNames): void
    {
        $this->logger->log(
            AuditLogger::ACTION_EXPORT,
            AuditLogger::ENTITY_GROUP,
            0,
            $fieldNames,
            $count . ' group(s) exported to spreadsheet.'
        );
    }

    /**
     * Log when positions are imported from a spreadsheet.
     *
     * Triggered by the unity/position_import hook.
     *
     * @param int    $count      Number of positions imported
     * @param string $fieldNames Comma-separated personal data field names
     */
    public function onPositionImport(int $count, string $fieldNames): void
    {
        $this->logger->log(
            AuditLogger::ACTION_IMPORT,
            AuditLogger::ENTITY_POSITION,
            0,
            $fieldNames,
            $count . ' position(s) imported from spreadsheet.'
        );
    }

    /**
     * Log when positions are exported to a spreadsheet.
     *
     * Triggered by the unity/position_export hook.
     *
     * @param int    $count      Number of positions exported
     * @param string $fieldNames Comma-separated personal data field names
     */
    public function onPositionExport(int $count, string $fieldNames): void
    {
        $this->logger->log(
            AuditLogger::ACTION_EXPORT,
            AuditLogger::ENTITY_POSITION,
            0,
            $fieldNames,
            $count . ' position(s) exported to spreadsheet.'
        );
    }
}
