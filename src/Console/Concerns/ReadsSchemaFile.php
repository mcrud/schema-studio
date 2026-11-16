<?php

namespace Mcrud\Crud\Console\Concerns;

/** Turns an exported Schema Studio JSON file into the payload the generator expects. */
trait ReadsSchemaFile
{
    /** @return array<string, mixed>|null Null (after printing an error) when the file cannot be read. */
    protected function schemaData(string $path, bool $auth = false): ?array
    {
        if (!is_file($path) || !($json = json_decode((string) file_get_contents($path), true))) {
            $this->error('Schema must be a readable JSON file.');

            return null;
        }

        return [
            'project' => ['name' => $json['project']['name'] ?? 'Schema Studio', 'description' => $json['project']['description'] ?? '', 'roles' => $json['project']['roles'] ?? '', 'locale' => $json['project']['locale'] ?? 'en'],
            'tables' => $json['tables'] ?? [], 'relations' => $json['relations'] ?? [], 'locales' => $json['locales'] ?? ['en'],
            'permissions' => $json['permissions'] ?? [], 'roles' => $json['roles'] ?? '', 'dashboard' => !empty($json['dashboard']),
            'layout' => $json['layout'] ?? config('crud.layout', 'admin'),
            'auth' => !empty($json['auth']) || $auth, 'auth_register' => !empty($json['auth_register']), 'auth_verify' => !empty($json['auth_verify']),
            'api' => !empty($json['api']), 'web' => !array_key_exists('web', $json) || !empty($json['web']),
            'sample_data' => !empty($json['sample_data']), 'sample_rows' => $json['sample_rows'] ?? 10,
            'generate_tests' => array_key_exists('generate_tests', $json) ? $json['generate_tests'] : config('crud.generate_tests'),
        ];
    }
}
