<?php

declare(strict_types=1);

namespace Scrutiny\Tests\Unit\Privacy;

use Brain\Monkey\Filters;
use Scrutiny\Privacy\PersonalDataFields;

/*
 * Tests for PersonalDataFields label lookup and the filterable
 * protected-contact-field list.
 */

covers(PersonalDataFields::class);

describe('getLabel', function () {
    it('returns the mapped label', function (string $field, string $label) {
        expect(PersonalDataFields::getLabel($field))->toBe($label);
    })->with([
        'personal email' => [PersonalDataFields::PERSONAL_EMAIL, 'Personal Email'],
        'mobile number'  => [PersonalDataFields::MOBILE_NUMBER, 'Mobile Number'],
        'all fields'     => [PersonalDataFields::ALL_FIELDS_SENTINEL, 'All fields'],
        'gdpr accepted'  => [PersonalDataFields::GDPR_ACCEPTED, 'GDPR Accepted'],
    ]);

    // Home group and intergroup position are not personal data, but they
    // still need labels: the audit log's field filter is built from LABELS,
    // so a field missing from it is a field nobody can filter by.
    it('covers the service role fields', function (string $field, string $label) {
        expect(PersonalDataFields::getLabel($field))->toBe($label);
    })->with([
        'home group'          => [PersonalDataFields::HOME_GROUP, 'Home Group'],
        'intergroup position' => [PersonalDataFields::INTERGROUP_POSITION, 'Intergroup Position'],
        'gsr'                 => [PersonalDataFields::GSR, 'GSR'],
        'position rotation'   => [PersonalDataFields::POSITION_ROTATION, 'Position Rotation'],
        '12th stepper'        => [PersonalDataFields::TWELFTH_STEPPER, '12th Stepper'],
        'area'                => [PersonalDataFields::AREA, 'Area'],
        'meeting po'          => [PersonalDataFields::MEETING_PO, 'Meeting PO'],
    ]);

    it('maps legacy underscore names to the canonical labels', function () {
        expect(PersonalDataFields::getLabel('personal_email'))->toBe('Personal Email')
            ->and(PersonalDataFields::getLabel('mobile_number'))->toBe('Mobile Number');
    });

    it('returns the field name verbatim when it is unknown', function () {
        expect(PersonalDataFields::getLabel('something-else'))->toBe('something-else');
    });
});

it('has a label for every logical field name', function () {
    // The Audit Log page builds its field filter by iterating LABELS, so a
    // constant missing from it is a field nobody can filter by. Cheaper to
    // assert here than to notice it on the screen.
    $reflection = new \ReflectionClass(PersonalDataFields::class);

    foreach ($reflection->getConstants() as $name => $value) {
        if (!is_string($value) || str_starts_with($name, 'FIELD_') || str_starts_with($name, 'KEY_')) {
            continue;
        }

        expect(PersonalDataFields::LABELS)->toHaveKey(
            $value,
            message: sprintf('%s (%s) has no entry in LABELS', $name, $value)
        );
    }
});

describe('what counts as personal data', function () {
    it('does not treat the service role fields as personal data', function () {
        // They must stay out of ALL_FIELDS and the two config maps, or the
        // obscurers would mask them and the view tracker would log reads of
        // them — neither of which applies to a public service role.
        expect(PersonalDataFields::ALL_FIELDS)
            ->not->toContain(PersonalDataFields::HOME_GROUP)
            ->not->toContain(PersonalDataFields::INTERGROUP_POSITION)
            ->not->toContain(PersonalDataFields::GSR)
            ->and(PersonalDataFields::CONFIG_KEY_MAP)
            ->not->toContain(PersonalDataFields::GSR)
            ->not->toContain(PersonalDataFields::HOME_GROUP)
            ->not->toContain(PersonalDataFields::INTERGROUP_POSITION)
            ->and(PersonalDataFields::CONFIG_ACF_KEY_MAP)
            ->not->toContain(PersonalDataFields::GSR)
            ->not->toContain(PersonalDataFields::HOME_GROUP)
            ->not->toContain(PersonalDataFields::INTERGROUP_POSITION);
    });

    // The landline is a number reaching a named individual at home, so it
    // belongs with the mobile in every set that drives obscuring and view
    // tracking.
    it('treats the landline as personal data', function () {
        expect(PersonalDataFields::ALL_FIELDS)->toContain(PersonalDataFields::LANDLINE_NUMBER)
            ->and(PersonalDataFields::CONFIG_KEY_MAP)->toContain(PersonalDataFields::LANDLINE_NUMBER)
            ->and(PersonalDataFields::CONFIG_ACF_KEY_MAP)->toContain(PersonalDataFields::LANDLINE_NUMBER)
            ->and(PersonalDataFields::LABELS)->toHaveKey(PersonalDataFields::LANDLINE_NUMBER);
    });

    // The preferred contact names one of two options rather than a number, so
    // it stays out of the personal-data sets for the same reason the service
    // roles do — while still carrying a label, because its audit entries name
    // the choice outright.
    it('does not treat the preferred contact as personal data', function () {
        expect(PersonalDataFields::ALL_FIELDS)->not->toContain(PersonalDataFields::PREFERRED_CONTACT)
            ->and(PersonalDataFields::CONFIG_KEY_MAP)->not->toContain(PersonalDataFields::PREFERRED_CONTACT)
            ->and(PersonalDataFields::CONFIG_ACF_KEY_MAP)->not->toContain(PersonalDataFields::PREFERRED_CONTACT)
            ->and(PersonalDataFields::LABELS)->toHaveKey(PersonalDataFields::PREFERRED_CONTACT);
    });
});

describe('protectedContactFields', function () {
    it('returns the default set unfiltered', function () {
        // With no expectation registered, Brain Monkey's apply_filters returns
        // the value unchanged — the default set.
        expect(PersonalDataFields::protectedContactFields())->toBe([
            'contact_1_email', 'contact_1_phone',
            'contact_2_email', 'contact_2_phone',
            'contact_3_email', 'contact_3_phone',
        ]);
    });

    it('honours a filter override and normalises it', function () {
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

        expect(PersonalDataFields::protectedContactFields())->toBe(['contact_1_email', 'contact_2_phone']);
    });
});
