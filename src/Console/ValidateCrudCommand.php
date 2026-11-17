<?php

namespace Mcrud\Crud\Console;

use Illuminate\Console\Command;
use Mcrud\Crud\Console\Concerns\ReadsSchemaFile;
use Mcrud\Crud\Schema\SchemaValidator;

class ValidateCrudCommand extends Command
{
    use ReadsSchemaFile;

    protected $signature = 'crud:validate {schema : Path to an exported Schema Studio JSON file} {--json : Print the result as JSON}';
    protected $description = 'Check an exported schema for problems without generating anything';

    public function handle(SchemaValidator $validator): int
    {
        $data = $this->schemaData($this->argument('schema'));
        if ($data === null) return self::FAILURE;

        $errors = $validator->errors($data);
        if ($this->option('json')) {
            $this->line((string) json_encode(['valid' => $errors === [], 'errors' => $errors], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return $errors === [] ? self::SUCCESS : self::FAILURE;
        }
        if ($errors === []) {
            $this->info('The schema is valid.');

            return self::SUCCESS;
        }
        $this->error(count($errors).' problem(s) found:');
        foreach ($errors as $error) $this->line(" - {$error}");

        return self::FAILURE;
    }
}
