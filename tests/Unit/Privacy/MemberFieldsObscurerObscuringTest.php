<?php

declare(strict_types=1);

namespace Scrutiny\Tests\Unit\Privacy;

use PHPUnit\Framework\TestCase;
use Scrutiny\Privacy\MemberFieldsObscurer;
use Scrutiny\Privacy\PersonalDataPolicy;
use Unity\Core\Interfaces\Configuration;
use Unity\Members\Interfaces\Member;
use WP_Mock;

/**
 * Tests for MemberFieldsObscurer's read-side obscuring: the format_value
 * filters (frontend) and prepare_field filters (admin edit form), plus the
 * filter registration wiring.
 *
 * @covers \Scrutiny\Privacy\MemberFieldsObscurer
 */
class MemberFieldsObscurerObscuringTest extends TestCase
{
    private const FIELD_PERSONAL_EMAIL = 'about-layout-group_personal-email';
    private const FIELD_MOBILE_NUMBER  = 'about-layout-group_mobile-number';
    private const KEY_PERSONAL_EMAIL   = 'field_aaa';
    private const KEY_MOBILE_NUMBER    = 'field_bbb';

    protected function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();
        $GLOBALS['scrutiny_test_capabilities'] = [];
        $GLOBALS['scrutiny_test_actions'] = [];
    }

    protected function tearDown(): void
    {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    private function makeObscurer(): MemberFieldsObscurer
    {
        $configuration = $this->createMock(Configuration::class);
        $configuration->method('getConfig')
            ->with(Member::class)
            ->willReturn([
                'FIELD_PERSONAL_EMAIL' => self::FIELD_PERSONAL_EMAIL,
                'FIELD_MOBILE_NUMBER'  => self::FIELD_MOBILE_NUMBER,
                'KEY_PERSONAL_EMAIL'   => self::KEY_PERSONAL_EMAIL,
                'KEY_MOBILE_NUMBER'    => self::KEY_MOBILE_NUMBER,
            ]);

        return new MemberFieldsObscurer($configuration, new PersonalDataPolicy());
    }

    private function grantView(): void
    {
        $GLOBALS['scrutiny_test_capabilities'][PersonalDataPolicy::VIEW_CAPABILITY] = true;
    }

    private function grantEdit(): void
    {
        $GLOBALS['scrutiny_test_capabilities'][PersonalDataPolicy::EDIT_CAPABILITY] = true;
    }

    // ─── register ───────────────────────────────────────────────────

    /**
     * @test
     */
    public function register_wires_format_prepare_and_update_filters(): void
    {
        $obscurer = $this->makeObscurer();

        // format_value (frontend), priority 20, 3 args.
        WP_Mock::expectFilterAdded('acf/format_value/name=' . self::FIELD_PERSONAL_EMAIL, [$obscurer, 'obscureAcfPersonalEmail'], 20, 3);
        WP_Mock::expectFilterAdded('acf/format_value/name=' . self::FIELD_MOBILE_NUMBER, [$obscurer, 'obscureAcfMobileNumber'], 20, 3);

        // prepare_field (admin) on the short sub-field name, default priority.
        WP_Mock::expectFilterAdded('acf/prepare_field/name=personal-email', [$obscurer, 'prepareAcfPersonalEmail']);
        WP_Mock::expectFilterAdded('acf/prepare_field/name=mobile-number', [$obscurer, 'prepareAcfMobileNumber']);

        // …and again on the full name because the short name differs.
        WP_Mock::expectFilterAdded('acf/prepare_field/name=' . self::FIELD_PERSONAL_EMAIL, [$obscurer, 'prepareAcfPersonalEmail']);
        WP_Mock::expectFilterAdded('acf/prepare_field/name=' . self::FIELD_MOBILE_NUMBER, [$obscurer, 'prepareAcfMobileNumber']);

        // update_value guards keyed by ACF field key, priority 10, 3 args.
        WP_Mock::expectFilterAdded('acf/update_value/key=' . self::KEY_PERSONAL_EMAIL, [$obscurer, 'preservePersonalEmail'], 10, 3);
        WP_Mock::expectFilterAdded('acf/update_value/key=' . self::KEY_MOBILE_NUMBER, [$obscurer, 'preserveMobileNumber'], 10, 3);

        $obscurer->register();

        WP_Mock::assertHooksAdded();
    }

    // ─── format_value (frontend) ────────────────────────────────────

    /**
     * @test
     */
    public function format_value_obscures_email_for_users_without_view(): void
    {
        $result = $this->makeObscurer()->obscureAcfPersonalEmail('a@example.com', 1, []);

        $this->assertSame(PersonalDataPolicy::FIXED_PLACEHOLDER, $result);
    }

    /**
     * @test
     */
    public function format_value_returns_the_real_email_for_viewers(): void
    {
        $this->grantView();

        $result = $this->makeObscurer()->obscureAcfPersonalEmail('a@example.com', 1, []);

        $this->assertSame('a@example.com', $result);
    }

    /**
     * @test
     */
    public function format_value_leaves_empty_and_non_string_values_untouched(): void
    {
        $obscurer = $this->makeObscurer();

        $this->assertSame('', $obscurer->obscureAcfPersonalEmail('', 1, []));
        $this->assertSame(null, $obscurer->obscureAcfMobileNumber(null, 1, []));
        $this->assertSame(42, $obscurer->obscureAcfMobileNumber(42, 1, []));
    }

    /**
     * @test
     */
    public function format_value_obscures_mobile_for_users_without_view(): void
    {
        $result = $this->makeObscurer()->obscureAcfMobileNumber('07700 900000', 1, []);

        $this->assertSame(PersonalDataPolicy::FIXED_PLACEHOLDER, $result);
    }

    // ─── prepare_field (admin) ──────────────────────────────────────

    /**
     * @test
     */
    public function prepare_field_masks_the_value_as_a_placeholder_for_non_viewers(): void
    {
        $field = ['value' => 'a@example.com', 'name' => 'personal-email'];

        $result = $this->makeObscurer()->prepareAcfPersonalEmail($field);

        $this->assertSame('', $result['value']);
        $this->assertSame(PersonalDataPolicy::FIXED_PLACEHOLDER, $result['placeholder']);
    }

    /**
     * @test
     */
    public function prepare_field_disables_the_input_for_viewers_who_cannot_edit(): void
    {
        $this->grantView();
        $field = ['value' => '07700 900000'];

        $result = $this->makeObscurer()->prepareAcfMobileNumber($field);

        // Real value retained, but the input is disabled.
        $this->assertSame('07700 900000', $result['value']);
        $this->assertSame(1, $result['disabled']);
    }

    /**
     * @test
     */
    public function prepare_field_leaves_the_input_editable_for_editors(): void
    {
        $this->grantView();
        $this->grantEdit();
        $field = ['value' => 'a@example.com'];

        $result = $this->makeObscurer()->prepareAcfPersonalEmail($field);

        $this->assertSame('a@example.com', $result['value']);
        $this->assertArrayNotHasKey('disabled', $result);
    }

    /**
     * @test
     */
    public function prepare_field_passes_through_false_and_empty_values(): void
    {
        $obscurer = $this->makeObscurer();

        $this->assertFalse($obscurer->prepareAcfPersonalEmail(false));
        $this->assertSame(
            ['value' => ''],
            $obscurer->prepareAcfMobileNumber(['value' => ''])
        );
    }

    /**
     * @test
     */
    public function prepare_field_masks_the_mobile_value_for_non_viewers(): void
    {
        $field = ['value' => '07700 900000', 'name' => 'mobile-number'];

        $result = $this->makeObscurer()->prepareAcfMobileNumber($field);

        $this->assertSame('', $result['value']);
        $this->assertSame(PersonalDataPolicy::FIXED_PLACEHOLDER, $result['placeholder']);
    }

    // ─── update_value: the clear sentinel ───────────────────────────

    /**
     * @test
     */
    public function update_value_converts_the_clear_sentinel_to_an_empty_string(): void
    {
        $this->grantEdit();

        $result = $this->makeObscurer()->preservePersonalEmail(
            PersonalDataPolicy::CLEAR_SENTINEL,
            23462,
            ['name' => self::FIELD_PERSONAL_EMAIL, 'key' => self::KEY_PERSONAL_EMAIL]
        );

        $this->assertSame('', $result);
    }

    /**
     * @test
     */
    public function update_value_preserves_the_existing_value_when_a_non_viewer_editor_submits_blank(): void
    {
        // Editor who cannot view sees a placeholder; submitting blank must
        // keep the stored value rather than wiping it.
        $this->grantEdit();
        $GLOBALS['scrutiny_test_acf_fields'][23462][self::FIELD_PERSONAL_EMAIL] = 'keep@example.com';

        $result = $this->makeObscurer()->preservePersonalEmail(
            '',
            23462,
            ['name' => self::FIELD_PERSONAL_EMAIL, 'key' => self::KEY_PERSONAL_EMAIL]
        );

        $this->assertSame('keep@example.com', $result);
    }

    /**
     * @test
     */
    public function update_value_rejects_a_mobile_change_from_a_user_who_cannot_edit(): void
    {
        // No edit capability: the stored mobile number must be preserved
        // against the attacker-supplied value.
        $GLOBALS['scrutiny_test_acf_fields'][23462][self::FIELD_MOBILE_NUMBER] = '07700 900000';

        $result = $this->makeObscurer()->preserveMobileNumber(
            '07999 999999',
            23462,
            ['name' => self::FIELD_MOBILE_NUMBER, 'key' => self::KEY_MOBILE_NUMBER]
        );

        $this->assertSame('07700 900000', $result);
    }
}
