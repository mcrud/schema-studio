<?php

namespace Mcrud\Crud\Support;

/**
 * Single source of truth for the column types the studio supports.
 * Validation, migrations, casts, form inputs and factories all read from here.
 */
final class ColumnTypes
{
    /** @var array<string, array{rule: string, cast: ?string, input: string, faker: string}> */
    private const TYPES = [
        'string' => ['rule' => 'string|max:255', 'cast' => null, 'input' => 'text', 'faker' => 'fake()->words(3, true)'],
        'text' => ['rule' => 'string', 'cast' => null, 'input' => 'textarea', 'faker' => 'fake()->paragraph()'],
        'integer' => ['rule' => 'integer', 'cast' => null, 'input' => 'number', 'faker' => 'fake()->numberBetween(1, 100)'],
        'bigInteger' => ['rule' => 'integer', 'cast' => null, 'input' => 'number', 'faker' => 'fake()->numberBetween(1, 100000)'],
        'boolean' => ['rule' => 'boolean', 'cast' => 'boolean', 'input' => 'checkbox', 'faker' => 'fake()->boolean()'],
        'date' => ['rule' => 'date', 'cast' => 'date', 'input' => 'date', 'faker' => "fake()->date()"],
        'dateTime' => ['rule' => 'date', 'cast' => 'datetime', 'input' => 'datetime', 'faker' => "fake()->dateTime()->format('Y-m-d H:i:s')"],
        'float' => ['rule' => 'numeric', 'cast' => 'float', 'input' => 'number', 'faker' => 'fake()->randomFloat(3, 1, 1000)'],
        'time' => ['rule' => 'date_format:H:i', 'cast' => null, 'input' => 'time', 'faker' => "fake()->time('H:i')"],
        'uuid' => ['rule' => 'uuid', 'cast' => null, 'input' => 'text', 'faker' => 'fake()->uuid()'],
        'json' => ['rule' => 'json', 'cast' => null, 'input' => 'textarea', 'faker' => "json_encode(['key' => fake()->word()])"],
        'enum' => ['rule' => 'string|max:255', 'cast' => null, 'input' => 'select', 'faker' => 'fake()->word()'],
        'decimal' => ['rule' => 'numeric', 'cast' => 'decimal:2', 'input' => 'number', 'faker' => 'fake()->randomFloat(2, 1, 1000)'],
    ];

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::TYPES);
    }

    /** @return list<string> */
    public static function enumValues(?string $raw): array
    {
        return array_values(array_unique(array_filter(array_map('trim', explode(',', (string) $raw)), fn ($v) => $v !== '')));
    }

    public static function rule(string $type): string
    {
        return self::TYPES[$type]['rule'] ?? 'string|max:255';
    }

    public static function cast(string $type): ?string
    {
        return self::TYPES[$type]['cast'] ?? null;
    }

    public static function input(string $type): string
    {
        return self::TYPES[$type]['input'] ?? 'text';
    }

    /** Faker expression for a column, using the column name first and its type as a fallback. */
    public static function faker(string $name, string $type, bool $unique = false): string
    {
        $expression = match (true) {
            str_contains($name, 'email') => 'fake()->safeEmail()',
            str_contains($name, 'phone') => 'fake()->phoneNumber()',
            str_contains($name, 'url') || str_contains($name, 'website') => 'fake()->url()',
            str_contains($name, 'address') => 'fake()->address()',
            str_contains($name, 'city') => 'fake()->city()',
            str_contains($name, 'country') => 'fake()->country()',
            $name === 'name' || str_ends_with($name, '_name') => 'fake()->name()',
            $name === 'title' => 'fake()->sentence(3)',
            in_array($name, ['description', 'notes', 'body', 'content'], true) => 'fake()->paragraph()',
            $name === 'status' => "fake()->randomElement(['active', 'inactive'])",
            in_array($name, ['sku', 'code', 'number'], true) => "fake()->bothify('???-#####')",
            in_array($name, ['price', 'total', 'amount', 'cost'], true) => 'fake()->randomFloat(2, 5, 500)',
            default => self::TYPES[$type]['faker'] ?? 'fake()->word()',
        };

        return $unique ? preg_replace('/^fake\(\)->/', 'fake()->unique()->', $expression) : $expression;
    }
}
