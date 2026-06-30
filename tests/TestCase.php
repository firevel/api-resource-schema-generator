<?php

namespace Firevel\ApiResourceSchemaGenerator\Tests;

use Firevel\ApiResourceSchemaGenerator\ApiResourceSchemaServiceProvider;
use Firevel\Generator\Testing\MakesGenerators;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use MakesGenerators;

    protected function getPackageProviders($app)
    {
        return [
            // Binds the FirevelGeneratorManager singleton and merges the
            // generator's config pipelines (api-resource, api-resources, …)
            // that our from-schema pipelines reference. In a real app this is
            // auto-discovered; testbench needs it listed explicitly.
            \Firevel\Generator\ServiceProvider::class,
            ApiResourceSchemaServiceProvider::class,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
    }
}
