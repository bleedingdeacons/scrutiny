<?php

declare(strict_types=1);

/**
 * Minimal stand-ins for the Advanced Custom Fields symbols
 * {@see \Scrutiny\Fields\GdprAuditHistory} builds on.
 *
 * Two consumers, one file:
 *
 *   - PHPStan reads it via `scanFiles` in phpstan.neon.dist. ACF is a
 *     third-party plugin, not a Composer dependency and not checked out
 *     beside Scrutiny in CI, so without this every reference to acf_field
 *     and friends is an "unknown class / function" error.
 *   - tests/bootstrap.php loads it so the field type can actually be
 *     instantiated in a unit run.
 *
 * Only the surface GdprAuditHistory touches is reproduced. In particular the
 * real acf_field constructor also registers the field type's info and wires
 * some three dozen hook callbacks; here it does the one thing the field type
 * depends on — calling initialize().
 *
 * Everything is guarded, so a run that somehow has the real ACF loaded uses
 * that instead.
 */

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace
// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps

if (!class_exists('acf_field')) {
    /**
     * Stand-in for ACF's field type base class.
     *
     * Properties are untyped, matching the real class — child classes
     * redeclare some of them (GdprAuditHistory redeclares $show_in_rest),
     * which PHP only permits when neither declaration has a type.
     */
    class acf_field
    {
        /** @var string */
        public $name = '';

        /** @var string */
        public $label = '';

        /** @var string */
        public $category = 'basic';

        /** @var string */
        public $description = '';

        /** @var bool */
        public $public = true;

        /** @var bool */
        public $show_in_rest = true;

        /** @var array<string, mixed> */
        public $defaults = [];

        /** @var array<string, mixed> */
        public $supports = ['required' => true];

        /** @var array<string, mixed> */
        public $l10n = [];

        public function __construct()
        {
            $this->initialize();
        }

        /**
         * @return void
         */
        public function initialize()
        {
        }

        /**
         * @param array<string, mixed> $field
         * @return void
         */
        public function render_field($field)
        {
        }

        /**
         * @param array<string, mixed> $field
         * @return void
         */
        public function render_field_settings($field)
        {
        }

        /**
         * @return void
         */
        public function input_admin_enqueue_scripts()
        {
        }
    }
}

if (!function_exists('acf_register_field_type')) {
    /**
     * Records the registered field type so tests can assert on it.
     *
     * @param acf_field|string $fieldClass
     * @return acf_field|string
     */
    function acf_register_field_type($fieldClass)
    {
        $GLOBALS['scrutiny_test_acf_field_types'][] = $fieldClass;

        return $fieldClass;
    }
}

if (!function_exists('acf_render_field_setting')) {
    /**
     * Records each rendered field setting, keyed by the setting's name, so
     * tests can assert on the settings UI without an ACF render pipeline.
     *
     * @param array<string, mixed> $field
     * @param array<string, mixed> $setting
     * @param bool                 $global
     * @return void
     */
    function acf_render_field_setting($field, $setting, $global = false)
    {
        $GLOBALS['scrutiny_test_acf_field_settings'][] = $setting;
    }
}

if (!function_exists('acf_get_form_data')) {
    /**
     * Reads from the in-memory form-data store tests populate via
     * $GLOBALS['scrutiny_test_acf_form_data'].
     *
     * @param string $name
     * @return mixed
     */
    function acf_get_form_data($name = '')
    {
        if ($name === '') {
            return $GLOBALS['scrutiny_test_acf_form_data'] ?? [];
        }

        return $GLOBALS['scrutiny_test_acf_form_data'][$name] ?? null;
    }
}
