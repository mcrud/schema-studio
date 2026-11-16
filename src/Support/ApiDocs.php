<?php

namespace Mcrud\Crud\Support;

use Illuminate\Support\Str;

/** Builds OpenAPI 3 and Postman v2.1 documents for the generated REST resources. */
final class ApiDocs
{
    public function openApi(array $tables, string $title): array
    {
        $paths = [];
        $schemas = [];
        foreach ($tables as $t) {
            $schemas[$t['model']] = $this->schema($t);
            $base = "/{$t['table']}";
            $ref = ['$ref' => "#/components/schemas/{$t['model']}"];
            $id = [['name' => $Str = Str::singular($t['table']), 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']]];
            $body = ['required' => true, 'content' => ['application/json' => ['schema' => $ref]]];
            $list = [['name' => 'q', 'in' => 'query', 'schema' => ['type' => 'string']], ['name' => 'sort', 'in' => 'query', 'schema' => ['type' => 'string']], ['name' => 'dir', 'in' => 'query', 'schema' => ['type' => 'string', 'enum' => ['asc', 'desc']]], ['name' => 'per_page', 'in' => 'query', 'schema' => ['type' => 'integer']], ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer']]];
            $one = fn (string $description) => ['200' => ['description' => $description, 'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['data' => $ref]]]]]];
            $paths[$base] = [
                'get' => ['summary' => "List {$t['table']}", 'tags' => [$t['model']], 'parameters' => $list, 'responses' => ['200' => ['description' => 'Paginated list', 'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['data' => ['type' => 'array', 'items' => $ref]]]]]]]],
                'post' => ['summary' => "Create {$t['model']}", 'tags' => [$t['model']], 'requestBody' => $body, 'responses' => ['201' => ['description' => 'Created'], '422' => ['description' => 'Validation failed']]],
            ];
            $paths["{$base}/{{$Str}}"] = [
                'get' => ['summary' => "Show {$t['model']}", 'tags' => [$t['model']], 'parameters' => $id, 'responses' => $one('The record')],
                'put' => ['summary' => "Update {$t['model']}", 'tags' => [$t['model']], 'parameters' => $id, 'requestBody' => $body, 'responses' => $one('Updated') + ['422' => ['description' => 'Validation failed']]],
                'delete' => ['summary' => "Delete {$t['model']}", 'tags' => [$t['model']], 'parameters' => $id, 'responses' => ['204' => ['description' => 'Deleted']]],
            ];
        }

        return [
            'openapi' => '3.0.3',
            'info' => ['title' => "{$title} API", 'version' => '1.0.0'],
            'servers' => [['url' => '{baseUrl}/api', 'variables' => ['baseUrl' => ['default' => 'http://localhost']]]],
            'paths' => $paths,
            'components' => ['schemas' => $schemas] + (config('crud.api_auth') === 'sanctum' ? ['securitySchemes' => ['bearer' => ['type' => 'http', 'scheme' => 'bearer']]] : []),
        ] + (config('crud.api_auth') === 'sanctum' ? ['security' => [['bearer' => []]]] : []);
    }

    public function postman(array $tables, string $title): array
    {
        $items = [];
        foreach ($tables as $t) {
            $url = fn (string $path) => ['raw' => "{{baseUrl}}/api/{$t['table']}{$path}", 'host' => ['{{baseUrl}}'], 'path' => array_values(array_filter(['api', $t['table'], ...explode('/', trim($path, '/'))]))];
            $payload = json_encode($this->example($t), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $request = fn (string $method, string $path, bool $body = false) => ['method' => $method, 'header' => [['key' => 'Accept', 'value' => 'application/json'], ['key' => 'Content-Type', 'value' => 'application/json']], 'url' => $url($path)] + ($body ? ['body' => ['mode' => 'raw', 'raw' => $payload]] : []);
            $items[] = ['name' => $t['model'], 'item' => [
                ['name' => 'List', 'request' => $request('GET', '')],
                ['name' => 'Create', 'request' => $request('POST', '', true)],
                ['name' => 'Show', 'request' => $request('GET', '/1')],
                ['name' => 'Update', 'request' => $request('PUT', '/1', true)],
                ['name' => 'Delete', 'request' => $request('DELETE', '/1')],
            ]];
        }

        return [
            'info' => ['name' => "{$title} API", 'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json'],
            'variable' => [['key' => 'baseUrl', 'value' => 'http://localhost'], ['key' => 'token', 'value' => '']],
            'auth' => ['type' => 'bearer', 'bearer' => [['key' => 'token', 'value' => '{{token}}', 'type' => 'string']]],
            'item' => $items,
        ];
    }

    public static function mergeOpenApi(array $old, array $new): array
    {
        $old['paths'] = array_merge($old['paths'] ?? [], $new['paths']);
        $old['components']['schemas'] = array_merge($old['components']['schemas'] ?? [], $new['components']['schemas']);

        return $old;
    }

    public static function mergePostman(array $old, array $new): array
    {
        $names = array_column($new['item'], 'name');
        $old['item'] = array_merge(array_values(array_filter($old['item'] ?? [], fn ($i) => !in_array($i['name'], $names, true))), $new['item']);

        return $old;
    }

    private function schema(array $t): array
    {
        $properties = ['id' => ['type' => 'integer', 'readOnly' => true]];
        $required = [];
        foreach ($t['fields'] as $f) {
            $properties[$f['name']] = $this->property($f);
            if (!$f['nullable']) $required[] = $f['name'];
        }
        foreach ($t['relations'] as $r) {
            if ($r['type'] === 'belongsToMany') $properties[$r['name']] = ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Related ids to sync'];
        }

        return ['type' => 'object', 'properties' => $properties] + ($required ? ['required' => $required] : []);
    }

    private function property(array $f): array
    {
        if (!empty($f['foreign'])) return ['type' => 'integer', 'nullable' => $f['nullable'], 'description' => "Id in {$f['foreign']}"];
        $schema = match ($f['type']) {
            'integer', 'bigInteger' => ['type' => 'integer'],
            'float', 'decimal' => ['type' => 'number'],
            'boolean' => ['type' => 'boolean'],
            'date' => ['type' => 'string', 'format' => 'date'],
            'dateTime', 'dateTimeTz' => ['type' => 'string', 'format' => 'date-time'],
            'time' => ['type' => 'string', 'example' => '14:30'],
            'uuid' => ['type' => 'string', 'format' => 'uuid'],
            'email' => ['type' => 'string', 'format' => 'email'],
            'url' => ['type' => 'string', 'format' => 'uri'],
            'json' => ['type' => 'object'],
            'enum' => ['type' => 'string', 'enum' => $f['values']],
            default => ['type' => 'string'],
        };

        return $schema + ($f['nullable'] ? ['nullable' => true] : []);
    }

    private function example(array $t): array
    {
        $example = [];
        foreach ($t['fields'] as $f) {
            $example[$f['name']] = match (true) {
                !empty($f['foreign']) => 1,
                in_array($f['type'], ['integer', 'bigInteger'], true) => 1,
                in_array($f['type'], ['float', 'decimal'], true) => 9.99,
                $f['type'] === 'boolean' => true,
                $f['type'] === 'date' => '2025-01-31',
                in_array($f['type'], ['dateTime', 'dateTimeTz'], true) => '2025-01-31 10:00:00',
                $f['type'] === 'time' => '14:30',
                $f['type'] === 'email' => 'user@example.com',
                $f['type'] === 'url' => 'https://example.com',
                $f['type'] === 'uuid' => '3f1d9c1a-8a9b-4b1a-9f55-0c6c1c1a2b3c',
                $f['type'] === 'enum' => $f['values'][0] ?? '',
                $f['type'] === 'json' => '{}',
                default => 'example',
            };
        }
        foreach ($t['relations'] as $r) if ($r['type'] === 'belongsToMany') $example[$r['name']] = [1];

        return $example;
    }
}
