<?php

declare(strict_types=1);

namespace Scrutiny\Tests\Unit\Privacy;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use Scrutiny\Privacy\ResponderCertificationGuard;
use Scrutiny\Tests\TestCase;
use Unity\Core\Interfaces\Configuration;
use Unity\Members\Interfaces\Member;

/**
 * ResponderCertificationGuard during a REST request.
 *
 * Kept as a PHPUnit class, not a Pest spec like the rest of the guard's tests
 * in ResponderCertificationGuardTest. It defines REST_REQUEST, which cannot be
 * undone once defined, so it must run in a separate process — and Pest refuses
 * process isolation outright. Pest runs this class as it is.
 */
#[CoversClass(ResponderCertificationGuard::class)]
final class ResponderCertificationGuardRestRequestTest extends TestCase
{
    private const FIELD = 'service-layout-group_responder-certification';
    private const KEY   = 'field_6a5a5d9e7dcec';

    #[PreserveGlobalState(false)]
    #[RunInSeparateProcess]
    #[Test]
    public function it_lets_writes_through_during_rest_requests(): void
    {
        define('REST_REQUEST', true);

        $GLOBALS['scrutiny_test_capabilities'] = [];

        // A stored value is present and the caller has no capability. If the
        // REST guard did not take effect first, the stored value would be
        // preserved instead of the new one.
        $GLOBALS['scrutiny_test_acf_fields'][23462][self::FIELD] = 'Certified';

        $configuration = $this->createMock(Configuration::class);
        $configuration->method('getConfig')
            ->with(Member::class)
            ->willReturn([
                'FIELD_RESPONDER_CERTIFICATION' => self::FIELD,
                'KEY_RESPONDER_CERTIFICATION'   => self::KEY,
                'POST_TYPE'                     => 'member',
            ]);

        $result = (new ResponderCertificationGuard($configuration))->preserveCertification(
            'Pending',
            23462,
            ['name' => self::FIELD, 'key' => self::KEY]
        );

        $this->assertSame('Pending', $result);
    }
}
