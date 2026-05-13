<?php

namespace Firevel\ApiResourceSchemaGenerator;

use Firevel\Generator\Generators\BaseGenerator;
use Firevel\Generator\Resource;

class SchemaConsolidatorGenerator extends BaseGenerator
{
    public function handle()
    {
        // Get all collected schemas from context
        $schemas = $this->context()->get('schemas', []);

        if (empty($schemas)) {
            $this->logger()->info("# No schemas to consolidate");
            return;
        }

        // Default path for consolidated schemas file
        $defaultPath = 'schemas/app.json';

        // Ask for custom path (using ask if available in logger, otherwise use default)
        if (method_exists($this->logger(), 'ask')) {
            $path = $this->logger()->ask('Set output file path for consolidated schemas', $defaultPath);
        } else {
            $path = $defaultPath;
            $this->logger()->info("# Using default path: {$path}");
        }

        // Check if file exists and ask user what to do
        if (file_exists($path)) {
            $action = $this->logger()->ask('File already exists. What would you like to do?', 'override', ['overwrite', 'override', 'skip', 'cancel']);

            if ($action === 'cancel') {
                $this->logger()->info('Operation cancelled');
                return;
            }

            if ($action === 'skip') {
                $this->logger()->info('Skipped: ' . $path);
                return;
            }

            if ($action === 'override') {
                // Load existing file and merge all fields
                $existingData = json_decode(file_get_contents($path), true);
                if (is_array($existingData)) {
                    // Merge resources array using name-based merge
                    if (isset($existingData['resources']) && is_array($existingData['resources'])) {
                        $schemas = $this->mergeSchemas($existingData['resources'], $schemas);
                    }
                    // Start with existing file, overlay new input (new input takes precedence)
                    $newInput = $this->input() ? $this->input()->all() : [];
                    $output = array_merge($existingData, $newInput);
                    $output['resources'] = $schemas;
                    $output = $this->mergeGeneratorRequires($output);
                    $this->logger()->info('Merging with existing file');

                    $this->writeOutput($path, $output, count($schemas));
                    return;
                }
            }
        }

        // Build the consolidated output structure
        // Start with all data from the full input (before scoping)
        $output = $this->input() ? $this->input()->all() : [];

        // Add the resources (processed schemas)
        $output['resources'] = $schemas;

        $output = $this->mergeGeneratorRequires($output);

        $this->writeOutput($path, $output, count($schemas));
    }

    /**
     * Merge generator-pushed Composer requires from PipelineContext into the
     * consolidated output's top-level `require` key, so downstream pipelines
     * (which see a fresh context per `firevel:generate` invocation) can pick
     * them up via input.require.
     *
     * Precedence: explicit input value wins; '*' from any source defers to a
     * concrete version from another source.
     */
    protected function mergeGeneratorRequires(array $output): array
    {
        $generatorRequires = (array) $this->context()->get('composer_requires', []);
        if (empty($generatorRequires)) {
            return $output;
        }

        $existing = (array) ($output['require'] ?? []);
        foreach ($generatorRequires as $package => $version) {
            // Existing concrete version wins over incoming '*'.
            if (isset($existing[$package]) && $existing[$package] !== '*' && $version === '*') {
                continue;
            }
            // Write when nothing set, or when existing is '*' (defer to concrete).
            if (!isset($existing[$package]) || $existing[$package] === '*') {
                $existing[$package] = $version;
            }
        }
        ksort($existing);
        $output['require'] = $existing;

        return $output;
    }

    /**
     * Write the consolidated output to file
     *
     * @param string $path
     * @param array $output
     * @param int $schemaCount
     * @return void
     */
    protected function writeOutput(string $path, array $output, int $schemaCount): void
    {
        // Create directory if it doesn't exist
        $dir = dirname($path);
        if (!is_dir($dir) && !empty($dir) && $dir !== '.') {
            mkdir($dir, 0755, true);
        }

        // Write the consolidated file
        file_put_contents($path, json_encode($output, JSON_PRETTY_PRINT));

        $this->logger()->info("# Consolidated schemas file generated");
        $this->logger()->info("- File: {$path}");
        $this->logger()->info("- Schemas: " . $schemaCount);
    }

    /**
     * Merge existing schemas with new schemas, overriding by schema name
     *
     * @param array $existingSchemas
     * @param array $newSchemas
     * @return array
     */
    protected function mergeSchemas(array $existingSchemas, array $newSchemas): array
    {
        // Create a map of existing schemas by name
        $schemaMap = [];
        foreach ($existingSchemas as $schema) {
            if (isset($schema['name'])) {
                $schemaMap[$schema['name']] = $schema;
            }
        }

        // Override with new schemas
        foreach ($newSchemas as $schema) {
            if (isset($schema['name'])) {
                $schemaMap[$schema['name']] = $schema;
            }
        }

        // Return as indexed array
        return array_values($schemaMap);
    }
}
