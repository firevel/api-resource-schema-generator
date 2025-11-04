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

        // Build the consolidated output structure
        $output = [
            'resources' => $schemas,
        ];

        // Default path for consolidated schemas file
        $defaultPath = 'schemas/resources.json';

        // Ask for custom path (using ask if available in logger, otherwise use default)
        if (method_exists($this->logger(), 'ask')) {
            $path = $this->logger()->ask('Set output file path for consolidated schemas', $defaultPath);
        } else {
            $path = $defaultPath;
            $this->logger()->info("# Using default path: {$path}");
        }

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
}
