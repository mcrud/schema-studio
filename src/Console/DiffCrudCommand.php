<?php

namespace Mcrud\Crud\Console;

use Illuminate\Console\Command;
use Mcrud\Crud\Console\Concerns\ReadsSchemaFile;
use Mcrud\Crud\Generators\CrudGenerator;

class DiffCrudCommand extends Command
{
    use ReadsSchemaFile;

    protected $signature = 'crud:diff {schema : Path to an exported Schema Studio JSON file} {--stat : List file statuses without printing diffs} {--json : Print the file statuses as JSON}';
    protected $description = 'Show how already generated files differ from what the schema would generate now';

    public function handle(CrudGenerator $generator): int
    {
        $data = $this->schemaData($this->argument('schema'));
        if ($data === null) return self::FAILURE;

        if ($this->option('json')) {
            $files = array_map(fn (array $file) => $this->option('stat') ? ['path' => $file['path'], 'status' => $file['status']] : $file, $generator->diff($data));
            $this->line((string) json_encode($files, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

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
