<?php

declare(strict_types=1);

namespace Scrutiny\Tests\Unit\Admin\Members;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use function Brain\Monkey\Functions\expect;
use BleedingDeacons\WpMocks\WpState;
use Mockery;
use Scrutiny\Admin\Members\PersonalDataMinder;
use Scrutiny\Privacy\PersonalDataPolicy;
use Scrutiny\Tests\TestCase;
use Unity\Core\Interfaces\Configuration;
use Unity\Members\Interfaces\Member;

/**
 * Tests for PersonalDataMinder's conditional script enqueue.
 */
#[CoversClass(\Scrutiny\Admin\Members\PersonalDataMinder::class)]
class PersonalDataMinderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['scrutiny_test_actions'] = [];
        $GLOBALS['scrutiny_test_capabilities'] = [];
    }

    protected function tearDown(): void
    {
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

    #[Test]
    public function it_registers_the_admin_enqueue_hook_on_construction(): void
    {
        $this->makeMinder();

        $hooks = array_column($GLOBALS['scrutiny_test_actions'], 'hook');
        $this->assertContains('acf/input/admin_enqueue_scripts', $hooks);
    }

    #[Test]
    public function it_does_nothing_without_a_current_screen(): void
    {
        WpState::$screen = null;

        $enqueued = false;
        expect('wp_enqueue_script')->andReturnUsing(
            function () use (&$enqueued) {
                $enqueued = true;
            }
        );

        $this->makeMinder()->enqueueScripts();

        $this->assertFalse($enqueued, 'No script should be enqueued without a screen.');
    }

    #[Test]
    public function it_does_nothing_on_a_different_post_type_screen(): void
    {
        expect('get_current_screen')->andReturn(
            (object) ['post_type' => 'post']
        );

        $enqueued = false;
        expect('wp_enqueue_script')->andReturnUsing(
            function () use (&$enqueued) {
                $enqueued = true;
            }
        );

        $this->makeMinder()->enqueueScripts();

        $this->assertFalse($enqueued, 'No script should be enqueued on a non-member screen.');
    }

    #[Test]
    public function it_enqueues_and_localises_the_script_on_the_member_screen(): void
    {
        $GLOBALS['scrutiny_test_capabilities'] = [
            PersonalDataPolicy::EDIT_CAPABILITY => true,
            PersonalDataPolicy::VIEW_CAPABILITY => false,
        ];

        expect('get_current_screen')->andReturn(
            (object) ['post_type' => 'unity_member']
        );
        expect('plugin_dir_url')->andReturn('https://example.com/wp-content/plugins/scrutiny/');
        expect('wp_enqueue_script')->once()->with(
            'scrutiny-personal-data-minder',
            Mockery::type('string'),
            ['jquery', 'acf-input'],
            Mockery::type('string'),
            true
        );

        $localised = null;
        expect('wp_localize_script')->once()->andReturnUsing(
            function ($handle, $object, $data) use (&$localised) {
                $localised = $data;
                return true;
            }
        );

        $this->makeMinder()->enqueueScripts();

        $this->assertSame(['canEdit' => true, 'canView' => false], $localised);
    }
}
