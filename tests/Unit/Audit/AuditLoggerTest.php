<?php

declare(strict_types=1);

namespace Scrutiny\Tests\Unit\Audit;

use PHPUnit\Framework\TestCase;
use Scrutiny\Audit\GdprAuditLogger;
use Scrutiny\Audit\Interfaces\AuditLogger;
use Scrutiny\Audit\Interfaces\AuditRepository;
use Scrutiny\Privacy\PersonalDataFields;
use Mockery;
use WP_Mock;

/**
 * Tests for GdprAuditLogger
 */
class AuditLoggerTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Create an GdprAuditLogger without WP dependencies by using reflection
     */
    private function createLogger(AuditRepository $repository): GdprAuditLogger
    {
        $reflection = new \ReflectionClass(GdprAuditLogger::class);
        $instance = $reflection->newInstanceWithoutConstructor();

        $prop = $reflection->getProperty('repository');
        $prop->setAccessible(true);
        $prop->setValue($instance, $repository);

        return $instance;
    }

    /** @test */
    public function log_batch_calls_log_for_each_field(): void
    {
        // Previously this test could not call logBatch() at all — log() reaches
        // for wp_get_current_user() and get_current_user_id() — so it set a
        // times(3) expectation it never met and asserted something unrelated.
        // WP_Mock can stub those now, so it exercises the real delegation.
        WP_Mock::userFunction('wp_get_current_user')
            ->andReturn((object) ['user_login' => 'auditor']);
        WP_Mock::userFunction('get_current_user_id')
            ->andReturn(7);

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
}