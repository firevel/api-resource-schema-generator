<?php

namespace Firevel\ApiResourceSchemaGenerator;

use Firevel\Generator\Generators\BaseGenerator;
use Firevel\Generator\Resource;

class SaveFile extends BaseGenerator
{
    public function handle()
    {
        $resource = $this->resource();
        $path = $this->logger()->ask('Set output file path', 'schemas/api-resources/' . $resource->name . '/schema.json');

        // Create directory if it doesn't exist
        $dir = dirname($path);
        if (!is_dir($dir) && !empty($dir) && $dir !== '.') {
            mkdir($dir, 0755, true);
        }

        file_put_contents($path, json_encode($resource->output, JSON_PRETTY_PRINT));
        $this->logger()->info($path . ' generated');
    }
}
