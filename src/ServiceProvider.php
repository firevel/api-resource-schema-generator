<?php

namespace Firevel\ApiResourceSchemaGenerator;

use Firevel\Generator\FirevelGeneratorManager;
use Illuminate\Support\ServiceProvider;

class TemplateGeneratorServiceProvider extends ServiceProvider
{
    public function boot(FirevelGeneratorManager $manager)
    {
        $manager
            ->extend(
                'api-resource-schema',
                [
                    'schema-handler' => \Firevel\ApiResourceSchemaGenerator\SchemaHandler::class,
                    'save-file' => \Firevel\ApiResourceSchemaGenerator\SaveFile::class,
                ]
            );
    }
}
