<?php

namespace Firevel\ApiResourceSchemaGenerator;

use Firevel\Generator\Generators\BaseGenerator;

class SaveFile extends BaseGenerator
{
    public static function description(): string
    {
        return 'Save the processed resource schema as a JSON file under schemas/api-resources/<name>/schema.json.';
    }

    public function handle()
    {
        $resource = $this->resource();
        $defaultPath = 'schemas/api-resources/' . $resource->name . '/schema.json';
        $headless = $this->isDryRun() || $this->shouldSkipExisting();

        // Resolution order: context `output_path` -> resource `output_path`
        // -> interactive prompt (CLI only) -> hardcoded default (headless).
        $path = $this->context()->get('output_path')
            ?? $resource->get('output_path')
            ?? ($headless
                ? $defaultPath
                : (string) $this->logger()->ask('Set output file path', $defaultPath));

        $pathPreSet = $this->context()->has('output_path') || $resource->has('output_path');

        // Existing-file overwrite/skip/cancel prompt only fires in a real CLI
        // session with no up-front path decision. Headless / pre-set runs
        // delegate to createFile() (which honors --skip-existing).
        if (!$headless && !$pathPreSet && file_exists($path)) {
            $action = (string) $this->logger()->ask(
                'File already exists. What would you like to do?',
                'overwrite',
                ['overwrite', 'skip', 'cancel']
            );

            if ($action === 'cancel') {
                $this->logger()->info('cancelled');
                return;
            }
            if ($action === 'skip') {
                $this->logger()->info("skipped {$path} (user declined)");
                return;
            }
        }

        $this->createFile($path, json_encode($resource->output, JSON_PRETTY_PRINT));

        // Expose the processed schema so a chained pipeline can consume it
        // via `--json=@output` or the `--pipe` flag (e.g.
        // `firevel:generate api-resource-schema,api-resource --pipe`).
        // Mirrors SchemaConsolidatorGenerator's behavior for multi-resource runs.
        $this->emitOutput($resource->output);
    }
}
