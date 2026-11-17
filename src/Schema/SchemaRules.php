<?php

namespace Mcrud\Crud\Schema;

use Mcrud\Crud\Support\ColumnTypes;

/** Validation rules for a schema payload, shared by the studio endpoints and the Artisan commands. */
final class SchemaRules
{
    /** @return array<string, list<string>> */
    public static function rules(): array
    {
        return [
            'project' => ['required', 'array'],
            'project.name' => ['required', 'string', 'max:120'],
            'project.description' => ['nullable', 'string', 'max:500'],
            'project.locale' => ['required', 'in:en,ar'],
            'project.roles' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9_ ,-]+$/'],
            'tables' => ['required', 'array', 'min:1', 'max:12'],
            'tables.*.name' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z][A-Za-z0-9_]*$/'],
            'tables.*.fields' => ['required', 'array', 'min:1', 'max:30'],
            'tables.*.fields.*.name' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z][A-Za-z0-9_]*$/'],
            'tables.*.fields.*.type' => ['required', 'in:'.implode(',', ColumnTypes::names())],
            'tables.*.fields.*.nullable' => ['nullable', 'boolean'],
            'tables.*.fields.*.unique' => ['nullable', 'boolean'],
            'tables.*.existing' => ['nullable', 'boolean'],
            'tables.*.fields.*.existing' => ['nullable', 'boolean'],
            'tables.*.soft_deletes' => ['nullable', 'boolean'],
            'tables.*.timestamps' => ['nullable', 'boolean'],
            'tables.*.fields.*.values' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9_ ,.\\-]+$/'],
            'tables.*.fields.*.rules' => ['nullable', 'string', 'max:255', 'regex:/^[^\'"`;{}$]+$/'],
            'tables.*.fields.*.index' => ['nullable', 'boolean'],
            'tables.*.fields.*.rename_from' => ['nullable', 'string', 'max:80', 'regex:/^[A-Za-z][A-Za-z0-9_]*$/'],
            'tables.*.fields.*.changed' => ['nullable', 'boolean'],
            'tables.*.indexes' => ['nullable', 'array', 'max:20'],
            'tables.*.indexes.*.columns' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9_, ]+$/'],
            'tables.*.indexes.*.unique' => ['nullable', 'boolean'],
            'tables.*.indexes.*.existing' => ['nullable', 'boolean'],
            'tables.*.dropped' => ['nullable', 'array', 'max:30'],
            'tables.*.dropped.*' => ['string', 'regex:/^[A-Za-z][A-Za-z0-9_]*$/'],
            'api' => ['nullable', 'boolean'],
            'web' => ['nullable', 'boolean'],
            'tables.*.fields.*.default' => ['nullable', 'string', 'max:100'],
            'relations' => ['nullable', 'array', 'max:40'],
            'relations.*.from' => ['required', 'string', 'max:80'],
            'relations.*.to' => ['required', 'string', 'max:80'],
            'relations.*.type' => ['required', 'in:hasMany,hasOne,belongsTo,belongsToMany'],
            'relations.*.nullable' => ['nullable', 'boolean'],
            'relations.*.existing' => ['nullable', 'boolean'],
            'relations.*.key' => ['nullable', 'string', 'max:80', 'regex:/^[A-Za-z][A-Za-z0-9_]*$/'],
            'locales' => ['required', 'array', 'min:1'],
            'locales.*' => ['in:en,ar'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['in:view,create,update,delete'],
            'roles' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9_ ,-]+$/'],
            'dashboard' => ['nullable', 'boolean'],
            'layout' => ['nullable', 'in:admin,simple'],
            'auth' => ['nullable', 'boolean'],
            'auth_register' => ['nullable', 'boolean'],
            'auth_verify' => ['nullable', 'boolean'],
            'migrate' => ['nullable', 'boolean'],
            'sample_data' => ['nullable', 'boolean'],
            'sample_rows' => ['nullable', 'integer', 'between:1,100'],
            'generate_tests' => ['nullable', 'boolean'],
            'confirm_migrate' => ['nullable', 'boolean'],
        ];
    }
}
