<?php

declare(strict_types=1);

namespace Scrutiny\Tests\Unit\Audit;

use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Scrutiny\Audit\GdprAuditLogger;
use Scrutiny\Audit\Interfaces\AuditLogger;
use Scrutiny\Audit\Interfaces\AuditRepository;
use WP_Mock;

/**
 * Tests for GdprAuditLogger — the entry assembly, current-user capture and
 * IP anonymisation performed before delegating to the repository.
 *
 * @covers \Scrutiny\Audit\GdprAuditLogger
 */
class GdprAuditLoggerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();
    }

    protected function tearDown(): void
    {
        WP_Mock::tearDown();
        Mockery::close();
        unset($_SERVER['REMOTE_ADDR']);
        parent::tearDown();
    }

    /**
     * @test
     */
    public function log_assembles_an_entry_with_the_current_user_and_anonymised_ipv4(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.42';

        WP_Mock::userFunction('wp_get_current_user')->andReturn((object) ['user_login' => 'admin']);
        WP_Mock::userFunction('get_current_user_id')->andReturn(7);

        $captured = null;
        $repository = Mockery::mock(AuditRepository::class);
        $repository->shouldReceive('insert')->once()->andReturnUsing(
            function (array $entry) use (&$captured) {
                $captured = $entry;
                return 1;
            }
        );

        (new GdprAuditLogger($repository))->log(
            AuditLogger::ACTION_VIEW,
            AuditLogger::ENTITY_MEMBER,
            42,
            'personal-email',
            'accessed'
        );

        $this->assertSame('view', $captured['action']);
        $this->assertSame('member', $captured['entity_type']);
        $this->assertSame(42, $captured['entity_id']);
        $this->assertSame('personal-email', $captured['field_name']);
        $this->assertSame('accessed', $captured['detail']);
        $this->assertSame(7, $captured['user_id']);
        $this->assertSame('admin', $captured['user_login']);
        // Last IPv4 octet zeroed for GDPR.
        $this->assertSame('203.0.113.0', $captured['ip_address']);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            $captured['logged_at']
        );
    }

    /**
     * @test
     */
    public function log_anonymises_an_ipv6_address(): void
    {
        $_SERVER['REMOTE_ADDR'] = '2001:db8:1234:5678:9abc:def0:1234:5678';

        WP_Mock::userFunction('wp_get_current_user')->andReturn((object) ['user_login' => 'admin']);
        WP_Mock::userFunction('get_current_user_id')->andReturn(1);

        $captured = null;
        $repository = Mockery::mock(AuditRepository::class);
        $repository->shouldReceive('insert')->once()->andReturnUsing(
            function (array $entry) use (&$captured) {
                $captured = $entry;
                return 1;
            }
        );

        (new GdprAuditLogger($repository))->log('view', 'member', 1, 'personal-email');

        // The last 80 bits are zeroed, leaving the /48 network prefix.
        $this->assertSame('2001:db8:1234::', $captured['ip_address']);
    }

    /**
     * @test
     */
    public function log_falls_back_for_a_missing_or_invalid_ip(): void
    {
        $_SERVER['REMOTE_ADDR'] = 'not-an-ip';

        WP_Mock::userFunction('wp_get_current_user')->andReturn((object) []);
        WP_Mock::userFunction('get_current_user_id')->andReturn(0);

        $captured = null;
        $repository = Mockery::mock(AuditRepository::class);
        $repository->shouldReceive('insert')->once()->andReturnUsing(
            function (array $entry) use (&$captured) {
                $captured = $entry;
                return 1;
            }
        );

        (new GdprAuditLogger($repository))->log('view', 'member', 1, 'personal-email');

        $this->assertSame('0.0.0.0', $captured['ip_address']);
        // No user_login property on the current user → 'system'.
        $this->assertSame('system', $captured['user_login']);
    }

    /**
     * @test
     */
    public function log_batch_logs_one_entry_per_field(): void
    {
        WP_Mock::userFunction('wp_get_current_user')->andReturn((object) ['user_login' => 'admin']);
        WP_Mock::userFunction('get_current_user_id')->andReturn(1);
        $_SERVER['REMOTE_ADDR'] = '198.51.100.5';

        $repository = Mockery::mock(AuditRepository::class);
        $repository->shouldReceive('insert')->times(3)->andReturn(1);

        (new GdprAuditLogger($repository))->logBatch(
            'delete',
            'member',
            9,
            ['personal-email', 'mobile-number', 'gdpr-accepted'],
            'Member deleted'
        );
    }
}
