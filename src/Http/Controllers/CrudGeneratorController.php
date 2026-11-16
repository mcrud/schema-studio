<?php

namespace Mcrud\Crud\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Mcrud\Crud\Generators\CrudGenerator;

class CrudGeneratorController
{
    public function index(): View
    {
        $path = storage_path('app/crud-schema-studio/project.json');
        $project = is_file($path) ? json_decode((string) file_get_contents($path), true) : [];

        return view('crud::generator', ['project' => is_array($project) ? $project : []]);
    }

    public function store(Request $request, CrudGenerator $generator): RedirectResponse
    {
        $data = $request->validate([
            'project' => ['required', 'array'],
            'project.name' => ['required', 'string', 'max:120'],
            'project.description' => ['nullable', 'string', 'max:500'],
            'project.locale' => ['required', 'in:en,ar'],
            'project.roles' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9_ ,-]+$/'],
            'tables' => ['required', 'array', 'min:1', 'max:12'],
            'tables.*.name' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z][A-Za-z0-9_]*$/'],
            'tables.*.fields' => ['required', 'array', 'min:1', 'max:30'],
            'tables.*.fields.*.name' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z][A-Za-z0-9_]*$/'],
            'tables.*.fields.*.type' => ['required', 'in:string,text,integer,bigInteger,boolean,date,dateTime,decimal'],
            'tables.*.fields.*.nullable' => ['nullable', 'boolean'],
            'tables.*.fields.*.unique' => ['nullable', 'boolean'],
            'tables.*.fields.*.default' => ['nullable', 'string', 'max:100'],
            'relations' => ['nullable', 'array', 'max:40'],
            'relations.*.from' => ['required', 'string', 'max:80'],
            'relations.*.to' => ['required', 'string', 'max:80'],
            'relations.*.type' => ['required', 'in:hasMany,hasOne,belongsToMany'],
            'relations.*.nullable' => ['nullable', 'boolean'],
            'locales' => ['required', 'array', 'min:1'],
            'locales.*' => ['in:en,ar'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['in:view,create,update,delete'],
            'roles' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9_ ,-]+$/'],
            'dashboard' => ['nullable', 'boolean'],
            'migrate' => ['nullable', 'boolean'],
        ]);
        $report = $generator->generate($data);
        $messages = ['CRUD created for all tables.'];
        if ($report['dashboard']) $messages[] = 'Dashboard ready at /dashboard.';
        if ($request->boolean('migrate')) $messages = array_merge($messages, $generator->migrateAndSeed($report['seeders']));
        else $messages[] = 'Run php artisan migrate to create the tables.';

        return back()->with('status', implode(' ', $messages));
    }
}
