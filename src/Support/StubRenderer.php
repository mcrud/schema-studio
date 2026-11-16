<?php

namespace Mcrud\Crud\Support;

use RuntimeException;

/** Renders resources/stubs/*.stub files, replacing [[placeholder]] tokens. */
final class StubRenderer
{
    public function __construct(private readonly string $directory = __DIR__.'/../../resources/stubs') {}

    /** @param array<string, string|int|null> $vars */
    public function render(string $stub, array $vars = []): string
    {
        $path = "{$this->directory}/{$stub}.stub";
        if (!is_file($path)) throw new RuntimeException("Missing stub: {$stub}");

        return preg_replace_callback('/\[\[\s*(\w+)\s*\]\]/', fn (array $m) => (string) ($vars[$m[1]] ?? $m[0]), (string) file_get_contents($path));
    }
}
