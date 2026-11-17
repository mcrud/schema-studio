<?php

namespace Mcrud\Crud\Console;

use Illuminate\Console\Command;
use Mcrud\Crud\Support\ColumnTypes;

class TypesCrudCommand extends Command
{
    protected $signature = 'crud:types {--json : Print the types as JSON}';
    protected $description = 'List the column types a schema can use, with their validation rule, cast and form input';

    public function handle(): int
    {
        $rows = array_map(fn (string $type) => ['type' => $type, 'rule' => ColumnTypes::rule($type), 'cast' => ColumnTypes::cast($type) ?? '', 'input' => ColumnTypes::input($type)], ColumnTypes::names());
        if ($this->option('json')) {
            $this->line((string) json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }
        $this->table(['Type', 'Validation', 'Cast', 'Input'], array_map('array_values', $rows));

        return self::SUCCESS;
    }
}
