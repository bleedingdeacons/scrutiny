<?php

declare(strict_types=1);

namespace Scrutiny\Tests\Unit\Audit;

use BleedingDeacons\WpMocks\WpState;
use Brain\Monkey\Functions;
use Mockery;
use Scrutiny\Audit\GdprAuditLogger;
use Scrutiny\Audit\Interfaces\AuditLogger;
use Scrutiny\Audit\Interfaces\AuditRepository;
use Scrutiny\Privacy\PersonalDataFields;
use Scrutiny\Tests\TestCase;

/**
 * Tests for GdprAuditLogger
 */
class AuditLoggerTest extends TestCase
{
    /**
     * Create an GdprAuditLogger without WP dependencies by using reflection
     */
    private function createLogger(AuditRepository $repository): GdprAuditLogger
    {
        $reflection = new \ReflectionClass(GdprAuditLogger::class);
        $instance = $reflection->newInstanceWithoutConstructor();

        // No setAccessible() call: a no-op since PHP 8.1 — this plugin's
        // floor — and deprecated as of 8.5.
        $reflection->getProperty('repository')->setValue($instance, $repository);

        return $instance;
    }

    /** @test */
    public function log_batch_calls_log_for_each_field(): void
    {
        // Previously this test could not call logBatch() at all — log() reaches
        // for wp_get_current_user() and get_current_user_id() — so it set a
        // times(3) expectation it never met and asserted something unrelated.
        // Both are available now, so it exercises the real delegation.
        Functions\when('wp_get_current_user')->justReturn($this->currentUser('auditor'));
        WpState::$currentUserId = 7;

        $fields = [
            PersonalDataFields::PERSONAL_EMAIL,
            PersonalDataFields::MOBILE_NUMBER,
        ];

        $inserted = [];
        $repository = Mockery::mock(AuditRepository::class);
        $repository->shouldReceive('insert')
            ->times(count($fields))
            ->andReturnUsing(function (array $row) use (&$inserted): int {
                $inserted[] = $row;
                return 1;
            });

        $logger = $this->createLogger($repository);

        $logger->logBatch(
            AuditLogger::ACTION_VIEW,
            AuditLogger::ENTITY_MEMBER,
            42,
            $fields,
            'Bulk export'
        );

        $this->assertSame($fields, array_column($inserted, 'field_name'));
        $this->assertSame([42, 42], array_column($inserted, 'entity_id'));
        $this->assertSame(['auditor', 'auditor'], array_column($inserted, 'user_login'));
    }

    /** @test */
    public function personal_data_fields_are_correctly_defined(): void
    {
        // Hyphens, not underscores. These values are the audit log's field_name
        // column and the keys of PersonalDataFields::LABELS, and both have used
        // the hyphenated form throughout.
        $this->assertSame('personal-email', PersonalDataFields::PERSONAL_EMAIL);
        $this->assertSame('mobile-number', PersonalDataFields::MOBILE_NUMBER);
    }

    /** @test */
    public function all_fields_constant_contains_all_fields(): void
    {
        $this->assertContains(PersonalDataFields::PERSONAL_EMAIL, PersonalDataFields::ALL_FIELDS);
        $this->assertContains(PersonalDataFields::MOBILE_NUMBER, PersonalDataFields::ALL_FIELDS);
        $this->assertCount(2, PersonalDataFields::ALL_FIELDS);
    }

    /** @test */
    public function labels_exist_for_all_fields(): void
    {
        foreach (PersonalDataFields::ALL_FIELDS as $field) {
            $this->assertArrayHasKey($field, PersonalDataFields::LABELS);
            $this->assertNotEmpty(PersonalDataFields::LABELS[$field]);
        }
    }

    /**
     * A current-user stand-in with the given login.
     *
     * wp-mocks types wp_get_current_user() as returning WP_User, and Patchwork
     * keeps a function's original signature when Brain Monkey redefines it, so
     * an ad-hoc stdClass is a TypeError now — which is the more faithful
     * behaviour anyway: real WordPress always hands back a WP_User.
     */
    private function currentUser(string $login): \WP_User
    {
        $user = new \WP_User();
        $user->user_login = $login;

        return $user;
    }
}
