<?php

namespace Firevel\ApiResourceSchemaGenerator\Tests;

use Firevel\ApiResourceSchemaGenerator\ConsolidateSchemasIntoResourceGenerator;
use Firevel\Generator\FirevelGeneratorManager;
use Firevel\Generator\Resource;

class ApiResourcesFromSchemaTest extends TestCase
{
    /** @test */
    public function it_registers_the_one_shot_pipelines()
    {
        $manager = app(FirevelGeneratorManager::class);
        $pipelines = $manager->getPipelines();

        $this->assertArrayHasKey('api-resource-schema-process', $pipelines);
        $this->assertArrayHasKey('api-resource-from-schema', $pipelines);
        $this->assertArrayHasKey('api-resources-from-schema', $pipelines);
    }

    /** @test */
    public function the_pipeline_registry_is_valid()
    {
        $manager = app(FirevelGeneratorManager::class);

        // No missing classes, unknown scoped pipelines, or scope cycles.
        $this->assertSame([], $manager->validate());
    }

    /** @test */
    public function the_from_schema_pipelines_reference_real_targets()
    {
        $pipelines = app(FirevelGeneratorManager::class)->getPipelines();

        // Both end by generating per-resource code via the api-resource pipeline.
        $this->assertSame('api-resource', $this->scopedTarget($pipelines['api-resources-from-schema'], 'resources.*'));
        $this->assertSame('api-resource', $this->scopedTarget($pipelines['api-resource-from-schema'], 'resources.*'));

        // Plural transforms each schema through the slim (no-save) building block.
        $this->assertSame('api-resource-schema-process', $this->scopedTarget($pipelines['api-resources-from-schema'], 'schemas.*'));
    }

    /**
     * Return the `pipeline` a scoped step targets for the given scope, or null.
     */
    protected function scopedTarget(array $steps, string $scope): ?string
    {
        foreach ($steps as $step) {
            if (is_array($step) && ($step['scope'] ?? null) === $scope) {
                return $step['pipeline'] ?? null;
            }
        }

        return null;
    }

    /** @test */
    public function the_bridge_writes_a_temp_descriptor_and_injects_resources()
    {
        $input = [
            'schemas' => [
                ['name' => 'post', 'fields' => [], 'indexes' => [], 'relationships' => []],
            ],
        ];
        $processed = [
            [
                'name' => 'post',
                'model' => ['fillable' => ['title']],
                'migrations' => ['create' => [['name' => 'title', 'type' => 'string']]],
            ],
        ];

        // resource() (the scope-resolution target) and input() (the pre-scope
        // data) are the same instance in the real pipeline. Here they carry
        // identical data: the generator builds output from input() and injects
        // the processed resources onto resource().
        $generator = $this->makeGenerator(
            ConsolidateSchemasIntoResourceGenerator::class,
            $input,
            ['input' => new Resource($input), 'schemas' => $processed]
        );
        $generator->handle();

        // The processed resources were injected onto the resource for the
        // following resources.* scope.
        $injected = $generator->resource();
        $this->assertTrue($injected->has('resources'));
        $this->assertCount(1, $injected->get('resources'));
        $this->assertSame('post', $injected->get('resources')[0]['name']);

        // An inspectable descriptor was written under the system temp dir.
        $written = $this->latestTempDescriptor();
        $this->assertNotNull($written, 'expected a temp descriptor file to be written');
        $this->assertStringStartsWith(rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR), $written);

        $output = json_decode(file_get_contents($written), true);
        $this->assertArrayHasKey('resources', $output);
        $this->assertSame('post', $output['resources'][0]['name']);

        @unlink($written);
    }

    /**
     * Find the most recently written one-shot temp descriptor, if any.
     */
    protected function latestTempDescriptor(): ?string
    {
        $matches = glob(
            rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . 'firevel-api-resource-from-schema-*.json'
        );

        if (empty($matches)) {
            return null;
        }

        usort($matches, fn ($a, $b) => filemtime($b) <=> filemtime($a));

        return $matches[0];
    }
}
