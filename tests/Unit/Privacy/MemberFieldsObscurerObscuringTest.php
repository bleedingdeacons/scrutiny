<?php

declare(strict_types=1);

namespace Scrutiny\Tests\Unit\Privacy;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use function Brain\Monkey\Filters\has;
use Scrutiny\Privacy\MemberFieldsObscurer;
use Scrutiny\Privacy\PersonalDataPolicy;
use Scrutiny\Tests\TestCase;
use Unity\Core\Interfaces\Configuration;
use Unity\Members\Interfaces\Member;

/**
 * Tests for MemberFieldsObscurer's read-side obscuring: the format_value
 * filters (frontend) and prepare_field filters (admin edit form), plus the
 * filter registration wiring.
 */
#[CoversClass(\Scrutiny\Privacy\MemberFieldsObscurer::class)]
class MemberFieldsObscurerObscuringTest extends TestCase
{
    private const FIELD_PERSONAL_EMAIL = 'about-layout-group_personal-email';
    private const FIELD_MOBILE_NUMBER  = 'about-layout-group_mobile-number';
    private const KEY_PERSONAL_EMAIL   = 'field_aaa';
    private const KEY_MOBILE_NUMBER    = 'field_bbb';
    private const FIELD_LANDLINE_NUMBER = 'about-layout-group_landline-number';
    private const KEY_LANDLINE_NUMBER  = 'field_ccc';

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['scrutiny_test_capabilities'] = [];
        $GLOBALS['scrutiny_test_actions'] = [];
    }

    protected function tearDown(): void
    {
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
                'FIELD_LANDLINE_NUMBER' => self::FIELD_LANDLINE_NUMBER,
                'KEY_LANDLINE_NUMBER'  => self::KEY_LANDLINE_NUMBER,
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
    #[Test]
    public function register_wires_format_prepare_and_update_filters(): void
    {
        $obscurer = $this->makeObscurer();

        $obscurer->register();

        // Brain Monkey's Filters\has() answers with the registered priority
        // when given a callback, and false when the callback is not hooked,
        // so one assertion covers both "was it wired" and "at what priority".
        // Accepted-argument counts are not recorded by Brain Monkey, so the
        // 3-arg expectations the WP_Mock version carried are not reproduced.

        // format_value (frontend), priority 20.
        self::assertSame(20, has('acf/format_value/name=' . self::FIELD_PERSONAL_EMAIL, [$obscurer, 'obscureAcfPersonalEmail']));
        self::assertSame(20, has('acf/format_value/name=' . self::FIELD_MOBILE_NUMBER, [$obscurer, 'obscureAcfMobileNumber']));
        self::assertSame(20, has('acf/format_value/name=' . self::FIELD_LANDLINE_NUMBER, [$obscurer, 'obscureAcfLandlineNumber']));

        // prepare_field (admin) on the short sub-field name, default priority.
        self::assertSame(10, has('acf/prepare_field/name=personal-email', [$obscurer, 'prepareAcfPersonalEmail']));
        self::assertSame(10, has('acf/prepare_field/name=mobile-number', [$obscurer, 'prepareAcfMobileNumber']));
        self::assertSame(10, has('acf/prepare_field/name=landline-number', [$obscurer, 'prepareAcfLandlineNumber']));

        // …and again on the full name because the short name differs.
        self::assertSame(10, has('acf/prepare_field/name=' . self::FIELD_PERSONAL_EMAIL, [$obscurer, 'prepareAcfPersonalEmail']));
        self::assertSame(10, has('acf/prepare_field/name=' . self::FIELD_MOBILE_NUMBER, [$obscurer, 'prepareAcfMobileNumber']));
        self::assertSame(10, has('acf/prepare_field/name=' . self::FIELD_LANDLINE_NUMBER, [$obscurer, 'prepareAcfLandlineNumber']));

        // update_value guards keyed by ACF field key, priority 10.
        self::assertSame(10, has('acf/update_value/key=' . self::KEY_PERSONAL_EMAIL, [$obscurer, 'preservePersonalEmail']));
        self::assertSame(10, has('acf/update_value/key=' . self::KEY_MOBILE_NUMBER, [$obscurer, 'preserveMobileNumber']));
        self::assertSame(10, has('acf/update_value/key=' . self::KEY_LANDLINE_NUMBER, [$obscurer, 'preserveLandlineNumber']));
    }

    // ─── format_value (frontend) ────────────────────────────────────
    #[Test]
    public function format_value_obscures_email_for_users_without_view(): void
    {
        $result = $this->makeObscurer()->obscureAcfPersonalEmail('a@example.com', 1, []);

        $this->assertSame(PersonalDataPolicy::FIXED_PLACEHOLDER, $result);
    }

    #[Test]
    public function format_value_returns_the_real_email_for_viewers(): void
    {
        $this->grantView();

        $result = $this->makeObscurer()->obscureAcfPersonalEmail('a@example.com', 1, []);

        $this->assertSame('a@example.com', $result);
    }

    #[Test]
    public function format_value_leaves_empty_and_non_string_values_untouched(): void
    {
        $obscurer = $this->makeObscurer();

        $this->assertSame('', $obscurer->obscureAcfPersonalEmail('', 1, []));
        $this->assertSame(null, $obscurer->obscureAcfMobileNumber(null, 1, []));
        $this->assertSame(42, $obscurer->obscureAcfMobileNumber(42, 1, []));
    }

    #[Test]
    public function format_value_obscures_mobile_for_users_without_view(): void
    {
        $result = $this->makeObscurer()->obscureAcfMobileNumber('07700 900000', 1, []);

        $this->assertSame(PersonalDataPolicy::FIXED_PLACEHOLDER, $result);
    }

    /**
     * A landline is obscured exactly as a mobile is — same placeholder, same
     * capability gate. It is a number that reaches a named individual at
     * home, so if anything the case is stronger.
     */
    #[Test]
    public function format_value_obscures_the_landline_for_users_without_view(): void
    {
        $result = $this->makeObscurer()->obscureAcfLandlineNumber('0117 496 0000', 1, []);

        $this->assertSame(PersonalDataPolicy::FIXED_PLACEHOLDER, $result);
    }

    #[Test]
    public function format_value_returns_the_landline_unchanged_for_viewers(): void
    {
        $this->grantView();

        $result = $this->makeObscurer()->obscureAcfLandlineNumber('0117 496 0000', 1, []);

        $this->assertSame('0117 496 0000', $result);
    }

    #[Test]
    public function format_value_leaves_an_empty_landline_untouched(): void
    {
        $obscurer = $this->makeObscurer();

        $this->assertSame('', $obscurer->obscureAcfLandlineNumber('', 1, []));
        $this->assertSame(null, $obscurer->obscureAcfLandlineNumber(null, 1, []));
    }

    // ─── prepare_field (admin) ──────────────────────────────────────
    #[Test]
    public function prepare_field_masks_the_value_as_a_placeholder_for_non_viewers(): void
    {
        $field = ['value' => 'a@example.com', 'name' => 'personal-email'];

        $result = $this->makeObscurer()->prepareAcfPersonalEmail($field);

        $this->assertSame('', $result['value']);
        $this->assertSame(PersonalDataPolicy::FIXED_PLACEHOLDER, $result['placeholder']);
    }

    #[Test]
    public function prepare_field_disables_the_input_for_viewers_who_cannot_edit(): void
    {
        $this->grantView();
        $field = ['value' => '07700 900000'];

        $result = $this->makeObscurer()->prepareAcfMobileNumber($field);

        // Real value retained, but the input is disabled.
        $this->assertSame('07700 900000', $result['value']);
        $this->assertSame(1, $result['disabled']);
    }

    #[Test]
    public function prepare_field_leaves_the_input_editable_for_editors(): void
    {
        $this->grantView();
        $this->grantEdit();
        $field = ['value' => 'a@example.com'];

        $result = $this->makeObscurer()->prepareAcfPersonalEmail($field);

        $this->assertSame('a@example.com', $result['value']);
        $this->assertArrayNotHasKey('disabled', $result);
    }

    #[Test]
    public function prepare_field_passes_through_false_and_empty_values(): void
    {
        $obscurer = $this->makeObscurer();

        $this->assertFalse($obscurer->prepareAcfPersonalEmail(false));
        $this->assertSame(
            ['value' => ''],
            $obscurer->prepareAcfMobileNumber(['value' => ''])
        );
    }

    #[Test]
    public function prepare_field_masks_the_mobile_value_for_non_viewers(): void
    {
        $field = ['value' => '07700 900000', 'name' => 'mobile-number'];

        $result = $this->makeObscurer()->prepareAcfMobileNumber($field);

        $this->assertSame('', $result['value']);
        $this->assertSame(PersonalDataPolicy::FIXED_PLACEHOLDER, $result['placeholder']);
    }

    #[Test]
    public function prepare_field_masks_the_landline_value_for_non_viewers(): void
    {
        $field = ['value' => '0117 496 0000', 'name' => 'landline-number'];

        $result = $this->makeObscurer()->prepareAcfLandlineNumber($field);

        $this->assertSame('', $result['value']);
        $this->assertSame(PersonalDataPolicy::FIXED_PLACEHOLDER, $result['placeholder']);
    }

    #[Test]
    public function prepare_field_disables_the_landline_for_viewers_who_cannot_edit(): void
    {
        $this->grantView();

        $result = $this->makeObscurer()->prepareAcfLandlineNumber(['value' => '0117 496 0000']);

        $this->assertSame('0117 496 0000', $result['value']);
        $this->assertSame(1, $result['disabled']);
    }

    #[Test]
    public function prepare_field_passes_through_a_false_or_empty_landline(): void
    {
        $obscurer = $this->makeObscurer();

        $this->assertFalse($obscurer->prepareAcfLandlineNumber(false));
        $this->assertSame(
            ['value' => ''],
            $obscurer->prepareAcfLandlineNumber(['value' => ''])
        );
    }

    // ─── update_value: the clear sentinel ───────────────────────────
    #[Test]
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

    #[Test]
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

    #[Test]
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

    #[Test]
    public function update_value_rejects_a_landline_change_from_a_user_who_cannot_edit(): void
    {
        $GLOBALS['scrutiny_test_acf_fields'][23462][self::FIELD_LANDLINE_NUMBER] = '0117 496 0000';

        $result = $this->makeObscurer()->preserveLandlineNumber(
            '0117 496 9999',
            23462,
            ['name' => self::FIELD_LANDLINE_NUMBER, 'key' => self::KEY_LANDLINE_NUMBER]
        );

        $this->assertSame('0117 496 0000', $result);
    }

    #[Test]
    public function update_value_preserves_a_landline_when_a_non_viewer_editor_submits_blank(): void
    {
        $this->grantEdit();
        $GLOBALS['scrutiny_test_acf_fields'][23462][self::FIELD_LANDLINE_NUMBER] = '0117 496 0000';

        $result = $this->makeObscurer()->preserveLandlineNumber(
            '',
            23462,
            ['name' => self::FIELD_LANDLINE_NUMBER, 'key' => self::KEY_LANDLINE_NUMBER]
        );

        $this->assertSame('0117 496 0000', $result);
    }

    /**
     * The Clear button submits a sentinel rather than an empty string, so
     * that an intentional clear is distinguishable from an untouched field
     * — which for a landline also drops the member's preference back to
     * Mobile downstream.
     */
    #[Test]
    public function update_value_clears_a_landline_on_the_sentinel(): void
    {
        $this->grantEdit();
        $GLOBALS['scrutiny_test_acf_fields'][23462][self::FIELD_LANDLINE_NUMBER] = '0117 496 0000';

        $result = $this->makeObscurer()->preserveLandlineNumber(
            PersonalDataPolicy::CLEAR_SENTINEL,
            23462,
            ['name' => self::FIELD_LANDLINE_NUMBER, 'key' => self::KEY_LANDLINE_NUMBER]
        );

        $this->assertSame('', $result);
    }
}
