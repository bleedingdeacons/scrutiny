<?php

declare(strict_types=1);

namespace Scrutiny\Tests\Unit\Admin\Members;

use BleedingDeacons\WpMocks\WpState;
use Brain\Monkey\Functions;
use Mockery;
use Scrutiny\Admin\Members\PersonalDataMinder;
use Scrutiny\Privacy\PersonalDataPolicy;
use Unity\Core\Interfaces\Configuration;
use Unity\Members\Interfaces\Member;

/*
 * Tests for PersonalDataMinder's conditional script enqueue.
 */

covers(PersonalDataMinder::class);

beforeEach(function () {
    $GLOBALS['scrutiny_test_actions'] = [];
    $GLOBALS['scrutiny_test_capabilities'] = [];

    $this->makeMinder = function (): PersonalDataMinder {
        $configuration = $this->createMock(Configuration::class);
        $configuration->method('getConfig')
            ->with(Member::class)
            ->willReturn(['POST_TYPE' => 'unity_member']);

        return new PersonalDataMinder($configuration);
    };

    // Records whether wp_enqueue_script() was reached at all.
    $this->enqueued = false;
    $this->watchEnqueue = function (): void {
        Functions\expect('wp_enqueue_script')->andReturnUsing(function () {
            $this->enqueued = true;
        });
    };
});

it('registers the admin enqueue hook on construction', function () {
    ($this->makeMinder)();

    expect(array_column($GLOBALS['scrutiny_test_actions'], 'hook'))->toContain('acf/input/admin_enqueue_scripts');
});

it('does nothing without a current screen', function () {
    WpState::$screen = null;
    ($this->watchEnqueue)();

    ($this->makeMinder)()->enqueueScripts();

    expect($this->enqueued)->toBeFalse('No script should be enqueued without a screen.');
});

it('does nothing on a different post type screen', function () {
    Functions\expect('get_current_screen')->andReturn((object) ['post_type' => 'post']);
    ($this->watchEnqueue)();

    ($this->makeMinder)()->enqueueScripts();

    expect($this->enqueued)->toBeFalse('No script should be enqueued on a non-member screen.');
});

it('enqueues and localises the script on the member screen', function () {
    $GLOBALS['scrutiny_test_capabilities'] = [
        PersonalDataPolicy::EDIT_CAPABILITY => true,
        PersonalDataPolicy::VIEW_CAPABILITY => false,
    ];

    Functions\expect('get_current_screen')->andReturn((object) ['post_type' => 'unity_member']);
    Functions\expect('plugin_dir_url')->andReturn('https://example.com/wp-content/plugins/scrutiny/');
    Functions\expect('wp_enqueue_script')->once()->with(
        'scrutiny-personal-data-minder',
        Mockery::type('string'),
        ['jquery', 'acf-input'],
        Mockery::type('string'),
        true
    );

    $localised = null;
    Functions\expect('wp_localize_script')->once()->andReturnUsing(
        function ($handle, $object, $data) use (&$localised) {
            $localised = $data;
            return true;
        }
    );

    ($this->makeMinder)()->enqueueScripts();

    expect($localised)->toBe(['canEdit' => true, 'canView' => false]);
});
