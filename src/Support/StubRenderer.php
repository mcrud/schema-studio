<?php

namespace Mcrud\Crud\Support;

use RuntimeException;

/** Renders resources/stubs/*.stub files, replacing [[placeholder]] tokens. */
final class StubRenderer
{
    /** @param ?string $override A directory of published stubs that take precedence over the packaged ones. */
    public function __construct(private readonly string $directory = __DIR__.'/../../resources/stubs', private readonly ?string $override = null) {}

    /** @param array<string, string|int|null> $vars */
    public function render(string $stub, array $vars = []): string
    {
        $path = "{$this->directory}/{$stub}.stub";
        if ($this->override !== null && is_file("{$this->override}/{$stub}.stub")) $path = "{$this->override}/{$stub}.stub";
        if (!is_file($path)) throw new RuntimeException("Missing stub: {$stub}");

        return preg_replace_callback('/\[\[\s*(\w+)\s*\]\]/', fn (array $m) => (string) ($vars[$m[1]] ?? $m[0]), (string) file_get_contents($path));
    }
}
