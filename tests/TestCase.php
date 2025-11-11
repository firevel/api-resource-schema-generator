<?php

namespace Firevel\ApiResourceSchemaGenerator\Tests;

use Firevel\ApiResourceSchemaGenerator\ApiResourceSchemaServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
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
