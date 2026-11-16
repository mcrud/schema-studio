<?php

namespace Mcrud\Crud\Console;

use Illuminate\Console\Command;
use Mcrud\Crud\Console\Concerns\ReadsSchemaFile;
use Mcrud\Crud\Generators\CrudGenerator;

class SyncCrudCommand extends Command
{
    use ReadsSchemaFile;

    protected $signature = 'crud:sync {schema : Path to an exported Schema Studio JSON file} {--only=* : Only this project-relative file (repeatable)} {--force : Replace differing files instead of merging them}';
    protected $description = 'Update already generated files from the schema, merging with conflict markers (a .bak copy is kept)';

    public function handle(CrudGenerator $generator): int
    {
        $data = $this->schemaData($this->argument('schema'));
        if ($data === null) return self::FAILURE;

        $conflicts = 0;
        $skipped = 0;
        foreach ($generator->sync($data, (bool) $this->option('force'), array_values((array) $this->option('only'))) as $file) {
            $label = match ($file['status']) {
                'created' => '<fg=green>created    </>', 'merged' => '<fg=yellow>merged     </>', 'overwritten' => '<fg=red>overwritten</>',
                'skipped' => '<fg=magenta>skipped    </>', default => '<fg=gray>identical  </>',
            };
            $this->line("{$label} {$file['path']}".($file['conflicts'] ? " ({$file['conflicts']} conflict(s))" : ''));
            $conflicts += $file['conflicts'];
            $skipped += $file['status'] === 'skipped' ? 1 : 0;
        }
        if ($conflicts) $this->warn("Resolve the {$conflicts} conflict block(s) marked <<<<<<< current ... >>>>>>> generated. Originals are saved next to each file as *.bak.");
        if ($skipped) $this->warn("{$skipped} file(s) were skipped because they still contain unresolved conflict markers (use --force to replace them).");

        return self::SUCCESS;
    }
}
