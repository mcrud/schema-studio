<?php

namespace Mcrud\Crud\Schema;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Mcrud\Crud\Generators\CrudGenerator;

/**
 * Checks a schema the way the studio does before it generates anything: the request rules first,
 * then the generator's own checks (duplicate tables and columns, unknown relation targets, enums without values, ...).
 */
final class SchemaValidator
{
    public function __construct(private readonly CrudGenerator $generator) {}

    /** @return list<string> Every problem found, or an empty list when the schema is valid. */
    public function errors(array $data): array
    {
        $validator = Validator::make($data, SchemaRules::rules());
        if ($validator->fails()) {
            return $this->flatten($validator->errors()->toArray());
        }

        try {
            $this->generator->plan($data);
        } catch (ValidationException $e) {
            return $this->flatten($e->errors());
        }

        return [];
    }

    /** @param array<string, list<string>> $errors @return list<string> */
    private function flatten(array $errors): array
    {
        return array_values(array_unique(array_merge(...array_values($errors))));
    }
}
