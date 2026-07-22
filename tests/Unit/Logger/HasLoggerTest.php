<?php

declare(strict_types=1);

namespace Scrutiny\Tests\Unit\Logger;

use PHPUnit\Framework\TestCase;
use Scrutiny\Logger\HasLogger;

/**
 * A throwaway consumer of the logging trait so its static behaviour can be
 * exercised in isolation.
 */
class HasLoggerFixture
{
    use HasLogger;
}

/**
 * Tests for the HasLogger trait.
 *
 * The test bootstrap stubs wp_log() and Sentinel_Log_Channel, recording
 * every emitted entry in $GLOBALS['scrutiny_test_log_entries'], so the
 * trait's resolve-and-forward behaviour is fully observable.
 *
 * @covers \Scrutiny\Logger\HasLogger
 */
class HasLoggerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['scrutiny_test_log_entries'] = [];
        $this->resetChannel();
    }

    protected function tearDown(): void
    {
        $this->resetChannel();
        parent::tearDown();
    }

    private function resetChannel(): void
    {
        $prop = (new \ReflectionClass(HasLoggerFixture::class))->getProperty('loggerChannel');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    /**
     * @test
     */
    public function it_resolves_a_channel_named_after_the_short_class_name(): void
    {
        $channel = HasLoggerFixture::log();

        $this->assertInstanceOf(\Sentinel_Log_Channel::class, $channel);
        // logChannel() sanitises the short class name.
        $this->assertSame('hasloggerfixture', $channel->channel);
        // The channel is memoised.
        $this->assertSame($channel, HasLoggerFixture::log());
    }

    /**
     * @test
     */
    public function every_level_forwards_to_the_channel(): void
    {
        HasLoggerFixture::logEmergency('a');
        HasLoggerFixture::logAlert('b');
        HasLoggerFixture::logCritical('c');
        HasLoggerFixture::logError('d');
        HasLoggerFixture::logWarning('e');
        HasLoggerFixture::logNotice('f');
        HasLoggerFixture::logInfo('g');
        HasLoggerFixture::logDebug('h');

        $entries = $GLOBALS['scrutiny_test_log_entries'];
        $levels = array_column($entries, 'level');
        $messages = array_column($entries, 'message');

        $this->assertSame(
            ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'],
            $levels
        );
        $this->assertSame(['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'], $messages);
    }

    /**
     * @test
     */
    public function context_is_passed_through(): void
    {
        HasLoggerFixture::logError('boom', ['id' => 42]);

        $this->assertSame(['id' => 42], $GLOBALS['scrutiny_test_log_entries'][0]['context']);
    }
}
