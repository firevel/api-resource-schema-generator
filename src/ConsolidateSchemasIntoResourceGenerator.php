<?php

namespace Firevel\ApiResourceSchemaGenerator;

/**
 * Bridge step for the one-shot `api-resource(s)-from-schema` pipelines.
 *
 * Consolidates the schemas collected during the transform phase into the
 * generator-format descriptor, writes it to a system temp file as an
 * inspectable artifact, and — crucially — injects the processed `resources`
 * (and `seeders`) onto the top-level Resource so the following
 * `{scope: resources.*, pipeline: api-resource}` step generates the code in
 * the same run. No project file (schemas/app.json) is written, and there is
 * no interactive merge/overwrite prompt — the temp file is always a fresh
 * write keyed by a unique name.
 *
 * Runs as a class step inside the meta-pipeline, so it receives the same
 * top-level Resource instance that ScopedPipelineRunner::resolveScope() reads
 * when it later resolves `resources.*`; mutating it here is what makes the
 * in-memory handoff work.
 */
class ConsolidateSchemasIntoResourceGenerator extends SchemaConsolidatorGenerator
{
    public static function description(): string
    {
        return 'Consolidate processed schemas, write the generator-format descriptor to a system temp file, and inject `resources` onto the top-level resource for direct code generation.';
    }

    public function handle()
    {
        $schemas = $this->context()->get('schemas', []);

        // Nothing collected — stay silent; the input validator already rejects
        // empty schema sets, so this only guards against an upstream no-op.
        if (empty($schemas)) {
            return;
        }

        $output = $this->buildConsolidatedOutput($schemas);

        // Resolution order: context `output_path` -> resource `output_path`
        // -> system temp default.
        $path = $this->context()->get('output_path')
            ?? $this->resource()->get('output_path')
            ?? $this->defaultOutputPath();

        $this->writeOutput($path, $output, count($schemas));

        // Hand the processed resources (and any transformed seeders) to the
        // following `resources.*` scope by setting them on the top-level
        // resource this generator shares with the scoped runner.
        $resource = $this->resource();
        $resource->resources = $output['resources'];
        if (isset($output['seeders'])) {
            $resource->seeders = $output['seeders'];
        }
    }

    /**
     * Write the intermediate descriptor to the system temp directory so it is
     * available for inspection without cluttering the project's schemas/.
     */
    protected function defaultOutputPath(): string
    {
        return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . 'firevel-api-resource-from-schema-' . uniqid() . '.json';
    }
}
