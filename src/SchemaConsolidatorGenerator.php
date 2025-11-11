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
                // Load existing file and merge with existing resources
                $existingData = json_decode(file_get_contents($path), true);
                if (isset($existingData['resources']) && is_array($existingData['resources'])) {
                    $schemas = $this->mergeSchemas($existingData['resources'], $schemas);
                    $this->logger()->info('Merging with existing resources');
                }
            }
        }

        // Build the consolidated output structure
        // Start with all data from the full input (before scoping)
        $output = $this->input() ? $this->input()->all() : [];

        // Add the resources (processed schemas)
        $output['resources'] = $schemas;

        // Create directory if it doesn't exist
        $dir = dirname($path);
        if (!is_dir($dir) && !empty($dir) && $dir !== '.') {
            mkdir($dir, 0755, true);
        }

        // Write the consolidated file
        file_put_contents($path, json_encode($output, JSON_PRETTY_PRINT));

        $this->logger()->info("# Consolidated schemas file generated");
        $this->logger()->info("- File: {$path}");
        $this->logger()->info("- Schemas: " . count($schemas));
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
