<?php

namespace Mcrud\Crud\Tests;

use PHPUnit\Framework\TestCase;

/** Guards the Arabic interface: every visible English string of the studio must have a translation. */
class StudioTranslationTest extends TestCase
{
    /** Strings that are identical in every language (shortcuts, examples, paths and code fragments). */
    private const KEEP = [
        'Acme Store', 'Ctrl', 'Ctrl/⌘ + Shift + Z · Ctrl/⌘ + Y', 'Ctrl/⌘ + Z', 'Enter', 'Enter (in a column name)', 'admin, editor',
        'storage/app/crud-schema-studio/project.json', 'constrained(', 'default(', 'foreignId(', 'primary([',
    ];

    private function dictionary(): array
    {
        $dictionary = json_decode((string) file_get_contents(__DIR__.'/../resources/studio/ar.json'), true);
        self::assertIsArray($dictionary, 'resources/studio/ar.json must be valid JSON.');

        return $dictionary;
    }

    /** @return list<string> */
    private function bladeStrings(): array
    {
        $blade = (string) file_get_contents(__DIR__.'/../resources/views/generator.blade.php');
        $blade = preg_replace('#<(script|style)\b.*?</\1>#s', '', $blade);
        $blade = preg_replace('/\{\{.*?\}\}|\{!!.*?!!\}/s', "\0", $blade);
        $blade = preg_replace('/@(?:if|elseif|foreach|forelse|selected|checked|disabled|json|php|error)\s*\((?:[^()]|\((?:[^()]|\([^()]*\))*\))*\)/', "\0", $blade);
        $blade = preg_replace('/@(?:else|endif|endforeach|csrf|endforelse|enderror)\b/', "\0", $blade);

        $found = [];
        preg_match_all('/>([^<>]+)</', $blade, $text);
        foreach ($text[1] as $chunk) foreach (explode("\0", $chunk) as $part) $found[] = trim(html_entity_decode($part));
        preg_match_all('/(?:placeholder|title|aria-label)="([^"{}]+)"/', $blade, $attributes);
        foreach ($attributes[1] as $value) $found[] = trim(html_entity_decode($value));

        return $found;
    }

    /** @return list<string> */
    private function scriptStrings(): array
    {
        $js = (string) file_get_contents(__DIR__.'/../resources/js/studio.js');
        preg_match_all('/>([^<>{}$`\'"\\\\]{2,90})</', $js, $text);
        preg_match_all('/(?:placeholder|title|aria-label)=\\\\?["\']([^"\'\\\\<>{}$]{2,80})/', $js, $attributes);

        return array_map('trim', array_merge($text[1], $attributes[1]));
    }

    public function test_every_visible_blade_string_is_translated(): void
    {
        $dictionary = $this->dictionary();
        $missing = [];
        foreach ($this->bladeStrings() as $string) {
            if (!preg_match('/[A-Za-z]{2}/', $string) || str_starts_with($string, '@') || in_array($string, self::KEEP, true)) continue;
            if (!isset($dictionary[$string])) $missing[$string] = true;
        }

        self::assertSame([], array_keys($missing), 'Add these to resources/studio/ar.json: '.implode(' | ', array_keys($missing)));
    }

    public function test_every_string_built_by_the_designer_script_is_translated(): void
    {
        $dictionary = $this->dictionary();
        $missing = [];
        foreach ($this->scriptStrings() as $string) {
            if (!preg_match('/[A-Za-z]{3}/', $string) || in_array($string, self::KEEP, true)) continue;
            // Code-looking fragments picked up by the pattern rather than interface text.
            if (preg_match('/[\[\]();=]|^(?:column_name|draft, published|status, code|min:3\|regex.*|Product)$/', $string)) continue;
            if (!isset($dictionary[$string])) $missing[$string] = true;
        }

        self::assertSame([], array_keys($missing), 'Add these to resources/studio/ar.json: '.implode(' | ', array_keys($missing)));
    }

    public function test_every_translation_is_filled_in_and_keeps_interpolation_free(): void
    {
        foreach ($this->dictionary() as $english => $arabic) {
            self::assertIsString($arabic);
            self::assertNotSame('', trim($arabic), "Empty translation for: {$english}");
            self::assertDoesNotMatchRegularExpression('/\$\{|\{\{|<\/?[a-z]/i', $arabic, "Translations must be plain text: {$english}");
        }
    }

    public function test_the_translation_engine_translates_exact_matches_only(): void
    {
        if (trim((string) shell_exec('command -v node')) === '') $this->markTestSkipped('Node.js is needed to run the browser engine.');

        $script = <<<'JS'
        const fs = require('fs');
        const dict = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
        const attributes = [];
        global.localStorage = { getItem: () => null, setItem: () => {} };
        global.NodeFilter = { SHOW_TEXT: 4 };
        global.document = {
            body: { nodeType: 1, querySelectorAll: () => [], closest: () => null, hasAttribute: () => false },
            documentElement: { setAttribute: (n, v) => attributes.push([n, v]) },
            createTreeWalker: () => ({ nextNode: () => null }),
            querySelector: () => null, querySelectorAll: () => [], getElementById: () => null,
        };
        global.window = { STUDIO: { i18n: dict, locale: 'en' } };
        require(process.argv[3]);
        const i = window.StudioI18n, out = {};
        out.english = i.t('Add table');
        out.exact = i.translate('  Add table  ');
        out.unknown = i.translate('My own table name');
        out.partial = i.translate('Add table now');
        out.dollar = i.translate('$&');
        i.set('ar');
        out.arabic = i.t('Add table');
        out.runtime = i.t('Select at least one table');
        out.lang = i.get();
        i.set('en');
        out.back = i.t('Add table');
        out.attributes = attributes;
        console.log(JSON.stringify(out));
        JS;
        $file = sys_get_temp_dir().'/studio-i18n-check-'.bin2hex(random_bytes(4)).'.js';
        file_put_contents($file, $script);
        $output = shell_exec('node '.escapeshellarg($file).' '.escapeshellarg(realpath(__DIR__.'/../resources/studio/ar.json')).' '.escapeshellarg(realpath(__DIR__.'/../resources/js/studio-i18n.js')).' 2>&1');
        unlink($file);

        $result = json_decode((string) $output, true);
        self::assertIsArray($result, (string) $output);
        $dictionary = $this->dictionary();
        self::assertSame('Add table', $result['english']);
        self::assertSame('  '.$dictionary['Add table'].'  ', $result['exact']);
        self::assertSame('My own table name', $result['unknown']);
        self::assertSame('Add table now', $result['partial']);
        self::assertSame('$&', $result['dollar']);
        self::assertSame($dictionary['Add table'], $result['arabic']);
        self::assertSame($dictionary['Select at least one table'], $result['runtime']);
        self::assertSame('ar', $result['lang']);
        self::assertSame('Add table', $result['back']);
        self::assertContains(['lang', 'ar'], $result['attributes']);
    }

    public function test_the_browser_scripts_are_syntactically_valid(): void
    {
        if (trim((string) shell_exec('command -v node')) === '') $this->markTestSkipped('Node.js is needed to check the scripts.');

        foreach (['studio.js', 'studio-i18n.js'] as $file) {
            exec('node --check '.escapeshellarg(__DIR__.'/../resources/js/'.$file).' 2>&1', $output, $code);
            self::assertSame(0, $code, $file.': '.implode("\n", $output));
        }
    }
}
