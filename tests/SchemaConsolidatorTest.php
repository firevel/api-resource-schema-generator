<?php

namespace Firevel\ApiResourceSchemaGenerator\Tests;

use Firevel\ApiResourceSchemaGenerator\SchemaConsolidatorGenerator;
use Firevel\Generator\PipelineContext;
use Firevel\Generator\Resource;
use Illuminate\Support\Facades\File;

class SchemaConsolidatorTest extends TestCase
{
    protected $tempPath;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a temporary directory for output files
        $this->tempPath = sys_get_temp_dir() . '/schema-tests-' . uniqid();
        if (!is_dir($this->tempPath)) {
            mkdir($this->tempPath, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        // Clean up temporary files
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

    /** @test */
    public function it_preserves_input_data_and_adds_resources()
    {
        // Load test fixture
        $inputData = json_decode(file_get_contents(__DIR__ . '/fixtures/test-resources.json'), true);

        // Create resource and context
        $resource = new Resource($inputData);
        $context = new PipelineContext();

        // Set the full input in context (simulates what the pipeline does)
        $context->set('input', new Resource($inputData));

        // Simulate processed schemas (what SchemaHandler would produce)
        $processedSchemas = [
            [
                'name' => 'post',
                'model' => [
                    'fillable' => ['title']
                ],
                'transformer' => [
                    'transform' => ['id', 'title']
                ],
                'migrations' => [
                    'create' => [
                        ['name' => 'id', 'type' => 'increments'],
                        ['name' => 'title', 'type' => 'string']
                    ]
                ],
                'requests' => [
                    'store' => ['title' => ['required', 'string']],
                    'update' => ['title' => ['string']]
                ]
            ]
        ];

        $context->set('schemas', $processedSchemas);

        // Create output path
        $outputPath = $this->tempPath . '/output.json';

        // Create a mock logger
        $logger = new class($outputPath) {
            private $outputPath;

            public function __construct($outputPath)
            {
                $this->outputPath = $outputPath;
            }

            public function info($message) {}

            public function ask($question, $default, $options = null)
            {
                // Return the output path when asked
                if (strpos($question, 'output file path') !== false) {
                    return $this->outputPath;
                }
                // Return 'overwrite' for file exists question
                return 'overwrite';
            }
        };

        // Create and run the consolidator
        $consolidator = new SchemaConsolidatorGenerator($resource, $context);
        $consolidator->setLogger($logger);
        $consolidator->handle();

        // Verify output file was created
        $this->assertFileExists($outputPath);

        // Read and verify output
        $output = json_decode(file_get_contents($outputPath), true);

        // Check that original input data is preserved
        $this->assertEquals('1.0.0', $output['version']);
        $this->assertEquals('Test', $output['metadata']['author']);
        $this->assertEquals('2025-01-01', $output['metadata']['created']);

        // Check that schemas key is preserved (if present in input)
        $this->assertArrayHasKey('schemas', $output);

        // Check that resources key was added with processed data
        $this->assertArrayHasKey('resources', $output);
        $this->assertCount(1, $output['resources']);

        // Verify the processed resource structure
        $this->assertEquals('post', $output['resources'][0]['name']);
        $this->assertArrayHasKey('model', $output['resources'][0]);
        $this->assertArrayHasKey('transformer', $output['resources'][0]);
        $this->assertArrayHasKey('migrations', $output['resources'][0]);
        $this->assertArrayHasKey('requests', $output['resources'][0]);

        // Verify specific content
        $this->assertEquals(['title'], $output['resources'][0]['model']['fillable']);
    }

    /** @test */
    public function it_handles_override_mode_correctly()
    {
        // Create initial file
        $outputPath = $this->tempPath . '/output.json';
        $existingData = [
            'version' => '0.9.0',
            'resources' => [
                [
                    'name' => 'user',
                    'model' => ['fillable' => ['name', 'email']]
                ],
                [
                    'name' => 'post',
                    'model' => ['fillable' => ['old_field']]
                ]
            ]
        ];
        file_put_contents($outputPath, json_encode($existingData, JSON_PRETTY_PRINT));

        // Load test fixture
        $inputData = json_decode(file_get_contents(__DIR__ . '/fixtures/test-resources.json'), true);

        // Create resource and context
        $resource = new Resource($inputData);
        $context = new PipelineContext();

        // Set the full input in context (simulates what the pipeline does)
        $context->set('input', new Resource($inputData));

        // Processed schemas with updated 'post' resource
        $processedSchemas = [
            [
                'name' => 'post',
                'model' => ['fillable' => ['title']]
            ]
        ];

        $context->set('schemas', $processedSchemas);

        // Create a mock logger that returns 'override'
        $logger = new class($outputPath) {
            private $outputPath;

            public function __construct($outputPath)
            {
                $this->outputPath = $outputPath;
            }

            public function info($message) {}

            public function ask($question, $default, $options = null)
            {
                if (strpos($question, 'output file path') !== false) {
                    return $this->outputPath;
                }
                // Return 'override' to merge resources
                return 'override';
            }
        };

        // Create and run the consolidator
        $consolidator = new SchemaConsolidatorGenerator($resource, $context);
        $consolidator->setLogger($logger);
        $consolidator->handle();

        // Read and verify output
        $output = json_decode(file_get_contents($outputPath), true);

        // Check that resources were merged
        $this->assertCount(2, $output['resources']);

        // Find the resources by name
        $resourcesByName = [];
        foreach ($output['resources'] as $resource) {
            $resourcesByName[$resource['name']] = $resource;
        }

        // Verify 'user' resource was preserved
        $this->assertArrayHasKey('user', $resourcesByName);
        $this->assertEquals(['name', 'email'], $resourcesByName['user']['model']['fillable']);

        // Verify 'post' resource was updated
        $this->assertArrayHasKey('post', $resourcesByName);
        $this->assertEquals(['title'], $resourcesByName['post']['model']['fillable']);
    }

    /** @test */
    public function it_merges_all_top_level_fields_from_existing_file_on_override()
    {
        // Create initial file with service and other extra fields
        $outputPath = $this->tempPath . '/output.json';
        $existingData = [
            'service' => [
                'name' => 'MyService',
                'version' => '1.0.0'
            ],
            'customField' => 'existingValue',
            'resources' => [
                [
                    'name' => 'user',
                    'model' => ['fillable' => ['name', 'email']]
                ]
            ]
        ];
        file_put_contents($outputPath, json_encode($existingData, JSON_PRETTY_PRINT));

        // New input with only schemas (no service key)
        $inputData = [
            'schemas' => [
                [
                    'name' => 'post',
                    'fields' => [],
                    'indexes' => [],
                    'relationships' => []
                ]
            ]
        ];

        // Create resource and context
        $resource = new Resource($inputData);
        $context = new PipelineContext();
        $context->set('input', new Resource($inputData));

        // Processed schemas
        $processedSchemas = [
            [
                'name' => 'post',
                'model' => ['fillable' => ['title']]
            ]
        ];
        $context->set('schemas', $processedSchemas);

        // Create a mock logger that returns 'override'
        $logger = new class($outputPath) {
            private $outputPath;

            public function __construct($outputPath)
            {
                $this->outputPath = $outputPath;
            }

            public function info($message) {}

            public function ask($question, $default, $options = null)
            {
                if (strpos($question, 'output file path') !== false) {
                    return $this->outputPath;
                }
                return 'override';
            }
        };

        // Create and run the consolidator
        $consolidator = new SchemaConsolidatorGenerator($resource, $context);
        $consolidator->setLogger($logger);
        $consolidator->handle();

        // Read and verify output
        $output = json_decode(file_get_contents($outputPath), true);

        // Verify service from existing file is preserved
        $this->assertArrayHasKey('service', $output);
        $this->assertEquals('MyService', $output['service']['name']);
        $this->assertEquals('1.0.0', $output['service']['version']);

        // Verify custom field from existing file is preserved
        $this->assertArrayHasKey('customField', $output);
        $this->assertEquals('existingValue', $output['customField']);

        // Verify resources were merged
        $this->assertCount(2, $output['resources']);

        $resourcesByName = [];
        foreach ($output['resources'] as $resource) {
            $resourcesByName[$resource['name']] = $resource;
        }

        // Verify 'user' from existing file was preserved
        $this->assertArrayHasKey('user', $resourcesByName);
        $this->assertEquals(['name', 'email'], $resourcesByName['user']['model']['fillable']);

        // Verify 'post' from new input was added
        $this->assertArrayHasKey('post', $resourcesByName);
        $this->assertEquals(['title'], $resourcesByName['post']['model']['fillable']);
    }

    /** @test */
    public function it_merges_extra_fields_from_new_input_on_override()
    {
        // Create initial file with service
        $outputPath = $this->tempPath . '/output.json';
        $existingData = [
            'service' => [
                'name' => 'MyService'
            ],
            'resources' => [
                [
                    'name' => 'user',
                    'model' => ['fillable' => ['name']]
                ]
            ]
        ];
        file_put_contents($outputPath, json_encode($existingData, JSON_PRETTY_PRINT));

        // New input with additional top-level fields
        $inputData = [
            'newField' => 'newValue',
            'anotherField' => ['nested' => 'data'],
            'schemas' => [
                [
                    'name' => 'comment',
                    'fields' => [],
                    'indexes' => [],
                    'relationships' => []
                ]
            ]
        ];

        // Create resource and context
        $resource = new Resource($inputData);
        $context = new PipelineContext();
        $context->set('input', new Resource($inputData));

        // Processed schemas
        $processedSchemas = [
            [
                'name' => 'comment',
                'model' => ['fillable' => ['body']]
            ]
        ];
        $context->set('schemas', $processedSchemas);

        // Create a mock logger that returns 'override'
        $logger = new class($outputPath) {
            private $outputPath;

            public function __construct($outputPath)
            {
                $this->outputPath = $outputPath;
            }

            public function info($message) {}

            public function ask($question, $default, $options = null)
            {
                if (strpos($question, 'output file path') !== false) {
                    return $this->outputPath;
                }
                return 'override';
            }
        };

        // Create and run the consolidator
        $consolidator = new SchemaConsolidatorGenerator($resource, $context);
        $consolidator->setLogger($logger);
        $consolidator->handle();

        // Read and verify output
        $output = json_decode(file_get_contents($outputPath), true);

        // Verify service from existing file is preserved
        $this->assertArrayHasKey('service', $output);
        $this->assertEquals('MyService', $output['service']['name']);

        // Verify new fields from input are added
        $this->assertArrayHasKey('newField', $output);
        $this->assertEquals('newValue', $output['newField']);

        $this->assertArrayHasKey('anotherField', $output);
        $this->assertEquals(['nested' => 'data'], $output['anotherField']);

        // Verify resources were merged
        $this->assertCount(2, $output['resources']);

        $resourcesByName = [];
        foreach ($output['resources'] as $resource) {
            $resourcesByName[$resource['name']] = $resource;
        }

        // Verify both resources exist
        $this->assertArrayHasKey('user', $resourcesByName);
        $this->assertArrayHasKey('comment', $resourcesByName);
    }

    /** @test */
    public function it_includes_all_input_keys_in_output()
    {
        // Create input with multiple top-level keys
        $inputData = [
            'version' => '2.0.0',
            'config' => ['namespace' => 'App\\Models'],
            'author' => 'John Doe',
            'customKey' => 'customValue',
            'schemas' => [
                [
                    'name' => 'test',
                    'fields' => [],
                    'indexes' => [],
                    'relationships' => []
                ]
            ]
        ];

        // Create resource and context
        $resource = new Resource($inputData);
        $context = new PipelineContext();

        // Set the full input in context (simulates what the pipeline does)
        $context->set('input', new Resource($inputData));

        // Minimal processed schema
        $processedSchemas = [
            ['name' => 'test', 'model' => []]
        ];

        $context->set('schemas', $processedSchemas);

        // Create output path
        $outputPath = $this->tempPath . '/output.json';

        // Create a mock logger
        $logger = new class($outputPath) {
            private $outputPath;

            public function __construct($outputPath)
            {
                $this->outputPath = $outputPath;
            }

            public function info($message) {}

            public function ask($question, $default, $options = null)
            {
                if (strpos($question, 'output file path') !== false) {
                    return $this->outputPath;
                }
                return 'overwrite';
            }
        };

        // Create and run the consolidator
        $consolidator = new SchemaConsolidatorGenerator($resource, $context);
        $consolidator->setLogger($logger);
        $consolidator->handle();

        // Read and verify output
        $output = json_decode(file_get_contents($outputPath), true);

        // Verify all input keys are preserved
        $this->assertEquals('2.0.0', $output['version']);
        $this->assertEquals(['namespace' => 'App\\Models'], $output['config']);
        $this->assertEquals('John Doe', $output['author']);
        $this->assertEquals('customValue', $output['customKey']);

        // Verify schemas key is still there (from input)
        $this->assertArrayHasKey('schemas', $output);

        // Verify resources key was added
        $this->assertArrayHasKey('resources', $output);
        $this->assertCount(1, $output['resources']);
    }
}
