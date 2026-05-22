<?php

namespace Firevel\ApiResourceSchemaGenerator\Tests;

use Firevel\ApiResourceSchemaGenerator\SaveFile;
use Firevel\Generator\Resource;

class SaveFileTest extends TestCase
{
    protected string $tempPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempPath = sys_get_temp_dir() . '/save-file-tests-' . uniqid();
        if (! is_dir($this->tempPath)) {
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

    protected function deleteDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (array_diff(scandir($dir), ['.', '..']) as $entry) {
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    /** @test */
    public function it_writes_the_processed_schema_to_the_chosen_path()
    {
        $outputPath = $this->tempPath . '/post.json';
        $resourceAttrs = [
            'name' => 'post',
            'output' => ['name' => 'post', 'model' => ['fillable' => ['title']]],
        ];

        $generator = $this->runGenerator(SaveFile::class, $resourceAttrs, [
            'output_path' => $outputPath,
        ]);

        $this->assertFileExists($outputPath);
        $written = json_decode(file_get_contents($outputPath), true);
        $this->assertSame($resourceAttrs['output'], $written);
    }

    /** @test */
    public function it_emits_the_processed_schema_so_chained_pipelines_can_consume_it()
    {
        // Reproduces the `firevel:generate api-resource-schema,api-resource --pipe` case.
        // After SaveFile runs, the chain context's `output` slot must hold the
        // processed schema so the downstream pipeline can consume it via `@output`.
        $processed = [
            'name' => 'post',
            'model' => ['fillable' => ['title']],
            'transformer' => ['transform' => ['id' => 'id', 'title' => 'title']],
            'migrations' => ['create' => [['name' => 'id', 'type' => 'increments']]],
            'requests' => ['store' => ['rules' => []], 'update' => ['rules' => []]],
        ];

        $generator = $this->runGenerator(
            SaveFile::class,
            ['name' => 'post', 'output' => $processed],
            ['output_path' => $this->tempPath . '/post.json']
        );

        $this->assertSame($processed, $generator->context()->get('output'));
    }
}
