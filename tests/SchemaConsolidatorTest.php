<?php

namespace Firevel\ApiResourceSchemaGenerator\Tests;

use Firevel\ApiResourceSchemaGenerator\SchemaConsolidatorGenerator;
use Firevel\Generator\Resource;

class SchemaConsolidatorTest extends TestCase
{
    protected $tempPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempPath = sys_get_temp_dir() . '/schema-tests-' . uniqid();
        if (!is_dir($this->tempPath)) {
            mkdir($this->tempPath, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempPath)) {
            $this->deleteDirectory($this->tempPath);
        }

        parent::tearDown();
    }

    protected function deleteDirectory($dir)
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * Run the consolidator with the output path and merge policy injected
     * through context — no interactive prompts, plain NullLogger.
     *
     * @param array  $input         full pre-scoping resource (also stored under context.input)
     * @param array  $schemas       processed schemas to consolidate (under context.schemas)
     * @param string $outputPath    where the consolidated JSON gets written
     * @param bool   $mergeExisting when true, merge with the existing file instead of overwriting
     * @param array  $contextExtra  additional context keys to set (e.g. composer_requires)
     */
    protected function runConsolidator(
        array $input,
        array $schemas,
        string $outputPath,
        bool $mergeExisting = false,
        array $contextExtra = []
    ): SchemaConsolidatorGenerator {
        $contextOverrides = array_merge([
            'input' => new Resource($input),
            'schemas' => $schemas,
            'output_path' => $outputPath,
            'merge_existing' => $mergeExisting,
        ], $contextExtra);

        return $this->runGenerator(SchemaConsolidatorGenerator::class, $input, $contextOverrides);
    }

    /** @test */
    public function it_preserves_input_data_and_adds_resources()
    {
        $inputData = json_decode(file_get_contents(__DIR__ . '/fixtures/test-resources.json'), true);
        $processedSchemas = [
            [
                'name' => 'post',
                'model' => ['fillable' => ['title']],
                'transformer' => ['transform' => ['id', 'title']],
                'migrations' => [
                    'create' => [
                        ['name' => 'id', 'type' => 'increments'],
                        ['name' => 'title', 'type' => 'string'],
                    ],
                ],
                'requests' => [
                    'store' => ['title' => ['required', 'string']],
                    'update' => ['title' => ['string']],
                ],
            ],
        ];
        $outputPath = $this->tempPath . '/output.json';

        $this->runConsolidator($inputData, $processedSchemas, $outputPath);

        $this->assertFileExists($outputPath);
        $output = json_decode(file_get_contents($outputPath), true);

        // Original input data preserved.
        $this->assertEquals('1.0.0', $output['version']);
        $this->assertEquals('Test', $output['metadata']['author']);
        $this->assertEquals('2025-01-01', $output['metadata']['created']);
        $this->assertArrayHasKey('schemas', $output);

        // Processed resources added.
        $this->assertArrayHasKey('resources', $output);
        $this->assertCount(1, $output['resources']);
        $this->assertEquals('post', $output['resources'][0]['name']);
        $this->assertEquals(['title'], $output['resources'][0]['model']['fillable']);
    }

    /** @test */
    public function it_handles_override_mode_correctly()
    {
        $outputPath = $this->tempPath . '/output.json';
        $existingData = [
            'version' => '0.9.0',
            'resources' => [
                ['name' => 'user', 'model' => ['fillable' => ['name', 'email']]],
                ['name' => 'post', 'model' => ['fillable' => ['old_field']]],
            ],
        ];
        file_put_contents($outputPath, json_encode($existingData, JSON_PRETTY_PRINT));

        $inputData = json_decode(file_get_contents(__DIR__ . '/fixtures/test-resources.json'), true);
        $processedSchemas = [
            ['name' => 'post', 'model' => ['fillable' => ['title']]],
        ];

        $this->runConsolidator($inputData, $processedSchemas, $outputPath, true);

        $output = json_decode(file_get_contents($outputPath), true);

        $this->assertCount(2, $output['resources']);
        $byName = array_column($output['resources'], null, 'name');
        $this->assertEquals(['name', 'email'], $byName['user']['model']['fillable']);
        $this->assertEquals(['title'], $byName['post']['model']['fillable']);
    }

    /** @test */
    public function it_merges_all_top_level_fields_from_existing_file_on_override()
    {
        $outputPath = $this->tempPath . '/output.json';
        $existingData = [
            'service' => ['name' => 'MyService', 'version' => '1.0.0'],
            'customField' => 'existingValue',
            'resources' => [
                ['name' => 'user', 'model' => ['fillable' => ['name', 'email']]],
            ],
        ];
        file_put_contents($outputPath, json_encode($existingData, JSON_PRETTY_PRINT));

        $inputData = [
            'schemas' => [
                ['name' => 'post', 'fields' => [], 'indexes' => [], 'relationships' => []],
            ],
        ];
        $processedSchemas = [
            ['name' => 'post', 'model' => ['fillable' => ['title']]],
        ];

        $this->runConsolidator($inputData, $processedSchemas, $outputPath, true);

        $output = json_decode(file_get_contents($outputPath), true);

        $this->assertEquals('MyService', $output['service']['name']);
        $this->assertEquals('1.0.0', $output['service']['version']);
        $this->assertEquals('existingValue', $output['customField']);

        $this->assertCount(2, $output['resources']);
        $byName = array_column($output['resources'], null, 'name');
        $this->assertEquals(['name', 'email'], $byName['user']['model']['fillable']);
        $this->assertEquals(['title'], $byName['post']['model']['fillable']);
    }

    /** @test */
    public function it_merges_extra_fields_from_new_input_on_override()
    {
        $outputPath = $this->tempPath . '/output.json';
        $existingData = [
            'service' => ['name' => 'MyService'],
            'resources' => [
                ['name' => 'user', 'model' => ['fillable' => ['name']]],
            ],
        ];
        file_put_contents($outputPath, json_encode($existingData, JSON_PRETTY_PRINT));

        $inputData = [
            'newField' => 'newValue',
            'anotherField' => ['nested' => 'data'],
            'schemas' => [
                ['name' => 'comment', 'fields' => [], 'indexes' => [], 'relationships' => []],
            ],
        ];
        $processedSchemas = [
            ['name' => 'comment', 'model' => ['fillable' => ['body']]],
        ];

        $this->runConsolidator($inputData, $processedSchemas, $outputPath, true);

        $output = json_decode(file_get_contents($outputPath), true);

        $this->assertEquals('MyService', $output['service']['name']);
        $this->assertEquals('newValue', $output['newField']);
        $this->assertEquals(['nested' => 'data'], $output['anotherField']);

        $byName = array_column($output['resources'], null, 'name');
        $this->assertArrayHasKey('user', $byName);
        $this->assertArrayHasKey('comment', $byName);
    }

    /** @test */
    public function it_includes_all_input_keys_in_output()
    {
        $inputData = [
            'version' => '2.0.0',
            'config' => ['namespace' => 'App\\Models'],
            'author' => 'John Doe',
            'customKey' => 'customValue',
            'schemas' => [
                ['name' => 'test', 'fields' => [], 'indexes' => [], 'relationships' => []],
            ],
        ];
        $processedSchemas = [['name' => 'test', 'model' => []]];
        $outputPath = $this->tempPath . '/output.json';

        $this->runConsolidator($inputData, $processedSchemas, $outputPath);

        $output = json_decode(file_get_contents($outputPath), true);

        $this->assertEquals('2.0.0', $output['version']);
        $this->assertEquals(['namespace' => 'App\\Models'], $output['config']);
        $this->assertEquals('John Doe', $output['author']);
        $this->assertEquals('customValue', $output['customKey']);
        $this->assertArrayHasKey('schemas', $output);
        $this->assertArrayHasKey('resources', $output);
        $this->assertCount(1, $output['resources']);
    }

    /** @test */
    public function it_merges_generator_composer_requires_into_top_level_require()
    {
        $outputPath = $this->tempPath . '/output.json';

        $this->runConsolidator(
            ['name' => 'app'],
            [['name' => 'post']],
            $outputPath,
            false,
            ['composer_requires' =>['laravel/scout' => '*', 'firevel/sortable' => '*']]
        );

        $output = json_decode(file_get_contents($outputPath), true);
        $this->assertSame('*', $output['require']['laravel/scout']);
        $this->assertSame('*', $output['require']['firevel/sortable']);
    }

    /** @test */
    public function existing_concrete_require_wins_over_generator_star()
    {
        $outputPath = $this->tempPath . '/output.json';

        $this->runConsolidator(
            ['name' => 'app', 'require' => ['laravel/scout' => '^10.0']],
            [['name' => 'post']],
            $outputPath,
            false,
            ['composer_requires' =>['laravel/scout' => '*']]
        );

        $output = json_decode(file_get_contents($outputPath), true);
        $this->assertSame('^10.0', $output['require']['laravel/scout']);
    }

    /** @test */
    public function generator_concrete_replaces_existing_star()
    {
        $outputPath = $this->tempPath . '/output.json';

        $this->runConsolidator(
            ['name' => 'app', 'require' => ['laravel/scout' => '*']],
            [['name' => 'post']],
            $outputPath,
            false,
            ['composer_requires' =>['laravel/scout' => '^10.0']]
        );

        $output = json_decode(file_get_contents($outputPath), true);
        $this->assertSame('^10.0', $output['require']['laravel/scout']);
    }

    /** @test */
    public function no_generator_requires_does_not_introduce_require_key()
    {
        $outputPath = $this->tempPath . '/output.json';

        $this->runConsolidator(
            ['name' => 'app'],
            [['name' => 'post']],
            $outputPath
        );

        $output = json_decode(file_get_contents($outputPath), true);
        $this->assertArrayNotHasKey('require', $output);
    }
}
