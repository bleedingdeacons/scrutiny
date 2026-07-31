<?php

declare(strict_types=1);

namespace Scrutiny\Tests\Unit\Privacy;

use Brain\Monkey\Filters;
use Scrutiny\Privacy\PersonalDataFields;
use Scrutiny\Tests\TestCase;

/**
 * Tests for PersonalDataFields label lookup and the filterable
 * protected-contact-field list.
 *
 * @covers \Scrutiny\Privacy\PersonalDataFields
 */
class PersonalDataFieldsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    /**
     * @test
     */
    public function get_label_returns_the_mapped_label(): void
    {
        $this->assertSame('Personal Email', PersonalDataFields::getLabel(PersonalDataFields::PERSONAL_EMAIL));
        $this->assertSame('Mobile Number', PersonalDataFields::getLabel(PersonalDataFields::MOBILE_NUMBER));
        $this->assertSame('All fields', PersonalDataFields::getLabel(PersonalDataFields::ALL_FIELDS_SENTINEL));
        $this->assertSame('GDPR Accepted', PersonalDataFields::getLabel(PersonalDataFields::GDPR_ACCEPTED));
    }

    /**
     * @test
     */
    public function get_label_maps_legacy_underscore_names_to_canonical_labels(): void
    {
        $this->assertSame('Personal Email', PersonalDataFields::getLabel('personal_email'));
        $this->assertSame('Mobile Number', PersonalDataFields::getLabel('mobile_number'));
    }

    /**
     * @test
     */
    public function get_label_returns_the_field_name_verbatim_when_unknown(): void
    {
        $this->assertSame('something-else', PersonalDataFields::getLabel('something-else'));
    }

    /**
     * @test
     */
    public function protected_contact_fields_returns_the_default_set_unfiltered(): void
    {
        // With no expectation registered, Brain Monkey's apply_filters returns
        // the value unchanged — the default set.
        $fields = PersonalDataFields::protectedContactFields();

        $this->assertSame([
            'contact_1_email', 'contact_1_phone',
            'contact_2_email', 'contact_2_phone',
            'contact_3_email', 'contact_3_phone',
        ], $fields);
    }

    /**
     * @test
     */
    public function protected_contact_fields_honours_a_filter_override_and_normalises_it(): void
    {
        $default = [
            'contact_1_email', 'contact_1_phone',
            'contact_2_email', 'contact_2_phone',
            'contact_3_email', 'contact_3_phone',
        ];

        // The filter narrows the list; non-string/empty entries are then
        // dropped and the keys reindexed by the method.
        Filters\expectApplied('scrutiny_tsml_protected_fields')
            ->once()
            ->with($default)
            ->andReturn(['contact_1_email', '', 'contact_2_phone']);

        $this->assertSame(
            ['contact_1_email', 'contact_2_phone'],
            PersonalDataFields::protectedContactFields()
        );
    }
}
