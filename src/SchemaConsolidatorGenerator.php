<?php

namespace Firevel\ApiResourceSchemaGenerator;

use Firevel\Generator\Generators\BaseGenerator;
use Firevel\Generator\Resource;

class SchemaConsolidatorGenerator extends BaseGenerator
{
    public static function description(): string
    {
        return 'Consolidate processed schemas (and accumulated composer requires) into schemas/app.json, suitable for chaining into generic-app / appengine-app.';
    }

    public function handle()
    {
        // Get all collected schemas from context
        $schemas = $this->context()->get('schemas', []);

        // Nothing to do — stay silent; the outer pipeline summary covers
        // the empty-state case.
        if (empty($schemas)) {
            return;
        }

        $defaultPath = $this->defaultOutputPath();
        $headless = $this->isDryRun() || $this->shouldSkipExisting();

        // Resolution order for the output path:
        //   1. context `output_path` — set by callers (tests, programmatic use)
        //   2. resource `output_path` attribute — set in the input JSON
        //   3. interactive prompt — only when we're in a real CLI session
        //   4. hardcoded default — when running headless without injection
        $path = $this->context()->get('output_path')
            ?? $this->resource()->get('output_path')
            ?? ($headless
                ? $defaultPath
                : (string) $this->logger()->ask('Set output file path for consolidated schemas', $defaultPath));

        $pathPreSet = $this->context()->has('output_path')
            || $this->resource()->has('output_path');

        // Interactive merge prompt fires only in genuine CLI sessions with no
        // up-front decision. `context.merge_existing` (bool) forces the merge
        // path for programmatic / test use.
        $shouldMergeWithExisting = (bool) $this->context()->get('merge_existing', false);
        $interactivePromptApplies = !$headless && !$pathPreSet && !$shouldMergeWithExisting;

        if ($interactivePromptApplies && file_exists($path)) {
            $action = (string) $this->logger()->ask(
                'File already exists. What would you like to do?',
                'override',
                ['overwrite', 'override', 'skip', 'cancel']
            );

            if ($action === 'cancel') {
                $this->logger()->info('cancelled');
                return;
            }
            if ($action === 'skip') {
                $this->logger()->info("skipped {$path} (user declined)");
                return;
            }
            if ($action === 'override') {
                $shouldMergeWithExisting = true;
            }
        }

        if ($shouldMergeWithExisting) {
            $existingData = json_decode(file_get_contents($path), true);
            if (is_array($existingData)) {
                if (isset($existingData['resources']) && is_array($existingData['resources'])) {
                    $schemas = $this->mergeSchemas($existingData['resources'], $schemas);
                }
                $newInput = $this->input() ? $this->input()->all() : [];
                $output = array_merge($existingData, $newInput);
                $output['resources'] = $schemas;
                $output = $this->mergeTransformedSeeders($output);
                $output = $this->mergeGeneratorRequires($output);
                $this->logger()->info("merging with existing {$path}");

                $this->writeOutput($path, $output, count($schemas));
                return;
            }
        }

        $output = $this->buildConsolidatedOutput($schemas);

        $this->writeOutput($path, $output, count($schemas));
    }

    /**
     * Default path for the consolidated descriptor. Overridable by subclasses
     * (e.g. the one-shot api-resource(s)-from-schema bridge writes to a system
     * temp file rather than into the project's schemas/ directory).
     */
    protected function defaultOutputPath(): string
    {
        return 'schemas/app.json';
    }

    /**
     * Build the consolidated output structure from the collected schemas.
     *
     * Starts from the full pre-scoped input so non-`schemas` top-level keys
     * (service, metadata, etc.) survive, sets `resources` to the processed
     * schemas, then folds in the generator-level seeders and Composer requires
     * accumulated in the pipeline context.
     */
    protected function buildConsolidatedOutput(array $schemas): array
    {
        // Start with all data from the full input (before scoping)
        $output = $this->input() ? $this->input()->all() : [];

        // Add the resources (processed schemas)
        $output['resources'] = $schemas;

        $output = $this->mergeTransformedSeeders($output);
        $output = $this->mergeGeneratorRequires($output);

        return $output;
    }

    /**
     * Replace the LLM-format `seeders` block (if any) with the
     * generator-level version that `SeedersTransformerGenerator` pushed
     * to the shared PipelineContext during this run. Downstream pipelines
     * see a fresh PipelineContext per `firevel:generate` invocation, so
     * the transformed seeders have to land in the consolidated JSON to
     * survive the chain hop.
     */
    protected function mergeTransformedSeeders(array $output): array
    {
        $transformed = $this->context()->get('transformed_seeders');
        if (is_array($transformed)) {
            $output['seeders'] = $transformed;
        }
        return $output;
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
        $this->createFile($path, json_encode($output, JSON_PRETTY_PRINT));

        // Expose the consolidated structure so a chained pipeline can consume
        // it via `--json=@output` (chained context is shared since 0.8).
        $this->emitOutput($output);

        $this->logger()->info("wrote {$path} ({$schemaCount} schema" . ($schemaCount === 1 ? '' : 's') . ")");
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
