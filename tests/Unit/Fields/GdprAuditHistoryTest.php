<?php

declare(strict_types=1);

namespace Scrutiny\Tests\Unit\Fields;

use BleedingDeacons\WpMocks\WpState;
use Brain\Monkey\Functions;
use Scrutiny\Admin\AuditLogAdmin;
use Scrutiny\Audit\Interfaces\AuditRepository;
use Scrutiny\Fields\AuditHistoryRenderer;
use Scrutiny\Fields\GdprAuditHistory;
use Scrutiny\Tests\TestCase;

/**
 * Tests for the GdprAuditHistory ACF field type.
 *
 * The field type is an adapter, so this covers the adapting: the type
 * definition ACF stores in field groups, the settings it offers, the
 * translation of those settings into renderer options, and the resolution of
 * the post ID being edited.
 *
 * The base class and the three ACF functions come from tests/stubs/acf.php —
 * the same file PHPStan reads — so this suite runs with no ACF installed.
 *
 * @covers \Scrutiny\Fields\GdprAuditHistory
 */
class GdprAuditHistoryTest extends TestCase
{
    private GdprAuditHistory $field;

    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['scrutiny_test_capabilities']       = [AuditLogAdmin::CAPABILITY => true];
        $GLOBALS['scrutiny_test_acf_field_settings'] = [];
        $GLOBALS['scrutiny_test_acf_form_data']      = [];

        $repository = $this->createMock(AuditRepository::class);
        $repository->method('find')->willReturn([]);
        $repository->method('count')->willReturn(0);

        $this->field = new GdprAuditHistory(new AuditHistoryRenderer($repository));
    }

    protected function tearDown(): void
    {
        $GLOBALS['scrutiny_test_capabilities']  = [];
        $GLOBALS['scrutiny_test_acf_form_data'] = [];
        parent::tearDown();
    }

    // ──────────────────────────────────────────────
    //  Type definition
    // ──────────────────────────────────────────────

    /** @test */
    public function it_registers_under_the_documented_type_slug(): void
    {
        // The slug is written into every field group that uses the field.
        // Renaming it orphans them, so it is pinned here as well as in the
        // constant.
        $this->assertSame('gdpr_audit_history', GdprAuditHistory::NAME);
        $this->assertSame('gdpr_audit_history', $this->field->name);
    }

    /** @test */
    public function it_declares_itself_a_layout_field_that_stores_nothing(): void
    {
        // Display-only, exactly like ACF's own message/tab fields: nothing to
        // require, nothing to bind, nothing to expose over REST.
        $this->assertSame('layout', $this->field->category);
        $this->assertFalse($this->field->supports['required']);
        $this->assertFalse($this->field->supports['bindings']);
        $this->assertFalse($this->field->show_in_rest);
    }

    /** @test */
    public function it_defaults_to_the_member_record_type(): void
    {
        $this->assertSame(
            AuditHistoryRenderer::DEFAULT_ENTITY_TYPE,
            $this->field->defaults['entity_type']
        );
        $this->assertSame(
            AuditHistoryRenderer::DEFAULT_MAX_ENTRIES,
            $this->field->defaults['max_entries']
        );
        $this->assertSame('', $this->field->defaults['audit_action']);
    }

    // ──────────────────────────────────────────────
    //  Settings UI
    // ──────────────────────────────────────────────

    /** @test */
    public function it_offers_a_setting_for_each_default(): void
    {
        $this->field->render_field_settings($this->field->defaults);

        $names = array_column($GLOBALS['scrutiny_test_acf_field_settings'], 'name');

        // Every default must be reachable from the field group editor, or it
        // is a value nobody can change.
        $this->assertSame(
            ['entity_type', 'max_entries', 'audit_action', 'show_ip'],
            $names
        );
    }

    /** @test */
    public function its_record_type_choices_match_the_audit_log_page(): void
    {
        $this->field->render_field_settings($this->field->defaults);

        $settings = array_column($GLOBALS['scrutiny_test_acf_field_settings'], null, 'name');

        $this->assertSame(AuditLogAdmin::ENTITY_TYPES, $settings['entity_type']['choices']);
    }

    /** @test */
    public function its_action_choices_cover_every_action_the_log_records(): void
    {
        $this->field->render_field_settings($this->field->defaults);

        $settings = array_column($GLOBALS['scrutiny_test_acf_field_settings'], null, 'name');
        $choices  = $settings['audit_action']['choices'];

        $this->assertSame(AuditLogAdmin::ACTION_TYPES, array_keys($choices));
        // Nullable, so "all actions" stays reachable once one is chosen.
        $this->assertSame(1, $settings['audit_action']['allow_null']);
    }

    /** @test */
    public function its_entry_count_setting_stops_at_the_renderers_ceiling(): void
    {
        $this->field->render_field_settings($this->field->defaults);

        $settings = array_column($GLOBALS['scrutiny_test_acf_field_settings'], null, 'name');

        $this->assertSame(1, $settings['max_entries']['min']);
        $this->assertSame(AuditHistoryRenderer::MAX_ENTRIES_CEILING, $settings['max_entries']['max']);
    }

    // ──────────────────────────────────────────────
    //  Assets
    // ──────────────────────────────────────────────

    /** @test */
    public function it_enqueues_its_own_stylesheet(): void
    {
        // Hooked by the parent constructor onto acf/input/admin_enqueue_scripts.
        // Without it the action badges render as unstyled text.
        WpState::$enqueued = [];

        $this->field->input_admin_enqueue_scripts();

        $handles = array_column(WpState::$enqueued, 'handle');

        $this->assertContains('scrutiny-audit-history', $handles);
    }

    // ──────────────────────────────────────────────
    //  Rendering
    // ──────────────────────────────────────────────

    /** @test */
    public function it_renders_the_history_for_the_post_being_edited(): void
    {
        $GLOBALS['scrutiny_test_acf_form_data'] = ['post_id' => 42];

        ob_start();
        $this->field->render_field($this->field->defaults);
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('scrutiny-audit-history', $html);
    }

    /** @test */
    public function it_falls_back_to_the_current_post_when_form_data_is_not_a_post_id(): void
    {
        // ACF puts 'options', 'user_3' and the like in post_id for options
        // pages and user forms. Casting those to int would silently render
        // post 0's history — or worse, post 3's.
        $GLOBALS['scrutiny_test_acf_form_data'] = ['post_id' => 'options'];
        Functions\when('get_the_ID')->justReturn(99);

        $resolved = (new \ReflectionMethod(GdprAuditHistory::class, 'resolvePostId'))
            ->invoke($this->field);

        $this->assertSame(99, $resolved);
    }

    /** @test */
    public function it_resolves_to_zero_when_there_is_no_post_at_all(): void
    {
        $GLOBALS['scrutiny_test_acf_form_data'] = [];
        Functions\when('get_the_ID')->justReturn(false);

        $resolved = (new \ReflectionMethod(GdprAuditHistory::class, 'resolvePostId'))
            ->invoke($this->field);

        $this->assertSame(0, $resolved);

        // Zero is the renderer's "not saved yet" case, so the field degrades
        // to an explanation rather than an empty table.
        ob_start();
        $this->field->render_field($this->field->defaults);
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('once this record has been saved', $html);
    }
}
