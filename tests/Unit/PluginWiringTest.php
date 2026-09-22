<?php

declare(strict_types=1);

namespace Scrutiny\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;
use RuntimeException;
use Scrutiny\Audit\AuditTracker;
use Scrutiny\Audit\GdprAuditLogger;
use Scrutiny\Audit\GdprAuditRepository;
use Scrutiny\Audit\Interfaces\AuditLogger;
use Scrutiny\Audit\Interfaces\AuditRepository;
use Scrutiny\Cleanup\MemberPruner;
use Scrutiny\Cleanup\MemberTrashCleaner;
use Scrutiny\Cleanup\PrunerCron;
use Scrutiny\Cleanup\PrunerSettings;
use Scrutiny\Fields\AuditHistoryRenderer;
use Scrutiny\Plugin;
use Scrutiny\Privacy\GroupFieldsObscurer;
use Scrutiny\Privacy\MemberFieldsObscurer;
use Scrutiny\Privacy\PersonalDataPolicy;
use Scrutiny\Privacy\PrivacyPolicyFormatter;
use Scrutiny\Privacy\ResponderCertificationGuard;
use Unity\Core\Interfaces\Configuration;
use Unity\Groups\Interfaces\GroupRepository;
use Unity\Members\Interfaces\MemberRepository;
use Unity\Positions\Interfaces\PositionRepository;
use Unity\Testing\Doubles\FakeContainer;

/*
 * Covers the Plugin bootstrap: the container registrations in
 * registerServices(), the capability top-up in ensureCapabilities(), and the
 * getContainer() accessor.
 *
 * The full init() is not driven here because it hard-depends on tsml-for-unity
 * concretes (the privacy-policy factory/repository) that are not on the
 * classpath in Scrutiny's isolated unit run. registerServices() is invoked
 * directly and every Scrutiny-owned binding is resolved so its factory closure
 * runs.
 */

function resetPluginStatics(): void
{
    $ref = new \ReflectionClass(Plugin::class);
    foreach (['container' => null, 'initialized' => false] as $prop => $value) {
        if ($ref->hasProperty($prop)) {
            $ref->getProperty($prop)->setValue(null, $value);
        }
    }
}

function ensureCapabilities(): void
{
    (new \ReflectionMethod(Plugin::class, 'ensureCapabilities'))->invoke(null);
}

beforeEach(function () {
    $GLOBALS['scrutiny_test_actions'] = [];
    resetPluginStatics();
});

afterEach(function () {
    resetPluginStatics();
});

it('binds and resolves every Scrutiny service', function (string $id, string $concrete) {
    // AuditTracker's constructor wires an acf/load_value filter, which Brain
    // Monkey records for free. The action hooks it also wires go through the
    // bootstrap's add_action recorder.
    $configuration = $this->createMock(Configuration::class);
    $configuration->method('getConfig')->willReturn([
        'FIELD_PERSONAL_EMAIL'          => 'about-layout-group_personal-email',
        'FIELD_MOBILE_NUMBER'           => 'about-layout-group_mobile-number',
        'KEY_PERSONAL_EMAIL'            => 'field_aaa',
        'KEY_MOBILE_NUMBER'             => 'field_bbb',
        'KEY_RESPONDER_CERTIFICATION'   => 'field_ccc',
        'FIELD_RESPONDER_CERTIFICATION' => 'service-layout-group_responder-certification',
        'POST_TYPE'                     => 'member',
    ]);

    $container = new FakeContainer([
        Configuration::class      => $configuration,
        MemberRepository::class   => $this->createMock(MemberRepository::class),
        // AuditTracker resolves these to name home groups and positions in its
        // entries.
        GroupRepository::class    => $this->createMock(GroupRepository::class),
        PositionRepository::class => $this->createMock(PositionRepository::class),
    ]);

    // registerServices() is private static; invoke it directly.
    (new \ReflectionMethod(Plugin::class, 'registerServices'))->invoke(null, $container);

    // Resolving the binding runs its factory closure.
    expect($container->get($id))->toBeInstanceOf($concrete);
})->with([
    // The tsml-backed privacy-policy bindings are intentionally skipped —
    // their concretes are not on the classpath here. So is GdprAuditHistory,
    // whose acf_field base class only exists once ACF fires
    // acf/include_field_types; tests/Unit/Fields covers it against the stub
    // base class instead.
    'audit repository'              => [AuditRepository::class, GdprAuditRepository::class],
    'audit logger'                  => [AuditLogger::class, GdprAuditLogger::class],
    'personal data policy'          => [PersonalDataPolicy::class, PersonalDataPolicy::class],
    'audit tracker'                 => [AuditTracker::class, AuditTracker::class],
    'audit history renderer'        => [AuditHistoryRenderer::class, AuditHistoryRenderer::class],
    'member fields obscurer'        => [MemberFieldsObscurer::class, MemberFieldsObscurer::class],
    'group fields obscurer'         => [GroupFieldsObscurer::class, GroupFieldsObscurer::class],
    'responder certification guard' => [ResponderCertificationGuard::class, ResponderCertificationGuard::class],
    'pruner settings'               => [PrunerSettings::class, PrunerSettings::class],
    'member pruner'                 => [MemberPruner::class, MemberPruner::class],
    'member trash cleaner'          => [MemberTrashCleaner::class, MemberTrashCleaner::class],
    'pruner cron'                   => [PrunerCron::class, PrunerCron::class],
    'privacy policy formatter'      => [PrivacyPolicyFormatter::class, PrivacyPolicyFormatter::class],
]);

describe('ensureCapabilities', function () {
    it('grants each missing capability', function () {
        $role = Mockery::mock();
        $role->shouldReceive('has_cap')->andReturn(false);
        $role->shouldReceive('add_cap')
            ->times(3)
            ->with(Mockery::type('string'));

        Functions\when('get_role')->justReturn($role);

        ensureCapabilities();
    });

    it('bails when there is no admin role', function () {
        Functions\when('get_role')->justReturn(null);

        ensureCapabilities();
    })->throwsNoExceptions();
});

it('throws from getContainer before init', function () {
    Plugin::getContainer();
})->throws(RuntimeException::class);
