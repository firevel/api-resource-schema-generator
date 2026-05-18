<?php

namespace Firevel\ApiResourceSchemaGenerator;

use Firevel\Generator\Generators\BaseGenerator;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Translate the LLM-facing seeder JSON (per-resource maps with directives
 * `$ref / $now / $hash / $uuid` and `_ref` row labels) into the flat,
 * generator-level seeder format consumed by `firevel/generator`'s
 * `seeders` pipeline.
 *
 * Reads `seeders` and `schemas` from the resource (passed as the full
 * pre-scoped input via either a `scope: '.'` step or the standalone
 * `seeders-transform` pipeline). Pushes the result to
 * `context['transformed_seeders']` for `SchemaConsolidatorGenerator` to
 * fold into its output, and also emits the merged structure so the
 * pipeline is usable on its own via `--pipe` / `@output`.
 */
class SeedersTransformerGenerator extends BaseGenerator
{
    public static function description(): string
    {
        return 'Translate LLM-format seeder JSON into the generator-level seeder format.';
    }

    public function handle()
    {
        if (! $this->resource()->has('seeders')) {
            return; // silent no-op — input simply doesn't carry a seeders block
        }
        $seeders = $this->resource()->get('seeders');
        if (! is_array($seeders) || $seeders === []) {
            return;
        }

        $schemas = $this->resource()->has('schemas') ? $this->resource()->get('schemas') : [];
        if (! is_array($schemas)) {
            $schemas = [];
        }

        $schemasByName = $this->indexSchemasByName($schemas);

        // Build a global flat list and label index across all sets so that
        // a $ref from one set (e.g. "demo") can resolve to a row in another
        // set (e.g. "system"). Such a cross-set $ref does NOT impose a
        // topo-sort constraint on the *referring* set — system runs first,
        // demo second; the FK lookup happens at insert time.
        [$flat, $labelIndex] = $this->buildIndex($seeders);

        $transformed = [];
        $totalRows = 0;
        foreach ($seeders as $setName => $rowsByResource) {
            $entries = $this->transformSet($setName, $rowsByResource, $schemasByName, $flat, $labelIndex);
            $transformed[$setName] = $entries;
            $totalRows += count($entries);
        }

        $this->context()->set('transformed_seeders', $transformed);

        // Expose the merged structure for standalone runs (chained via @output).
        $output = $this->resource()->all();
        $output['seeders'] = $transformed;
        $this->emitOutput($output);

        $this->logger()?->info("seeders: transformed {$totalRows} row(s) across " . count($transformed) . ' set(s)');
    }

    /**
     * Build a global flat list `[i => {set, resource, row, ref}]` plus a
     * label index `"Resource.label" => global flat-list i`. Validates
     * shape at every level and rejects duplicate labels.
     *
     * @return array{0: array<int, array{set:string,resource:string,row:array<string,mixed>,ref:?string}>, 1: array<string, int>}
     */
    private function buildIndex(array $seeders): array
    {
        $flat = [];
        $labelIndex = [];

        foreach ($seeders as $setName => $rowsByResource) {
            if (! is_string($setName) || $setName === '') {
                throw new InvalidArgumentException(
                    'seeders keys must be non-empty strings naming a set (e.g. "system", "demo").'
                );
            }
            if (! is_array($rowsByResource)) {
                throw new InvalidArgumentException(
                    "seeders.{$setName} must be an object keyed by resource name."
                );
            }

            foreach ($rowsByResource as $resourceName => $rows) {
                if (! is_string($resourceName) || $resourceName === '') {
                    throw new InvalidArgumentException(
                        "seeders.{$setName} keys must be non-empty resource names (PascalCase)."
                    );
                }
                if (! is_array($rows)) {
                    throw new InvalidArgumentException(
                        "seeders.{$setName}.{$resourceName} must be an array of row objects."
                    );
                }

                foreach ($rows as $idx => $row) {
                    if (! is_array($row)) {
                        throw new InvalidArgumentException(
                            "seeders.{$setName}.{$resourceName}[{$idx}] must be an object."
                        );
                    }

                    $globalIdx = count($flat);
                    $ref = isset($row['_ref']) && is_string($row['_ref']) ? $row['_ref'] : null;
                    $flat[] = [
                        'set' => $setName,
                        'resource' => $resourceName,
                        'row' => $row,
                        'ref' => $ref,
                    ];

                    if ($ref !== null) {
                        $key = "{$resourceName}.{$ref}";
                        if (isset($labelIndex[$key])) {
                            throw new InvalidArgumentException(
                                "seeders: duplicate _ref label '{$key}' across sets — labels must be unique per resource."
                            );
                        }
                        $labelIndex[$key] = $globalIdx;
                    }
                }
            }
        }

        return [$flat, $labelIndex];
    }

    /**
     * @param array<int, mixed> $schemas
     * @return array<string, array<string, mixed>>
     */
    private function indexSchemasByName(array $schemas): array
    {
        $by = [];
        foreach ($schemas as $schema) {
            if (! is_array($schema) || empty($schema['name'])) {
                continue;
            }
            $by[(string) $schema['name']] = $schema;
        }
        return $by;
    }

    /**
     * @param array<string, array<int, mixed>> $rowsByResource
     * @param array<string, array<string, mixed>> $schemasByName
     * @param array<int, array{set:string,resource:string,row:array<string,mixed>,ref:?string}> $flat
     * @param array<string, int> $labelIndex
     * @return array<int, array<string, array<string, mixed>>>
     */
    private function transformSet(
        string $setName,
        array $rowsByResource,
        array $schemasByName,
        array $flat,
        array $labelIndex
    ): array {
        // Collect the global indices of rows in THIS set, preserving the
        // insertion order recorded during the global pass.
        $setIndices = [];
        foreach ($flat as $i => $item) {
            if ($item['set'] === $setName) {
                $setIndices[] = $i;
            }
        }
        if ($setIndices === []) {
            return [];
        }

        // For topo-sort purposes we only care about deps that point at
        // rows in the SAME set; cross-set $refs (e.g. demo → system) are
        // resolved at insert time and don't constrain ordering here.
        $localPos = array_flip($setIndices); // global idx => local pos
        $localDeps = [];
        foreach ($setIndices as $localPosIdx => $globalIdx) {
            $localDeps[$localPosIdx] = $this->collectRefDeps(
                $flat[$globalIdx]['row'],
                $labelIndex,
                $flat,
                $setName,
                $globalIdx,
                $localPos
            );
        }

        $sortedLocal = $this->topoSort(count($setIndices), $localDeps, $setName);

        $natKeyCache = [];
        $entries = [];
        foreach ($sortedLocal as $localPosIdx) {
            $globalIdx = $setIndices[$localPosIdx];
            $item = $flat[$globalIdx];
            $entries[] = $this->transformRow(
                $item['resource'],
                $item['row'],
                $schemasByName,
                $labelIndex,
                $flat,
                $natKeyCache,
                "{$setName}.{$item['resource']}[{$globalIdx}]"
            );
        }

        return $entries;
    }

    /**
     * Collect intra-set dependencies for one row. Walks the row tree,
     * resolves each `$ref` against the global label index, and keeps
     * only those whose target sits in the same set (mapped through
     * `$localPos`). Cross-set refs are validated (must exist) but
     * dropped from the topo graph.
     *
     * @param array<string, mixed> $row
     * @param array<string, int>   $labelIndex
     * @param array<int, array<string, mixed>> $flat
     * @param array<int, int> $localPos  global idx => local pos
     * @return array<int, int>  list of local-position dep indices
     */
    private function collectRefDeps(
        array $row,
        array $labelIndex,
        array $flat,
        string $setName,
        int $selfGlobalIdx,
        array $localPos
    ): array {
        $deps = [];
        $this->walkForRefs($row, $labelIndex, $flat, $setName, $selfGlobalIdx, $localPos, $deps);
        $deps = array_values(array_unique($deps));
        return array_values(array_filter($deps, fn ($d) => $d !== ($localPos[$selfGlobalIdx] ?? -1)));
    }

    /**
     * Recursively walk a value, validating every `$ref` and accumulating
     * intra-set dependency edges (local positions).
     *
     * @param array<int, int> $out
     */
    private function walkForRefs(
        mixed $value,
        array $labelIndex,
        array $flat,
        string $setName,
        int $selfGlobalIdx,
        array $localPos,
        array &$out
    ): void {
        if (! is_array($value)) {
            return;
        }

        if (count($value) === 1) {
            $k = array_key_first($value);
            if ($k === '$ref') {
                $ref = (string) $value[$k];
                if (! isset($labelIndex[$ref])) {
                    throw new InvalidArgumentException(
                        "seeders.{$setName}: \$ref '{$ref}' has no matching _ref label."
                    );
                }
                $depGlobalIdx = $labelIndex[$ref];
                // Only same-set deps influence topo order.
                if (isset($localPos[$depGlobalIdx])) {
                    $out[] = $localPos[$depGlobalIdx];
                }
                return;
            }
            // Other directives ($now / $hash / $uuid) don't introduce deps.
            if (is_string($k) && str_starts_with($k, '$')) {
                return;
            }
        }

        // Walk nested structures (assoc maps and lists).
        foreach ($value as $sub) {
            $this->walkForRefs($sub, $labelIndex, $flat, $setName, $selfGlobalIdx, $localPos, $out);
        }
    }

    /**
     * Kahn topological sort. Ties are broken by original insertion order
     * (flat-list index ascending).
     *
     * @param array<int, array<int, int>> $deps  i => list of dependency indices
     * @return array<int, int>
     */
    private function topoSort(int $n, array $deps, string $setName): array
    {
        $inDegree = [];
        for ($i = 0; $i < $n; $i++) {
            $inDegree[$i] = count($deps[$i] ?? []);
        }

        // Reverse adjacency: dependent_of[dep] = list of items depending on dep.
        $dependents = [];
        foreach ($deps as $i => $list) {
            foreach ($list as $d) {
                $dependents[$d][] = $i;
            }
        }

        $ready = [];
        foreach ($inDegree as $i => $deg) {
            if ($deg === 0) {
                $ready[] = $i;
            }
        }
        sort($ready);

        $out = [];
        while ($ready !== []) {
            $i = array_shift($ready);
            $out[] = $i;
            foreach ($dependents[$i] ?? [] as $dep) {
                $inDegree[$dep]--;
                if ($inDegree[$dep] === 0) {
                    $ready[] = $dep;
                }
            }
            sort($ready);
        }

        if (count($out) !== $n) {
            throw new RuntimeException(
                "seeders.{$setName}: cycle detected in \$ref dependencies; cannot determine insertion order."
            );
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, array<string, mixed>> $schemasByName
     * @param array<string, int> $labelIndex
     * @param array<int, array{resource: string, row: array<string, mixed>, ref: ?string}> $flat
     * @param array<string, string> $natKeyCache
     * @return array<string, array<string, mixed>>
     */
    private function transformRow(
        string $resourceName,
        array $row,
        array $schemasByName,
        array $labelIndex,
        array $flat,
        array &$natKeyCache,
        string $path
    ): array {
        $clean = $row;
        unset($clean['_ref']);

        $transformed = [];
        foreach ($clean as $col => $value) {
            $transformed[$col] = $this->transformValue(
                $value,
                $schemasByName,
                $labelIndex,
                $flat,
                $natKeyCache,
                "{$path}.{$col}"
            );
        }

        return [$this->modelClass($resourceName) => $transformed];
    }

    /**
     * @param array<string, array<string, mixed>> $schemasByName
     * @param array<string, int> $labelIndex
     * @param array<int, array{resource: string, row: array<string, mixed>, ref: ?string}> $flat
     * @param array<string, string> $natKeyCache
     */
    private function transformValue(
        mixed $value,
        array $schemasByName,
        array $labelIndex,
        array $flat,
        array &$natKeyCache,
        string $path
    ): mixed {
        if (! is_array($value)) {
            return $value;
        }

        // Single-key map with a $-prefixed directive key.
        if (count($value) === 1) {
            $k = (string) array_key_first($value);
            if (str_starts_with($k, '$')) {
                return $this->resolveDirective($k, $value[$k], $schemasByName, $labelIndex, $flat, $natKeyCache, $path);
            }
        }

        // Pass other arrays through. Scalar lists are valid generator values;
        // other shapes the schema layer doesn't produce, but we don't
        // strip / re-encode them either.
        return $value;
    }

    /**
     * @param array<string, array<string, mixed>> $schemasByName
     * @param array<string, int> $labelIndex
     * @param array<int, array{resource: string, row: array<string, mixed>, ref: ?string}> $flat
     * @param array<string, string> $natKeyCache
     * @return array<string, mixed>
     */
    private function resolveDirective(
        string $directive,
        mixed $arg,
        array $schemasByName,
        array $labelIndex,
        array $flat,
        array &$natKeyCache,
        string $path
    ): array {
        switch ($directive) {
            case '$now':
                return ['Illuminate\\Support\\Carbon' => ['now' => null]];
            case '$uuid':
                return ['Illuminate\\Support\\Str' => ['uuid' => null]];
            case '$hash':
                if (! is_string($arg)) {
                    throw new InvalidArgumentException("{$path}: \$hash requires a string argument.");
                }
                return ['Illuminate\\Support\\Facades\\Hash' => ['make' => $arg]];
            case '$ref':
                if (! is_string($arg)) {
                    throw new InvalidArgumentException("{$path}: \$ref requires a 'Resource.label' string argument.");
                }
                return $this->resolveRef($arg, $schemasByName, $labelIndex, $flat, $natKeyCache, $path);
            default:
                throw new InvalidArgumentException("{$path}: unknown directive '{$directive}'.");
        }
    }

    /**
     * @param array<string, array<string, mixed>> $schemasByName
     * @param array<string, int> $labelIndex
     * @param array<int, array{resource: string, row: array<string, mixed>, ref: ?string}> $flat
     * @param array<string, string> $natKeyCache
     * @return array<string, array<string, mixed>>
     */
    private function resolveRef(
        string $ref,
        array $schemasByName,
        array $labelIndex,
        array $flat,
        array &$natKeyCache,
        string $path
    ): array {
        $parts = explode('.', $ref, 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new InvalidArgumentException("{$path}: invalid \$ref '{$ref}' (expected 'Resource.label').");
        }
        [$resourceName, $label] = $parts;

        if (! isset($labelIndex[$ref])) {
            throw new InvalidArgumentException("{$path}: \$ref '{$ref}' has no matching _ref label.");
        }

        $naturalKey = $this->getNaturalKey($resourceName, $schemasByName, $natKeyCache, $path);

        $referencedRow = $flat[$labelIndex[$ref]]['row'];
        if (! array_key_exists($naturalKey, $referencedRow)) {
            throw new InvalidArgumentException(
                "{$path}: \$ref '{$ref}' resolves to a row in '{$resourceName}' but the row has no '{$naturalKey}' column (the resource's natural key)."
            );
        }
        $naturalValue = $referencedRow[$naturalKey];

        if (is_array($naturalValue)) {
            throw new InvalidArgumentException(
                "{$path}: \$ref '{$ref}' natural-key column '{$naturalKey}' is a directive, not a literal scalar. Pre-resolve it in the source data."
            );
        }

        return [$this->modelClass($resourceName) => [
            'where' => [$naturalKey, $naturalValue],
            'value' => 'id',
        ]];
    }

    /**
     * Choose the natural-key column for a resource. Preference order:
     * `code` → `slug` → `name` → `email` → first unique non-PK column.
     * Throws if the resource has no unique non-PK column at all.
     *
     * @param array<string, array<string, mixed>> $schemasByName
     * @param array<string, string> $cache
     */
    private function getNaturalKey(string $resourceName, array $schemasByName, array &$cache, string $path): string
    {
        if (isset($cache[$resourceName])) {
            return $cache[$resourceName];
        }

        if (! isset($schemasByName[$resourceName])) {
            throw new InvalidArgumentException(
                "{$path}: cannot resolve \$ref to unknown resource '{$resourceName}'. "
                . 'Available resources: ' . (empty($schemasByName) ? '(none)' : implode(', ', array_keys($schemasByName)))
            );
        }

        $fields = $schemasByName[$resourceName]['fields'] ?? [];
        if (! is_array($fields)) {
            $fields = [];
        }

        $uniques = [];
        foreach ($fields as $field) {
            if (! is_array($field) || empty($field['name'])) {
                continue;
            }
            $index = $field['index'] ?? null;
            $type = $field['type'] ?? null;
            $isPk = $index === 'primary' || $index === 'auto-increment' || $type === 'increments';
            if ($index === 'unique' && ! $isPk) {
                $uniques[] = (string) $field['name'];
            }
        }

        if ($uniques === []) {
            throw new InvalidArgumentException(
                "{$path}: resource '{$resourceName}' has no unique non-PK column to serve as a natural key for \$ref resolution. "
                . 'Mark one of its columns with `index: "unique"`.'
            );
        }

        foreach (['code', 'slug', 'name', 'email'] as $preferred) {
            if (in_array($preferred, $uniques, true)) {
                return $cache[$resourceName] = $preferred;
            }
        }

        return $cache[$resourceName] = $uniques[0];
    }

    private function modelClass(string $resourceName): string
    {
        return 'App\\Models\\' . Str::studly($resourceName);
    }
}
