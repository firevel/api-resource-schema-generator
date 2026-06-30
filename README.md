# API Resource Schema Generator

Generates JSON schema files for API resources using Firevel Generator.

## Usage

**Single resource:**
```bash
php artisan firevel:generate api-resource-schema --json=resource.json
```
Output: `schemas/api-resources/{name}/schema.json`

**Multiple resources:**
```bash
php artisan firevel:generate api-resource-schemas --json=resources.json
```
Output: `schemas/app.json`

## Schema → code in one step

The pipelines above only produce schema JSON, which you then feed to the
generator. The `*-from-schema` pipelines collapse both stages into a single
command: they transform the prompt-style schema **and** generate the resource
code (model, migration, transformer, controller, requests, factory, seeder,
policy, route).

**Single resource** — bare `{ "name": ..., "fields": [...] }` input:
```bash
php artisan firevel:generate api-resource-from-schema --json=resource.json
```

**Multiple resources** — `{ "schemas": [ ... ] }` input (plus optional `seeders`):
```bash
php artisan firevel:generate api-resources-from-schema --json=resources.json
```

Both accept the standard flags (`--only`, `--dry-run`, `--skip-existing`).
The intermediate generator-format descriptor is written to the system temp
directory (the path is logged) for inspection — nothing is added under
`schemas/`. Code generation runs the `api-resource` pipeline (resource files
only); it does not run the app-level steps (routes consolidation, morph map,
composer requires, `.env`).
