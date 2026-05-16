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
            ApiResourceSchemaServiceProvider::class,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
    }
}
