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
            'name' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z][A-Za-z0-9_]*$/'],
            'fields' => ['required', 'array', 'min:1', 'max:30'],
            'fields.*.name' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z][A-Za-z0-9_]*$/'],
            'fields.*.type' => ['required', 'in:string,text,integer,bigInteger,boolean,date,dateTime,decimal'],
            'fields.*.nullable' => ['nullable', 'boolean'],
            'fields.*.unique' => ['nullable', 'boolean'],
            'fields.*.default' => ['nullable', 'string', 'max:100'],
            'locales' => ['required', 'array', 'min:1'],
            'locales.*' => ['in:en,ar'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['in:view,create,update,delete'],
            'roles' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9_ ,-]+$/'],
            'dashboard' => ['nullable', 'boolean'],
        ]);
        $generator->generate($data);
        return back()->with('status', 'CRUD created. Run php artisan migrate to create the table.');
    }
}
