<?php

namespace Mcrud\Crud\Tests;

use PHPUnit\Framework\TestCase;

/** Keeps the documentation, changelog and CI configuration in step with the code. */
class ProjectFilesTest extends TestCase
{
    private function file(string $path): string
    {
        $contents = file_get_contents(__DIR__.'/../'.$path);
        self::assertIsString($contents, "{$path} is missing");

        return $contents;
    }

    public function test_the_readme_documents_every_config_key(): void
    {
        $config = require __DIR__.'/../config/crud.php';
        $readme = $this->file('README.md');

        foreach (array_keys($config) as $key) self::assertStringContainsString("| `{$key}` |", $readme, "README.md does not document crud.{$key}");
        foreach (array_keys($config) as $key) self::assertStringContainsString("'{$key}'", $this->file('config/crud.php'));
    }

    public function test_the_readme_and_docs_describe_the_current_dependency_model(): void
    {
        foreach (['README.md', 'docs/index.html'] as $path) {
            $text = $this->file($path);
            self::assertStringNotContainsString('includes `spatie/laravel-permission`', $text, $path);
            self::assertStringContainsString('crud:sync', $text, $path);
            self::assertStringContainsString('crud.ability', $text, $path);
        }
        $composer = json_decode($this->file('composer.json'), true);
        self::assertArrayNotHasKey('spatie/laravel-permission', $composer['require']);
        self::assertArrayHasKey('spatie/laravel-permission', $composer['suggest']);
    }

    public function test_the_changelog_has_a_release_section_with_upgrade_notes(): void
    {
        $changelog = $this->file('CHANGELOG.md');

        self::assertStringContainsString('## [2.0.0]', $changelog);
        self::assertStringContainsString('### Upgrading from 1.x', $changelog);
        self::assertStringContainsString('spatie/laravel-permission', $changelog);
        self::assertStringContainsString('crud.require_ability', $changelog);
    }

    public function test_the_ci_workflow_covers_every_supported_laravel_version(): void
    {
        $workflow = $this->file('.github/workflows/tests.yml');
        $composer = json_decode($this->file('composer.json'), true);

        foreach (["'10'", "'11'", "'12'", "'8.2'", "'8.3'", 'vendor/bin/phpunit', 'gd'] as $needle) self::assertStringContainsString($needle, $workflow);
        // Each Laravel line needs the Testbench major that supports it.
        foreach (['^8.0' => '^10.0', '^9.0' => '^11.0', '^10.0' => '^12.0'] as $testbench => $framework) {
            self::assertStringContainsString("framework: '{$framework}'", $workflow);
            self::assertStringContainsString("testbench: '{$testbench}'", $workflow);
        }
        self::assertStringContainsString('^8.0', $composer['require-dev']['orchestra/testbench']);
    }
}
