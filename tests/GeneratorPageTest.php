<?php

namespace Mcrud\Crud\Tests;

use PHPUnit\Framework\TestCase;

class GeneratorPageTest extends TestCase
{
    private function view(): string { return (string) file_get_contents(__DIR__.'/../resources/views/generator.blade.php'); }

    public function test_generator_page_renders_a_three_step_schema_designer(): void
    {
        $view = $this->view();
        foreach (['Project brief', 'Schema canvas', 'Column inspector', 'created_at', 'updated_at'] as $expected) self::assertStringContainsString($expected, $view);
    }

    public function test_template_payloads_include_the_requested_starter_fields(): void
    {
        $view = $this->view();
        foreach (['Online Store', 'SaaS Workspace', 'Clinic', 'Restaurant', "['sku','string']", "['starts_at','date']", "['birth_date','date']", "['available','boolean']", 'tables[${ti}][fields][${index}][${key}]', 'relations[${ri}][type]'] as $expected) self::assertStringContainsString($expected, $view);
    }

    public function test_hidden_wizard_panels_do_not_use_native_form_validation(): void
    {
        $view = $this->view();
        foreach (['id="studio-form" novalidate', 'function validateCurrent()', 'if(!validateCurrent())'] as $expected) self::assertStringContainsString($expected, $view);
    }

    public function test_backend_validation_still_protects_resource_payloads(): void
    {
        $controller = (string) file_get_contents(__DIR__.'/../src/Http/Controllers/CrudGeneratorController.php');
        foreach (["'tables' => ['required', 'array', 'min:1'", "'tables.*.fields' => ['required', 'array', 'min:1'", "'tables.*.fields.*.type' => ['required', 'in:string,text,integer,bigInteger,boolean,date,dateTime,decimal']", "'relations.*.type' => ['required', 'in:hasMany,hasOne,belongsToMany']"] as $expected) self::assertStringContainsString($expected, $controller);
    }

    public function test_canvas_supports_multiple_tables_with_relations(): void
    {
        $view = $this->view();
        foreach (['id="add-table"', 'id="auto-layout"', 'link-handle', 'belongsToMany', 'hasMany', 'hasOne', 'function drawLinks()'] as $expected) self::assertStringContainsString($expected, $view);
    }
}
