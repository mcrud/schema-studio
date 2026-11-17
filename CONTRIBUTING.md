# Contributing

Thanks for helping improve `mcrud/schema-studio`.

## Setup

```bash
composer install
composer test
```

The suite runs on PHP 8.2+ with Orchestra Testbench. CI runs it on PHP 8.2 and 8.3 against Laravel 10, 11 and 12, so avoid APIs that are missing from any of those versions.

## Making a change

1. Branch from `master`.
2. Keep the change focused: one fix or feature per pull request.
3. Add or update tests for any behaviour you change. Generator output is covered by feature tests in `tests/`.
4. If you add a studio interface string, add its Arabic translation too. A test fails when one is missing.
5. Generated code lives in `resources/stubs`. Change the stub rather than building strings in PHP, so users can still publish and customise it.
6. Add a line under an `Unreleased` heading in [CHANGELOG.md](CHANGELOG.md).
7. Run `composer test` before opening the pull request.

## Reporting bugs

Open an issue with the schema JSON (or the studio steps), the Laravel and PHP versions, and what you expected to happen.

## Security

Do not open a public issue for a vulnerability. Contact the maintainer privately.
