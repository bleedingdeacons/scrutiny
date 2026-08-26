<?php

declare(strict_types=1);

namespace Scrutiny\Fields;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Scrutiny\Admin\AuditLogAdmin;
use acf_field;
use function __;
use function acf_get_form_data;
use function acf_render_field_setting;
use function get_the_ID;
use function wp_enqueue_style;

/**
 * GdprAuditHistory ACF field type
 *
 * Adds a `gdpr_audit_history` field type to Advanced Custom Fields that
 * displays the GDPR audit trail Scrutiny has recorded for the record being
 * edited — dropped onto the member CPT's field group, it shows who viewed,
 * created, updated or called that member, and when.
 *
 * Display only: like ACF's own layout fields it renders no input, so there is
 * nothing to post and nothing is ever written to postmeta. It is excluded
 * from REST for the same reason.
 *
 * This class is deliberately thin. Everything that decides *what* is shown —
 * the query, the capability gate, the markup — lives in
 * {@see AuditHistoryRenderer}, which has no dependency on ACF and is unit
 * tested on its own. All this adds is ACF's settings UI and the resolution of
 * the post ID being edited.
 *
 * It must only be loaded from the `acf/include_field_types` action: the
 * `acf_field` base class does not exist before ACF fires it.
 */
class GdprAuditHistory extends acf_field
{
    /**
     * The ACF field type slug, as stored in field group definitions.
     */
    public const NAME = 'gdpr_audit_history';

    /**
     * The field holds no value, so there is nothing to expose over REST.
     *
     * Untyped to match the parent property.
     *
     * @var bool
     */
    public $show_in_rest = false;

    private AuditHistoryRenderer $renderer;

    public function __construct(AuditHistoryRenderer $renderer)
    {
        // Set before parent::__construct(), which calls initialize().
        $this->renderer = $renderer;

        parent::__construct();
    }

    /**
     * Describe the field type to ACF.
     *
     * @return void
     */
    public function initialize()
    {
        $this->name        = self::NAME;
        $this->label       = __('GDPR Audit History', 'scrutiny');
        $this->category    = 'layout';
        $this->description = __(
            'Displays the GDPR audit trail Scrutiny has recorded for the record being edited. Read-only: it stores no value of its own.',
            'scrutiny'
        );

        // Layout fields cannot be required and hold nothing to bind to.
        $this->supports = [
            'required' => false,
            'bindings' => false,
        ];

        $this->defaults = [
            'entity_type' => AuditHistoryRenderer::DEFAULT_ENTITY_TYPE,
            'max_entries' => AuditHistoryRenderer::DEFAULT_MAX_ENTRIES,
            'audit_action' => '',
            'show_ip'     => 0,
        ];
    }

    /**
     * Render the field on the edit screen.
     *
     * @param array<string, mixed> $field The field's settings.
     * @return void
     */
    public function render_field($field)
    {
        // AuditHistoryRenderer escapes everything it emits.
        echo $this->renderer->render(
            $this->resolvePostId(),
            [
                'entity_type' => (string) ($field['entity_type'] ?? AuditHistoryRenderer::DEFAULT_ENTITY_TYPE),
                'max_entries' => (int) ($field['max_entries'] ?? AuditHistoryRenderer::DEFAULT_MAX_ENTRIES),
                'action'      => (string) ($field['audit_action'] ?? ''),
                'show_ip'     => (bool) ($field['show_ip'] ?? false),
            ]
        );
    }

    /**
     * Render the field's settings in the field group editor.
     *
     * @param array<string, mixed> $field The field's settings.
     * @return void
     */
    public function render_field_settings($field)
    {
        acf_render_field_setting(
            $field,
            [
                'label'        => __('Record type', 'scrutiny'),
                'instructions' => __('Which kind of record this field is attached to. Entries are matched on this and the post ID.', 'scrutiny'),
                'type'         => 'select',
                'name'         => 'entity_type',
                'choices'      => AuditLogAdmin::ENTITY_TYPES,
            ]
        );

        acf_render_field_setting(
            $field,
            [
                'label'        => __('Entries to show', 'scrutiny'),
                'instructions' => __('How many of the most recent entries to display. The total is always shown alongside.', 'scrutiny'),
                'type'         => 'number',
                'name'         => 'max_entries',
                'min'          => 1,
                'max'          => AuditHistoryRenderer::MAX_ENTRIES_CEILING,
            ]
        );

        acf_render_field_setting(
            $field,
            [
                'label'        => __('Limit to action', 'scrutiny'),
                'instructions' => __('Show only one kind of entry, or leave empty for all of them.', 'scrutiny'),
                'type'         => 'select',
                'name'         => 'audit_action',
                'allow_null'   => 1,
                'choices'      => self::actionChoices(),
            ]
        );

        acf_render_field_setting(
            $field,
            [
                'label'        => __('Show IP addresses', 'scrutiny'),
                'instructions' => __('Adds the truncated IP address each entry was recorded from.', 'scrutiny'),
                'type'         => 'true_false',
                'name'         => 'show_ip',
                'ui'           => 1,
            ]
        );
    }

    /**
     * Enqueue the field's stylesheet.
     *
     * Hooked by the parent constructor onto `acf/input/admin_enqueue_scripts`,
     * which only fires on screens rendering ACF inputs.
     *
     * @return void
     */
    public function input_admin_enqueue_scripts()
    {
        if (!defined('SCRUTINY_PLUGIN_URL')) {
            return;
        }

        wp_enqueue_style(
            'scrutiny-audit-history',
            SCRUTINY_PLUGIN_URL . 'assets/css/audit-history.css',
            [],
            defined('SCRUTINY_VERSION') ? SCRUTINY_VERSION : false
        );
    }

    /**
     * The ID of the post being edited.
     *
     * ACF publishes it as form data on the post edit screen. That value is not
     * always a post ID — options pages and user/term forms put `options`,
     * `user_3` and the like there — so anything non-numeric falls through to
     * the loop's current post, and failing that to zero, which the renderer
     * treats as "not saved yet".
     */
    private function resolvePostId(): int
    {
        $formPostId = acf_get_form_data('post_id');

        if (is_numeric($formPostId) && (int) $formPostId > 0) {
            return (int) $formPostId;
        }

        $currentId = get_the_ID();

        return is_int($currentId) && $currentId > 0 ? $currentId : 0;
    }

    /**
     * The action filter's choices, keyed as the audit log stores them.
     *
     * @return array<string, string>
     */
    private static function actionChoices(): array
    {
        $choices = [];
        foreach (AuditLogAdmin::ACTION_TYPES as $action) {
            $choices[$action] = ucfirst($action);
        }

        return $choices;
    }
}
