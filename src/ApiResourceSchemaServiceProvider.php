<?php

namespace Firevel\ApiResourceSchemaGenerator;

use Firevel\Generator\FirevelGeneratorManager;
use Illuminate\Support\ServiceProvider;

class ApiResourceSchemaServiceProvider extends ServiceProvider
{
    public function boot(FirevelGeneratorManager $manager)
    {
        // Single schema generation pipeline
        $manager
            ->extend(
                'api-resource-schema',
                [
                    'schema-handler' => \Firevel\ApiResourceSchemaGenerator\SchemaHandler::class,
                    'save-file' => \Firevel\ApiResourceSchemaGenerator\SaveFile::class,
                ]
            );

        // Consolidator pipeline (used in meta-pipeline)
        $manager
            ->extend(
                'schemas-consolidate',
                [
                    'consolidate' => \Firevel\ApiResourceSchemaGenerator\SchemaConsolidatorGenerator::class,
                ]
            );

        // Meta-pipeline for multiple resources
        $manager
            ->extend(
                'api-resource-schemas',
                [
                    [
                        'scope' => 'schemas.*',
                        'pipeline' => 'api-resource-schema',
                    ],
                    [
                        'scope' => 'schemas',
                        'pipeline' => 'schemas-consolidate',
                    ],
                ]
            );
    }
}
