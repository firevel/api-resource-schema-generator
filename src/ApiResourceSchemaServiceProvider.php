<?php

namespace Firevel\ApiResourceSchemaGenerator;

use Firevel\Generator\FirevelGeneratorManager;
use Illuminate\Support\ServiceProvider;

class ApiResourceSchemaServiceProvider extends ServiceProvider
{
    public function boot(FirevelGeneratorManager $manager)
    {
        // Single-resource: transforms one prompt-style schema into the
        // generator-ready output structure and saves it.
        $manager->extend('api-resource-schema', [
            'description' => 'Process a single prompt-style resource schema (name + fields + relationships) into a generator-ready resource definition and save it to disk.',
            'input_schema' => [
                'type' => 'object',
                'required' => ['name', 'fields'],
                'properties' => [
                    'name' => [
                        'type' => 'string',
                        'pattern' => '^[a-zA-Z]+$',
                    ],
                    'fields' => [
                        'type' => 'array',
                        'minItems' => 1,
                    ],
                    'relationships' => ['type' => 'array'],
                    'indexes' => ['type' => 'array'],
                    'schemas' => ['not' => new \stdClass()],
                ],
            ],
            'input_error_messages' => [
                '/name' => "Pipeline 'api-resource-schema' requires a singular resource name like \"Article\" (letters only).",
                '/fields' => "Pipeline 'api-resource-schema' requires a non-empty `fields` array (each field has `name` and `type`).",
                '/schemas' => "Pipeline 'api-resource-schema' processes one resource at a time and does not accept a `schemas` array. Use 'api-resource-schemas' for multi-resource input.",
            ],
            'steps' => [
                'schema-handler' => \Firevel\ApiResourceSchemaGenerator\SchemaHandler::class,
                'save-file' => \Firevel\ApiResourceSchemaGenerator\SaveFile::class,
            ],
        ]);

        // Consolidator: reads processed schemas + composer requires from the
        // shared pipeline context and writes a consolidated JSON descriptor.
        // No input contract — operates entirely on context state.
        $manager->extend('schemas-consolidate', [
            'description' => 'Consolidate processed schemas collected during a meta-pipeline run into a single JSON descriptor.',
            'steps' => [
                'consolidate' => \Firevel\ApiResourceSchemaGenerator\SchemaConsolidatorGenerator::class,
            ],
        ]);

        // Standalone seeders transformer — translates LLM-format seeders
        // ($ref / $now / $hash / $uuid) into the generator-level seeder
        // format. Chainable via @output. Also runs as a step inside the
        // multi-resource meta-pipeline (see below) when the input also
        // carries a `seeders` block.
        $manager->extend('seeders-transform', [
            'description' => 'Translate LLM-format seeder JSON into the generator-level seeder format consumed by firevel/generator (chainable via @output).',
            'input_schema' => [
                'type' => 'object',
                'required' => ['seeders'],
                'properties' => [
                    'seeders' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'required' => ['name', 'resources'],
                            'properties' => [
                                'name' => ['type' => 'string', 'minLength' => 1],
                                'resources' => ['type' => 'array'],
                            ],
                        ],
                    ],
                    'schemas' => ['type' => 'array'],
                ],
            ],
            'input_error_messages' => [
                '/seeders' => "Pipeline 'seeders-transform' requires a top-level `seeders` array of `{name, resources}` set objects (e.g. [{\"name\":\"system\",\"resources\":[...]},{\"name\":\"demo\",\"resources\":[...]}]).",
            ],
            'steps' => [
                'transform' => \Firevel\ApiResourceSchemaGenerator\SeedersTransformerGenerator::class,
            ],
        ]);

        // Standalone, one-command seeders build: translate the LLM-format
        // `seeders` (resolving $refs and backfilling schema defaults) then hand
        // the generator-level map to the `seeders` pipeline to emit
        // <Set>DataSeeder.php + DatabaseSeeder. The transform step rewrites the
        // resource's `seeders` attribute to the transformed map, so the scoped
        // `seeders` step below resolves that map (not the original LLM array).
        // Pass the resource `schemas` alongside `seeders` so $refs resolve and
        // defaults backfill — decoupled from app/schema (re)generation.
        $manager->extend('api-seeders', [
            'description' => 'Build seeder classes from prompt-style seeder JSON: translate the LLM `seeders` ($ref/$now/$hash/$uuid/$attach + default backfill) then emit one <Set>DataSeeder.php per set plus DatabaseSeeder. Pass `schemas` alongside so $refs resolve and defaults backfill.',
            'input_schema' => [
                'type' => 'object',
                'required' => ['seeders'],
                'properties' => [
                    'seeders' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'required' => ['name', 'resources'],
                            'properties' => [
                                'name' => ['type' => 'string', 'minLength' => 1],
                                'resources' => ['type' => 'array'],
                            ],
                        ],
                    ],
                    'schemas' => ['type' => 'array'],
                ],
            ],
            'input_error_messages' => [
                '/seeders' => "Pipeline 'api-seeders' requires a top-level `seeders` array of `{name, resources}` set objects (e.g. [{\"name\":\"system\",\"resources\":[...]},{\"name\":\"demo\",\"resources\":[...]}]). Pass the resource `schemas` array alongside so \$refs resolve and defaults backfill.",
            ],
            'steps' => [
                'transform' => \Firevel\ApiResourceSchemaGenerator\SeedersTransformerGenerator::class,
                [
                    'scope' => 'seeders',
                    'pipeline' => 'seeders',
                ],
            ],
        ]);

        // Meta-pipeline: iterates `schemas.*` through `api-resource-schema`,
        // optionally transforms `seeders`, then consolidates. Designed to
        // chain into `generic-app` / `appengine-app` via `--pipe` /
        // `@output`. The seeders step is a bare class step (no scoping)
        // so it sees the full input and silently no-ops when no `seeders`
        // block is present.
        $manager->extend('api-resource-schemas', [
            'description' => 'Process multiple prompt-style resource schemas under `schemas.*` and consolidate them into a single descriptor (chainable into `generic-app` / `appengine-app` via `@output`). When the input carries a top-level `seeders` block, it is translated alongside.',
            'input_schema' => [
                'type' => 'object',
                'required' => ['schemas'],
                'properties' => [
                    'schemas' => [
                        'type' => 'array',
                        'minItems' => 1,
                    ],
                    'seeders' => ['type' => 'array'],
                ],
            ],
            'input_error_messages' => [
                '/schemas' => "Pipeline 'api-resource-schemas' iterates `schemas.*`. Provide a non-empty top-level `schemas` array, "
                    . "e.g. {\"schemas\":[{\"name\":\"Article\",\"fields\":[...]},{\"name\":\"Comment\",\"fields\":[...]}]}.",
            ],
            'steps' => [
                [
                    'scope' => 'schemas.*',
                    'pipeline' => 'api-resource-schema',
                ],
                'seeders' => \Firevel\ApiResourceSchemaGenerator\SeedersTransformerGenerator::class,
                [
                    'scope' => 'schemas',
                    'pipeline' => 'schemas-consolidate',
                ],
            ],
        ]);

        // Building block: transform one prompt-style schema into the
        // generator-ready structure WITHOUT writing a file (unlike
        // `api-resource-schema`, which also runs `save-file`). Used inside the
        // `schemas.*` scope of `api-resources-from-schema` so the transform
        // phase leaves no per-resource files behind — only `context['schemas']`.
        $manager->extend('api-resource-schema-process', [
            'description' => 'Transform one prompt-style resource schema (name + fields + relationships) into the generator-ready structure and collect it in the pipeline context, without writing a file. Building block for api-resources-from-schema.',
            'steps' => [
                'schema-handler' => \Firevel\ApiResourceSchemaGenerator\SchemaHandler::class,
            ],
        ]);

        // One-shot single-resource: transform a prompt-style schema and
        // generate its code directly (equivalent to `api-resource-schema`
        // piped into `api-resource`). The intermediate generator-format
        // descriptor is written to the system temp dir for inspection; the
        // processed resource is handed to the `resources.*` scope in-memory by
        // the consolidate bridge.
        $manager->extend('api-resource-from-schema', [
            'description' => 'Transform one prompt-style resource schema (name + fields + relationships) and generate its code directly: migration, model, transformer, controller, requests, factory, seeder, policy and route. The intermediate generator-format descriptor is written to the system temp dir for inspection.',
            'input_schema' => [
                'type' => 'object',
                'required' => ['name', 'fields'],
                'properties' => [
                    'name' => [
                        'type' => 'string',
                        'pattern' => '^[a-zA-Z]+$',
                    ],
                    'fields' => [
                        'type' => 'array',
                        'minItems' => 1,
                    ],
                    'relationships' => ['type' => 'array'],
                    'indexes' => ['type' => 'array'],
                    'schemas' => ['not' => new \stdClass()],
                ],
            ],
            'input_error_messages' => [
                '/name' => "Pipeline 'api-resource-from-schema' requires a singular resource name like \"Article\" (letters only).",
                '/fields' => "Pipeline 'api-resource-from-schema' requires a non-empty `fields` array (each field has `name` and `type`).",
                '/schemas' => "Pipeline 'api-resource-from-schema' processes one resource at a time and does not accept a `schemas` array. Use 'api-resources-from-schema' for multi-resource input.",
            ],
            'steps' => [
                'schema-handler' => \Firevel\ApiResourceSchemaGenerator\SchemaHandler::class,
                'seeders' => \Firevel\ApiResourceSchemaGenerator\SeedersTransformerGenerator::class,
                'consolidate' => \Firevel\ApiResourceSchemaGenerator\ConsolidateSchemasIntoResourceGenerator::class,
                [
                    'scope' => 'resources.*',
                    'pipeline' => 'api-resource',
                ],
            ],
        ]);

        // One-shot multi-resource: transform prompt-style schemas under
        // `schemas.*` and generate their code directly (equivalent to
        // `api-resource-schemas` piped into `api-resources`). When the input
        // carries a top-level `seeders` block it is translated alongside.
        $manager->extend('api-resources-from-schema', [
            'description' => 'Transform prompt-style resource schemas (schemas.*) and generate their code directly (migration, model, transformer, controller, requests, factory, seeder, policy, route) — api-resource-schemas piped into api-resources in one command. The intermediate generator-format descriptor is written to the system temp dir for inspection.',
            'input_schema' => [
                'type' => 'object',
                'required' => ['schemas'],
                'properties' => [
                    'schemas' => [
                        'type' => 'array',
                        'minItems' => 1,
                    ],
                    'seeders' => ['type' => 'array'],
                ],
            ],
            'input_error_messages' => [
                '/schemas' => "Pipeline 'api-resources-from-schema' iterates `schemas.*`. Provide a non-empty top-level `schemas` array, "
                    . "e.g. {\"schemas\":[{\"name\":\"Article\",\"fields\":[...]},{\"name\":\"Comment\",\"fields\":[...]}]}.",
            ],
            'steps' => [
                [
                    'scope' => 'schemas.*',
                    'pipeline' => 'api-resource-schema-process',
                ],
                'seeders' => \Firevel\ApiResourceSchemaGenerator\SeedersTransformerGenerator::class,
                'consolidate' => \Firevel\ApiResourceSchemaGenerator\ConsolidateSchemasIntoResourceGenerator::class,
                [
                    'scope' => 'resources.*',
                    'pipeline' => 'api-resource',
                ],
            ],
        ]);
    }
}
