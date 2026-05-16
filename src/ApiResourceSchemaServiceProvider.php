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

        // Meta-pipeline: iterates `schemas.*` through `api-resource-schema`,
        // then consolidates. Designed to chain into `generic-app` /
        // `appengine-app` via `--pipe` / `@output`.
        $manager->extend('api-resource-schemas', [
            'description' => 'Process multiple prompt-style resource schemas under `schemas.*` and consolidate them into a single descriptor (chainable into `generic-app` / `appengine-app` via `@output`).',
            'input_schema' => [
                'type' => 'object',
                'required' => ['schemas'],
                'properties' => [
                    'schemas' => [
                        'type' => 'array',
                        'minItems' => 1,
                    ],
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
                [
                    'scope' => 'schemas',
                    'pipeline' => 'schemas-consolidate',
                ],
            ],
        ]);
    }
}
