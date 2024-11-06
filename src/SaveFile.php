<?php

namespace App\Generators;

use Firevel\Generator\Generators\BaseGenerator;
use Firevel\Generator\Resource;

class SaveFile extends BaseGenerator
{
    public function handle()
    {
        $resource = $this->resource();
        $path = $this->logger()->ask('Set output file path', 'storage/api-resources/' . $resource->name . '.json');
        file_put_contents($path, json_encode($resource->output, JSON_PRETTY_PRINT));
        $path = $this->logger()->info($path . ' generated');
    }
}
