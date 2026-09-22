<?php

declare(strict_types=1);

namespace Scrutiny\Tests\Unit\Logger;

use Scrutiny\Logger\HasLogger;

/**
 * A throwaway consumer of the logging trait so its static behaviour can be
 * exercised in isolation.
 */
class HasLoggerFixture
{
    use HasLogger;
}

/*
 * Tests for the HasLogger trait.
 *
 * The test bootstrap stubs wp_log() and Sentinel_Log_Channel, recording every
 * emitted entry in $GLOBALS['scrutiny_test_log_entries'], so the trait's
 * resolve-and-forward behaviour is fully observable.
 */

covers(HasLogger::class);

function resetLoggerChannel(): void
{
    (new \ReflectionClass(HasLoggerFixture::class))->getProperty('loggerChannel')->setValue(null, null);
}

beforeEach(function () {
    $GLOBALS['scrutiny_test_log_entries'] = [];
    resetLoggerChannel();
});

afterEach(function () {
    resetLoggerChannel();
});

it('resolves a channel named after the short class name', function () {
    $channel = HasLoggerFixture::log();

    expect($channel)->toBeInstanceOf(\Sentinel_Log_Channel::class)
        // logChannel() sanitises the short class name.
        ->and($channel->channel)->toBe('hasloggerfixture')
        // The channel is memoised.
        ->and(HasLoggerFixture::log())->toBe($channel);
});

it('forwards every level to the channel', function () {
    HasLoggerFixture::logEmergency('a');
    HasLoggerFixture::logAlert('b');
    HasLoggerFixture::logCritical('c');
    HasLoggerFixture::logError('d');
    HasLoggerFixture::logWarning('e');
    HasLoggerFixture::logNotice('f');
    HasLoggerFixture::logInfo('g');
    HasLoggerFixture::logDebug('h');

    $entries = $GLOBALS['scrutiny_test_log_entries'];

    expect(array_column($entries, 'level'))
        ->toBe(['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'])
        ->and(array_column($entries, 'message'))->toBe(['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h']);
});

it('passes the context through', function () {
    HasLoggerFixture::logError('boom', ['id' => 42]);

    expect($GLOBALS['scrutiny_test_log_entries'][0]['context'])->toBe(['id' => 42]);
});
