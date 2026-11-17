<?php

namespace Mcrud\Crud\Console;

use Illuminate\Console\Command;
use Mcrud\Crud\Console\Concerns\ReadsSchemaFile;
use Mcrud\Crud\Generators\CrudGenerator;
use Mcrud\Crud\Schema\SchemaValidator;

class GenerateCrudCommand extends Command
{
    use ReadsSchemaFile;

    protected $signature = 'crud:generate {schema : Path to an exported Schema Studio JSON file} {--dry-run : Show the generation manifest only} {--migrate : Run pending migrations after generation} {--auth : Also generate login, profile and user management} {--skip-validation : Do not check the schema before generating}';
    protected $description = 'Generate Laravel CRUD resources from a Schema Studio JSON export';

    public function handle(CrudGenerator $generator, SchemaValidator $validator): int
    {
        $data = $this->schemaData($this->argument('schema'), (bool) $this->option('auth'));
        if ($data === null) return self::FAILURE;
        if (!$this->option('skip-validation') && ($errors = $validator->errors($data)) !== []) {
            $this->error('The schema has '.count($errors).' problem(s); nothing was generated:');
            foreach ($errors as $error) $this->line(" - {$error}");

            return self::FAILURE;
        }
        $preview = $generator->preview($data);
        $this->table(['Creates', 'Files'], [[implode(', ', $preview['create_migrations']), count($preview['files'])]]);
        if ($this->option('dry-run')) return self::SUCCESS;
        $report = $generator->generate($data);
        $this->info('CRUD generated.');
        foreach ($report['docs'] as $doc) $this->line("API docs: {$doc}");
        if ($this->option('migrate')) foreach ($generator->migrateAndSeed($report['seeders']) as $message) $this->line($message);
        return self::SUCCESS;
    }
}
