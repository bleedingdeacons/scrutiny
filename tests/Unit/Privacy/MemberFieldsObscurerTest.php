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
 * Tests for MemberFieldsObscurer::preserveAcfValue REST-context behaviour.
 *
 * Background: Integrity's REST controllers update members by calling
 * MemberRepository::save(), which calls update_field() per field. ACF
 * runs its acf/update_value/key=... filter chain on every update_field()
 * call, including from REST. The obscurer's preserve filter previously
 * gated the value behind current_user_can() — but during REST API calls
 * authenticated by API key there is no current user, so the value was
 * silently substituted with the existing stored value and Integrity's
 * writes never landed.
 *
 * The REST_REQUEST guard added to preserveAcfValue lets trusted server-
 * side REST callers through. The Member ACF field group's show_in_rest=0
 * means /wp/v2/{type} cannot reach personal-email or mobile-number, so
 * the only paths that hit this code in REST context are programmatic
 * callers (Integrity).
 */
class MemberFieldsObscurerTest extends TestCase
{
    private const FIELD_PERSONAL_EMAIL = 'about-layout-group_personal-email';
    private const FIELD_MOBILE_NUMBER  = 'about-layout-group_mobile-number';
    private const KEY_PERSONAL_EMAIL   = 'field_67d0eabc277cb';
    private const KEY_MOBILE_NUMBER    = 'field_67d0eaea7cdea';

    protected function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();
    }

    protected function tearDown(): void
    {
        WP_Mock::tearDown();

        // Clean up the REST_REQUEST constant between tests. PHP doesn't
        // allow undefining a constant once defined, so each test that
        // needs REST_REQUEST=true runs in isolation; tests asserting the
        // false branch must run with REST_REQUEST undefined or false.
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

    /**
     * @test
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function it_lets_email_writes_through_during_rest_requests(): void
    {
        define('REST_REQUEST', true);

        // No expectation on get_field — it must NOT be consulted in
        // REST context, because the new value should pass through
        // unchanged. Any call would indicate the guard isn't taking
        // effect early enough.
        WP_Mock::userFunction('get_field')->never();

        $result = $this->makeObscurer()->preservePersonalEmail(
            'new@example.com',
            23462,
            ['name' => self::FIELD_PERSONAL_EMAIL, 'key' => self::KEY_PERSONAL_EMAIL]
        );

        $this->assertSame('new@example.com', $result);
    }

    /**
     * @test
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function it_lets_mobile_writes_through_during_rest_requests(): void
    {
        define('REST_REQUEST', true);

        WP_Mock::userFunction('get_field')->never();

        $result = $this->makeObscurer()->preserveMobileNumber(
            '07700 900999',
            23462,
            ['name' => self::FIELD_MOBILE_NUMBER, 'key' => self::KEY_MOBILE_NUMBER]
        );

        $this->assertSame('07700 900999', $result);
    }

    /**
     * @test
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function it_preserves_the_existing_value_for_admin_form_saves_when_user_cannot_edit(): void
    {
        // REST_REQUEST is intentionally not defined here — admin form
        // saves go through admin-post.php, not REST.

        WP_Mock::userFunction('current_user_can')
            ->with(PersonalDataPolicy::EDIT_CAPABILITY)
            ->andReturn(false);

        WP_Mock::userFunction('get_field')
            ->once()
            ->with(self::FIELD_PERSONAL_EMAIL, 23462, false)
            ->andReturn('existing@example.com');

        $result = $this->makeObscurer()->preservePersonalEmail(
            'attacker-supplied@example.com',
            23462,
            ['name' => self::FIELD_PERSONAL_EMAIL, 'key' => self::KEY_PERSONAL_EMAIL]
        );

        $this->assertSame(
            'existing@example.com',
            $result,
            'Non-REST writes by users without edit capability must still be silently rejected.'
        );
    }

    /**
     * @test
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function it_lets_admin_form_saves_through_when_user_can_edit(): void
    {
        // REST_REQUEST is intentionally not defined.

        WP_Mock::userFunction('current_user_can')
            ->with(PersonalDataPolicy::EDIT_CAPABILITY)
            ->andReturn(true);

        WP_Mock::userFunction('current_user_can')
            ->with(PersonalDataPolicy::VIEW_CAPABILITY)
            ->andReturn(true);

        $result = $this->makeObscurer()->preservePersonalEmail(
            'new@example.com',
            23462,
            ['name' => self::FIELD_PERSONAL_EMAIL, 'key' => self::KEY_PERSONAL_EMAIL]
        );

        $this->assertSame('new@example.com', $result);
    }
}
