<?php

namespace Mcrud\Crud\Schema;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mcrud\Crud\Support\ColumnTypes;
use RuntimeException;

/**
 * Reads the connected database and describes it in the same shape the designer uses,
 * so existing tables can be dropped onto the canvas.
 */
class SchemaIntrospector
{
    /** @var list<string> */
    private const SYSTEM_TABLES = [
        'migrations', 'password_resets', 'password_reset_tokens', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches',
        'failed_jobs', 'personal_access_tokens', 'permissions', 'roles', 'model_has_permissions', 'model_has_roles', 'role_has_permissions',
        'sqlite_sequence', 'telescope_entries', 'telescope_entries_tags', 'telescope_monitoring', 'pulse_aggregates', 'pulse_entries', 'pulse_values',
    ];

    /**
     * @return array{tables: list<array<string, mixed>>, relations: list<array<string, mixed>>, connection: string}
     */
    public function describe(): array
    {
        $builder = Schema::getFacadeRoot();
        foreach (['getTables', 'getColumns', 'getIndexes', 'getForeignKeys'] as $method) {
            if (!method_exists($builder, $method)) throw new RuntimeException('Importing from a database needs Laravel 11 or newer (Schema::getForeignKeys is missing).');
        }

        $exclude = array_merge(self::SYSTEM_TABLES, (array) config('crud.import_exclude', []));
        $raw = [];
        foreach (Schema::getTables() as $table) {
            $name = $table['name'];
            if (in_array($name, $exclude, true) || str_starts_with($name, 'sqlite_')) continue;
            $raw[$name] = [
                'columns' => Schema::getColumns($name),
                'indexes' => Schema::getIndexes($name),
                'foreign' => array_values(array_filter(Schema::getForeignKeys($name), fn ($fk) => count($fk['columns']) === 1 && ($fk['foreign_schema'] ?? null) !== 'other')),
            ];
        }

        $pivots = $this->detectPivots($raw);
        $tables = [];
        $relations = [];
        foreach ($raw as $name => $info) {
            if (isset($pivots[$name])) {
                [$a, $b] = $pivots[$name];
                $relations[] = ['from' => $this->modelName($a), 'to' => $this->modelName($b), 'type' => 'belongsToMany', 'nullable' => false, 'existing' => true];
                continue;
            }
            $tables[] = $this->describeTable($name, $info);
            foreach ($info['foreign'] as $fk) {
                if (!isset($raw[$fk['foreign_table']]) || isset($pivots[$fk['foreign_table']])) continue;
                $column = collect($info['columns'])->firstWhere('name', $fk['columns'][0]);
                $default = $fk['columns'][0] === Str::singular($fk['foreign_table']).'_id';
                $relations[] = [
                    'from' => $this->modelName($fk['foreign_table']), 'to' => $this->modelName($name),
                    'type' => $this->isUnique($info['indexes'], $fk['columns'][0]) ? 'hasOne' : 'hasMany',
                    'nullable' => (bool) ($column['nullable'] ?? false), 'existing' => true,
                ] + ($default ? [] : ['key' => $fk['columns'][0]]);
            }
        }

        return ['tables' => $tables, 'relations' => $relations, 'connection' => (string) config('database.default')];
    }

    /** @return array<string, array{0: string, 1: string}> pivot table => [parent table, parent table] */
    private function detectPivots(array $raw): array
    {
        $pivots = [];
        foreach ($raw as $name => $info) {
            $fks = $info['foreign'];
            if (count($fks) !== 2) continue;
            $fkColumns = array_map(fn ($fk) => $fk['columns'][0], $fks);
            $others = collect($info['columns'])->pluck('name')->diff($fkColumns)->diff(['id', 'created_at', 'updated_at']);
            $parents = array_map(fn ($fk) => $fk['foreign_table'], $fks);
            if ($others->isNotEmpty() || $parents[0] === $parents[1] || !isset($raw[$parents[0]], $raw[$parents[1]])) continue;
            $expected = collect($parents)->map(fn ($t) => Str::singular($t))->sort()->implode('_');
            if ($expected !== $name) continue;
            sort($parents);
            $pivots[$name] = $parents;
        }

        return $pivots;
    }

    private function modelName(string $table): string
    {
        return Str::studly(Str::singular($table));
    }

    private function isUnique(array $indexes, string $column): bool
    {
        return collect($indexes)->contains(fn ($i) => ($i['unique'] ?? false) && !($i['primary'] ?? false) && $i['columns'] === [$column]);
    }

    private function describeTable(string $name, array $info): array
    {
        $names = collect($info['columns'])->pluck('name');
        $fkColumns = collect($info['foreign'])->map(fn ($fk) => $fk['columns'][0]);
        $timestamps = $names->contains('created_at') && $names->contains('updated_at');
        $soft = $names->contains('deleted_at');
        $skip = ['id'];
        if ($timestamps) array_push($skip, 'created_at', 'updated_at');
        if ($soft) $skip[] = 'deleted_at';

        $columns = [];
        foreach ($info['columns'] as $c) {
            if (in_array($c['name'], $skip, true) || $fkColumns->contains($c['name'])) continue;
            $columns[] = $this->describeColumn($c, $this->isUnique($info['indexes'], $c['name']));
        }

        $problem = null;
        if (!$names->contains('id')) $problem = 'No "id" column. The generated CRUD expects an id primary key.';
        elseif (!preg_match('/^[a-z][a-z0-9_]*$/', $name) || Str::plural(Str::snake($this->modelName($name))) !== $name) $problem = 'Its name does not follow the plural snake_case convention the generator uses.';
        elseif (!$columns) $problem = 'It has no importable columns besides id, timestamps and foreign keys.';

        return [
            'name' => $this->modelName($name), 'table' => $name, 'columns' => $columns,
            'timestamps' => $timestamps, 'softDeletes' => $soft, 'foreignKeys' => $fkColumns->count(),
            'importable' => $problem === null, 'problem' => $problem,
        ];
    }

    private function describeColumn(array $c, bool $unique): array
    {
        $typeName = strtolower((string) ($c['type_name'] ?? ''));
        $full = strtolower((string) ($c['type'] ?? $typeName));
        $values = '';
        $type = match (true) {
            in_array($typeName, ['bool', 'boolean'], true) || str_contains($full, 'tinyint(1)') => 'boolean',
            $typeName === 'enum' || str_starts_with($full, 'enum(') => 'enum',
            in_array($typeName, ['bigint', 'int8', 'bigserial'], true) => 'bigInteger',
            in_array($typeName, ['int', 'integer', 'smallint', 'tinyint', 'mediumint', 'int2', 'int4', 'serial'], true) => 'integer',
            in_array($typeName, ['decimal', 'numeric'], true) => 'decimal',
            in_array($typeName, ['float', 'double', 'real', 'float4', 'float8', 'double precision'], true) => 'float',
            $typeName === 'date' => 'date',
            in_array($typeName, ['datetime', 'timestamp', 'timestamptz', 'datetime2'], true) || str_starts_with($typeName, 'timestamp') => 'dateTime',
            $typeName === 'time' || str_starts_with($typeName, 'time without') => 'time',
            in_array($typeName, ['uuid', 'uniqueidentifier'], true) || $full === 'char(36)' => 'uuid',
            in_array($typeName, ['json', 'jsonb'], true) => 'json',
            in_array($typeName, ['text', 'mediumtext', 'longtext', 'tinytext', 'clob'], true) => 'text',
            default => 'string',
        };

        if ($type === 'enum') {
            preg_match_all("/'((?:[^']|'')*)'/", (string) ($c['type'] ?? ''), $m);
            $values = implode(', ', array_map(fn ($v) => str_replace("''", "'", $v), $m[1] ?? []));
            if (ColumnTypes::enumValues($values) === []) $type = 'string';
        }

        $default = $c['default'] ?? null;
        if (is_string($default)) {
            $default = trim($default);
            $default = preg_match("/^'(.*)'$/s", $default, $m) ? str_replace("''", "'", $m[1]) : $default;
            if (strtolower($default) === 'null' || str_contains($default, '(') || preg_match('/^current_timestamp/i', $default)) $default = '';
        } else {
            $default = '';
        }

        return ['name' => $c['name'], 'type' => $type, 'nullable' => (bool) ($c['nullable'] ?? false), 'unique' => $unique, 'default' => mb_substr((string) $default, 0, 100), 'values' => $values];
    }
}
