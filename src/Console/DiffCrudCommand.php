<?php

namespace Mcrud\Crud\Console;

use Illuminate\Console\Command;
use Mcrud\Crud\Console\Concerns\ReadsSchemaFile;
use Mcrud\Crud\Generators\CrudGenerator;

class DiffCrudCommand extends Command
{
    use ReadsSchemaFile;

    protected $signature = 'crud:diff {schema : Path to an exported Schema Studio JSON file} {--stat : List file statuses without printing diffs}';
    protected $description = 'Show how already generated files differ from what the schema would generate now';

    public function handle(CrudGenerator $generator): int
    {
        $data = $this->schemaData($this->argument('schema'));
        if ($data === null) return self::FAILURE;

        $changed = 0;
        foreach ($generator->diff($data) as $file) {
            $label = match ($file['status']) { 'missing' => '<fg=yellow>missing  </>', 'changed' => '<fg=red>changed  </>', default => '<fg=green>identical</>' };
            $this->line("{$label} {$file['path']}");
            if ($file['status'] === 'changed') {
                $changed++;
                if (!$this->option('stat')) $this->line($file['diff']."\n");
            }
        }
        $this->info($changed ? "{$changed} file(s) differ from the generated output. Run crud:sync to merge them (a .bak copy is kept); nothing was overwritten." : 'Generated files are up to date.');

        return self::SUCCESS;
    }
}
