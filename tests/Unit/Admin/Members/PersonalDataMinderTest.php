<?php

declare(strict_types=1);

namespace Scrutiny\Tests\Unit\Admin\Members;

use PHPUnit\Framework\TestCase;
use Scrutiny\Admin\Members\PersonalDataMinder;
use Scrutiny\Privacy\PersonalDataPolicy;
use Unity\Core\Interfaces\Configuration;
use Unity\Members\Interfaces\Member;
use WP_Mock;

/**
 * Tests for PersonalDataMinder's conditional script enqueue.
 *
 * @covers \Scrutiny\Admin\Members\PersonalDataMinder
 */
class PersonalDataMinderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();
        $GLOBALS['scrutiny_test_actions'] = [];
        $GLOBALS['scrutiny_test_capabilities'] = [];
    }

    protected function tearDown(): void
    {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    private function makeMinder(): PersonalDataMinder
    {
        $configuration = $this->createMock(Configuration::class);
        $configuration->method('getConfig')
            ->with(Member::class)
            ->willReturn(['POST_TYPE' => 'unity_member']);

        return new PersonalDataMinder($configuration);
    }

    /**
     * @test
     */
    public function it_registers_the_admin_enqueue_hook_on_construction(): void
    {
        $this->makeMinder();

        $hooks = array_column($GLOBALS['scrutiny_test_actions'], 'hook');
        $this->assertContains('acf/input/admin_enqueue_scripts', $hooks);
    }

    /**
     * @test
     */
    public function it_does_nothing_without_a_current_screen(): void
    {
        WP_Mock::userFunction('get_current_screen')->andReturn(null);

        $enqueued = false;
        WP_Mock::userFunction('wp_enqueue_script')->andReturnUsing(
            function () use (&$enqueued) {
                $enqueued = true;
            }
        );

        $this->makeMinder()->enqueueScripts();

        $this->assertFalse($enqueued, 'No script should be enqueued without a screen.');
    }

    /**
     * @test
     */
    public function it_does_nothing_on_a_different_post_type_screen(): void
    {
        WP_Mock::userFunction('get_current_screen')->andReturn(
            (object) ['post_type' => 'post']
        );

        $enqueued = false;
        WP_Mock::userFunction('wp_enqueue_script')->andReturnUsing(
            function () use (&$enqueued) {
                $enqueued = true;
            }
        );

        $this->makeMinder()->enqueueScripts();

        $this->assertFalse($enqueued, 'No script should be enqueued on a non-member screen.');
    }

    /**
     * @test
     */
    public function it_enqueues_and_localises_the_script_on_the_member_screen(): void
    {
        $GLOBALS['scrutiny_test_capabilities'] = [
            PersonalDataPolicy::EDIT_CAPABILITY => true,
            PersonalDataPolicy::VIEW_CAPABILITY => false,
        ];

        WP_Mock::userFunction('get_current_screen')->andReturn(
            (object) ['post_type' => 'unity_member']
        );
        WP_Mock::userFunction('plugin_dir_url')->andReturn('https://example.com/wp-content/plugins/scrutiny/');
        WP_Mock::userFunction('wp_enqueue_script')->once()->with(
            'scrutiny-personal-data-minder',
            \WP_Mock\Functions::type('string'),
            ['jquery', 'acf-input'],
            \WP_Mock\Functions::type('string'),
            true
        );

        $localised = null;
        WP_Mock::userFunction('wp_localize_script')->once()->andReturnUsing(
            function ($handle, $object, $data) use (&$localised) {
                $localised = $data;
                return true;
            }
        );

        $this->makeMinder()->enqueueScripts();

        $this->assertSame(['canEdit' => true, 'canView' => false], $localised);
    }
}
