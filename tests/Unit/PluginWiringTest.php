<?php

declare(strict_types=1);

namespace Scrutiny\Tests\Unit;

use Mockery;
use PHPUnit\Framework\TestCase;
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
use Scrutiny\Plugin;
use Scrutiny\Privacy\GroupFieldsObscurer;
use Scrutiny\Privacy\MemberFieldsObscurer;
use Scrutiny\Privacy\PersonalDataPolicy;
use Scrutiny\Privacy\PrivacyPolicyFormatter;
use Scrutiny\Privacy\ResponderCertificationGuard;
use Unity\Core\Interfaces\Configuration;
use Unity\Core\Interfaces\Container;
use Unity\Members\Interfaces\MemberRepository;
use WP_Mock;

/**
 * Covers the Plugin bootstrap: the container registrations in
 * registerServices(), the capability top-up in ensureCapabilities(), and the
 * getContainer() accessor.
 *
 * The full init() is not driven here because it hard-depends on
 * tsml-for-unity concretes (the privacy-policy factory/repository) that are
 * not on the classpath in Scrutiny's isolated unit run. registerServices() is
 * invoked directly and every Scrutiny-owned binding is resolved so its factory
 * closure runs.
 */
class PluginWiringTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();
        $GLOBALS['scrutiny_test_actions'] = [];
        $this->resetPluginStatics();
    }

    protected function tearDown(): void
    {
        $this->resetPluginStatics();
        WP_Mock::tearDown();
        Mockery::close();
        parent::tearDown();
    }

    /** @test */
    public function register_services_binds_and_resolves_every_scrutiny_service(): void
    {
        // AuditTracker's constructor wires an acf/load_value filter (WP_Mock
        // owns add_filter); permit it. The action hooks it also wires go
        // through the bootstrap add_action recorder.
        WP_Mock::userFunction('add_filter')->andReturn(true);

        $container = new PluginFakeContainer([
            Configuration::class    => $this->configuration(),
            MemberRepository::class => $this->createMock(MemberRepository::class),
        ]);

        // registerServices() is private static; invoke it directly.
        $ref = new \ReflectionMethod(Plugin::class, 'registerServices');
        $ref->invoke(null, $container);

        // Resolve every Scrutiny-owned binding so its factory closure runs.
        // The tsml-backed privacy-policy bindings are intentionally skipped —
        // their concretes are not on the classpath here.
        $expectations = [
            AuditRepository::class          => GdprAuditRepository::class,
            AuditLogger::class              => GdprAuditLogger::class,
            PersonalDataPolicy::class       => PersonalDataPolicy::class,
            AuditTracker::class             => AuditTracker::class,
            MemberFieldsObscurer::class     => MemberFieldsObscurer::class,
            GroupFieldsObscurer::class      => GroupFieldsObscurer::class,
            ResponderCertificationGuard::class => ResponderCertificationGuard::class,
            PrunerSettings::class           => PrunerSettings::class,
            MemberPruner::class             => MemberPruner::class,
            MemberTrashCleaner::class       => MemberTrashCleaner::class,
            PrunerCron::class               => PrunerCron::class,
            PrivacyPolicyFormatter::class   => PrivacyPolicyFormatter::class,
        ];

        foreach ($expectations as $id => $concrete) {
            $this->assertInstanceOf($concrete, $container->get($id), "$id should resolve to $concrete");
        }
    }

    /** @test */
    public function ensure_capabilities_grants_each_missing_capability(): void
    {
        $role = Mockery::mock();
        $role->shouldReceive('has_cap')->andReturn(false);
        $role->shouldReceive('add_cap')
            ->times(3)
            ->with(Mockery::type('string'));

        WP_Mock::userFunction('get_role')->with('administrator')->andReturn($role);

        (new \ReflectionMethod(Plugin::class, 'ensureCapabilities'))->invoke(null);

        $this->assertTrue(true); // Mockery expectations verified on tearDown.
    }

    /** @test */
    public function ensure_capabilities_bails_when_there_is_no_admin_role(): void
    {
        WP_Mock::userFunction('get_role')->with('administrator')->andReturn(null);

        (new \ReflectionMethod(Plugin::class, 'ensureCapabilities'))->invoke(null);

        $this->assertTrue(true);
    }

    /** @test */
    public function get_container_throws_before_init(): void
    {
        $this->expectException(RuntimeException::class);
        Plugin::getContainer();
    }

    // --- helpers ----------------------------------------------------------

    private function configuration(): Configuration
    {
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

        return $configuration;
    }

    private function resetPluginStatics(): void
    {
        $ref = new \ReflectionClass(Plugin::class);
        foreach (['container' => null, 'initialized' => false] as $prop => $value) {
            if ($ref->hasProperty($prop)) {
                $p = $ref->getProperty($prop);
                $p->setValue(null, $value);
            }
        }
    }
}

/**
 * Recording container implementing Unity's Container contract: presets are
 * pre-built leaf services; everything else runs its registered factory once.
 */
final class PluginFakeContainer implements Container
{
    /** @var array<string, callable> */
    private array $factories = [];
    /** @var array<string, mixed> */
    private array $instances;

    /** @param array<string, mixed> $presets */
    public function __construct(array $presets = [])
    {
        $this->instances = $presets;
    }

    public function register(string $id, callable $factory): void
    {
        $this->factories[$id] = $factory;
    }

    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }
        if (isset($this->factories[$id])) {
            return $this->instances[$id] = ($this->factories[$id])($this);
        }
        throw new RuntimeException('No service registered for ' . $id);
    }

    public function has(string $id): bool
    {
        return isset($this->factories[$id]) || array_key_exists($id, $this->instances);
    }
}
