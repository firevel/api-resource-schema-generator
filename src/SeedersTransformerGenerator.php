<?php

namespace Firevel\ApiResourceSchemaGenerator;

use Firevel\Generator\Generators\BaseGenerator;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Translate the LLM-facing seeder JSON into the generator-level seeder
 * format consumed by `firevel/generator`'s `seeders` pipeline.
 *
 * Input shape (LLM-facing, statically-typed for structured outputs —
 * all keys are fixed, all collections are arrays of `{name, …}`):
 *
 *   "seeders": [
 *     {
 *       "name": "system",
 *       "resources": [
 *         {
 *           "name": "Role",
 *           "rows": [
 *             { "ref": "admin",
 *               "data": { "name": "admin", "description": "Full access" } }
 *           ]
 *         }
 *       ]
 *     }
 *   ]
 *
 * Each row carries an optional `ref` (its addressable handle) and a
 * required `data` map of column → Value. Values are scalars, lists,
 * or directive objects of the form `{"$": "<verb>", …args}`:
 *
 *   { "$": "ref",  "resource": "Role", "ref": "admin" }
 *   { "$": "hash", "value": "secret" }
 *   { "$": "uuid" }
 *   { "$": "now"  }
 *
 * Output (in PipelineContext under `transformed_seeders`, picked up
 * by `SchemaConsolidatorGenerator`) is the generator-level format —
 * object keyed by set name with each entry as `{ClassFQN: cols}`:
 *
 *   "seeders": {
 *     "system": [
 *       { "App\\Models\\Role": { "name": "admin", "description": "Full access" } }
 *     ]
 *   }
 *
 * Refs can cross sets (a `demo` row can reference a `system` row); those
 * resolve at insert time and don't constrain topo order within the
 * referring set.
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
            return; // silent no-op when input lacks seeders
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

        // Build global flat list + label index across all sets so a ref
        // from one set can resolve to a row in another (demo → system).
        [$flat, $labelIndex] = $this->buildIndex($seeders);

        $transformed = [];
        $totalRows = 0;
        foreach ($seeders as $setEntry) {
            $setName = $setEntry['name'];
            $entries = $this->transformSet($setName, $schemasByName, $flat, $labelIndex);
            $transformed[$setName] = $entries;
            $totalRows += count($entries);
        }

        $this->context()->set('transformed_seeders', $transformed);

        // Expose merged structure for standalone runs (chained via @output).
        $output = $this->resource()->all();
        $output['seeders'] = $transformed;
        $this->emitOutput($output);

        $this->logger()?->info("seeders: transformed {$totalRows} row(s) across " . count($transformed) . ' set(s)');
    }

    /**
     * @param array<int, array<string, mixed>> $schemas
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
     * Walk the array-shaped envelope once. Validates every level and
     * returns:
     *   - $flat: list of `{set, resource, data, ref}` records (one per row,
     *     in input order — across all sets).
     *   - $labelIndex: `"Resource.ref"` → global flat-list index.
     *
     * @return array{0: array<int, array{set:string,resource:string,data:array<string,mixed>,ref:?string}>, 1: array<string, int>}
     */
    private function buildIndex(array $seeders): array
    {
        $flat = [];
        $labelIndex = [];

        foreach ($seeders as $setIdx => $setEntry) {
            if (! is_array($setEntry)) {
                throw new InvalidArgumentException(
                    "seeders[{$setIdx}] must be an object {name, resources}."
                );
            }
            $setName = $setEntry['name'] ?? null;
            if (! is_string($setName) || $setName === '') {
                throw new InvalidArgumentException(
                    "seeders[{$setIdx}].name must be a non-empty string (e.g. \"system\", \"demo\")."
                );
            }

            $resources = $setEntry['resources'] ?? [];
            if (! is_array($resources)) {
                throw new InvalidArgumentException(
                    "seeders[{$setIdx}].resources must be an array of {name, rows} objects."
                );
            }

            foreach ($resources as $resIdx => $resEntry) {
                if (! is_array($resEntry)) {
                    throw new InvalidArgumentException(
                        "seeders[{$setIdx}].resources[{$resIdx}] must be an object {name, rows}."
                    );
                }
                $resourceName = $resEntry['name'] ?? null;
                if (! is_string($resourceName) || $resourceName === '') {
                    throw new InvalidArgumentException(
                        "seeders[{$setIdx}].resources[{$resIdx}].name must be a non-empty string (PascalCase resource name)."
                    );
                }

                $rows = $resEntry['rows'] ?? [];
                if (! is_array($rows)) {
                    throw new InvalidArgumentException(
                        "seeders[{$setIdx}].resources[{$resIdx}].rows must be an array of row objects."
                    );
                }

                foreach ($rows as $rowIdx => $row) {
                    if (! is_array($row)) {
                        throw new InvalidArgumentException(
                            "seeders[{$setIdx}].resources[{$resIdx}].rows[{$rowIdx}] must be an object."
                        );
                    }
                    $data = $row['data'] ?? null;
                    if (! is_array($data)) {
                        throw new InvalidArgumentException(
                            "seeders[{$setIdx}].resources[{$resIdx}].rows[{$rowIdx}].data must be an object of column → value."
                        );
                    }
                    $ref = isset($row['ref']) && is_string($row['ref']) && $row['ref'] !== '' ? $row['ref'] : null;

                    $globalIdx = count($flat);
                    $flat[] = [
                        'set' => $setName,
                        'resource' => $resourceName,
                        'data' => $data,
                        'ref' => $ref,
                    ];

                    if ($ref !== null) {
                        $key = "{$resourceName}.{$ref}";
                        if (isset($labelIndex[$key])) {
                            throw new InvalidArgumentException(
                                "seeders: duplicate ref '{$key}' across rows — refs must be unique per resource."
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
     * @param array<string, array<string, mixed>> $schemasByName
     * @param array<int, array{set:string,resource:string,data:array<string,mixed>,ref:?string}> $flat
     * @param array<string, int> $labelIndex
     * @return array<int, array<string, array<string, mixed>>>
     */
    private function transformSet(
        string $setName,
        array $schemasByName,
        array $flat,
        array $labelIndex
    ): array {
        $setIndices = [];
        foreach ($flat as $i => $item) {
            if ($item['set'] === $setName) {
                $setIndices[] = $i;
            }
        }
        if ($setIndices === []) {
            return [];
        }

        // Only intra-set deps influence topo order; cross-set refs are
        // resolved at insert time (system runs first, demo second).
        $localPos = array_flip($setIndices); // global idx => local pos
        $localDeps = [];
        foreach ($setIndices as $localPosIdx => $globalIdx) {
            $localDeps[$localPosIdx] = $this->collectRefDeps(
                $flat[$globalIdx]['data'],
                $labelIndex,
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
                $item['data'],
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
     * Collect intra-set dependency edges (as local positions). Walks the
     * row's data tree, finds every `{"$": "ref", …}` directive, validates
     * its target exists, and keeps only same-set deps.
     *
     * @param array<string, mixed> $data
     * @param array<string, int>   $labelIndex
     * @param array<int, int>      $localPos  global idx => local pos
     * @return array<int, int>
     */
    private function collectRefDeps(
        array $data,
        array $labelIndex,
        string $setName,
        int $selfGlobalIdx,
        array $localPos
    ): array {
        $deps = [];
        $this->walkForRefs($data, $labelIndex, $setName, $selfGlobalIdx, $localPos, $deps);
        $deps = array_values(array_unique($deps));
        return array_values(array_filter($deps, fn ($d) => $d !== ($localPos[$selfGlobalIdx] ?? -1)));
    }

    /**
     * Recursive walker: any object with a string `"$"` discriminator is a
     * directive (terminal — don't recurse further). Anything else (assoc
     * map, list) gets walked.
     *
     * @param array<int, int> $out
     */
    private function walkForRefs(
        mixed $value,
        array $labelIndex,
        string $setName,
        int $selfGlobalIdx,
        array $localPos,
        array &$out
    ): void {
        if (! is_array($value)) {
            return;
        }

        if (isset($value['$']) && is_string($value['$'])) {
            if ($value['$'] === 'ref') {
                $resource = $value['resource'] ?? null;
                $ref = $value['ref'] ?? null;
                if (! is_string($resource) || $resource === '' || ! is_string($ref) || $ref === '') {
                    throw new InvalidArgumentException(
                        "seeders.{$setName}: ref directive requires non-empty 'resource' and 'ref' strings."
                    );
                }
                $key = "{$resource}.{$ref}";
                if (! isset($labelIndex[$key])) {
                    throw new InvalidArgumentException(
                        "seeders.{$setName}: ref to '{$key}' has no matching row."
                    );
                }
                $depGlobalIdx = $labelIndex[$key];
                if (isset($localPos[$depGlobalIdx])) {
                    $out[] = $localPos[$depGlobalIdx];
                }
                return;
            }
            if ($value['$'] === 'nested') {
                // Recurse into the nested child's data: any ref it contains
                // constrains the parent row's insertion order (the parent
                // statement embeds the child's create() inline, so all of
                // the child's deps must be ready first).
                $nestedData = $value['data'] ?? null;
                if (is_array($nestedData)) {
                    foreach ($nestedData as $sub) {
                        $this->walkForRefs($sub, $labelIndex, $setName, $selfGlobalIdx, $localPos, $out);
                    }
                }
                return;
            }
            // hash / now / uuid don't introduce deps.
            return;
        }

        foreach ($value as $sub) {
            $this->walkForRefs($sub, $labelIndex, $setName, $selfGlobalIdx, $localPos, $out);
        }
    }

    /**
     * Kahn topo sort with original-order tiebreak.
     *
     * @param array<int, array<int, int>> $deps  local pos => list of dep local positions
     * @return array<int, int>
     */
    private function topoSort(int $n, array $deps, string $setName): array
    {
        $inDegree = [];
        for ($i = 0; $i < $n; $i++) {
            $inDegree[$i] = count($deps[$i] ?? []);
        }

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
                "seeders.{$setName}: cycle detected in ref dependencies; cannot determine insertion order."
            );
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, array<string, mixed>> $schemasByName
     * @param array<string, int> $labelIndex
     * @param array<int, array{set:string,resource:string,data:array<string,mixed>,ref:?string}> $flat
     * @param array<string, string> $natKeyCache
     * @return array<string, array<string, mixed>>
     */
    private function transformRow(
        string $resourceName,
        array $data,
        array $schemasByName,
        array $labelIndex,
        array $flat,
        array &$natKeyCache,
        string $path
    ): array {
        $transformed = [];
        foreach ($data as $col => $value) {
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
     * @param array<int, array{set:string,resource:string,data:array<string,mixed>,ref:?string}> $flat
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

        // Directive — object with a "$" string discriminator.
        if (isset($value['$']) && is_string($value['$'])) {
            return $this->resolveDirective($value, $schemasByName, $labelIndex, $flat, $natKeyCache, $path);
        }

        // Plain array (list or assoc) passes through unchanged.
        return $value;
    }

    /**
     * @param array<string, mixed> $directive
     * @param array<string, array<string, mixed>> $schemasByName
     * @param array<string, int> $labelIndex
     * @param array<int, array{set:string,resource:string,data:array<string,mixed>,ref:?string}> $flat
     * @param array<string, string> $natKeyCache
     * @return array<string, mixed>
     */
    private function resolveDirective(
        array $directive,
        array $schemasByName,
        array $labelIndex,
        array $flat,
        array &$natKeyCache,
        string $path
    ): array {
        $verb = $directive['$'];

        switch ($verb) {
            case 'now':
                return ['Illuminate\\Support\\Carbon' => ['now' => null]];

            case 'uuid':
                return ['Illuminate\\Support\\Str' => ['uuid' => null]];

            case 'hash':
                $value = $directive['value'] ?? null;
                if (! is_string($value)) {
                    throw new InvalidArgumentException(
                        "{$path}: hash directive requires a string 'value'."
                    );
                }
                return ['Illuminate\\Support\\Facades\\Hash' => ['make' => $value]];

            case 'ref':
                $resource = $directive['resource'] ?? null;
                $ref = $directive['ref'] ?? null;
                if (! is_string($resource) || $resource === '') {
                    throw new InvalidArgumentException(
                        "{$path}: ref directive requires a non-empty 'resource' string."
                    );
                }
                if (! is_string($ref) || $ref === '') {
                    throw new InvalidArgumentException(
                        "{$path}: ref directive requires a non-empty 'ref' string."
                    );
                }
                return $this->resolveRef($resource, $ref, $schemasByName, $labelIndex, $flat, $natKeyCache, $path);

            case 'nested':
                return $this->resolveNested($directive, $schemasByName, $labelIndex, $flat, $natKeyCache, $path);

            default:
                throw new InvalidArgumentException("{$path}: unknown directive verb '{$verb}'.");
        }
    }

    /**
     * Translate a `nested` directive into a chained invocation:
     *   `Model::create([…child data…])->getKey()`
     *
     * Eloquent's `create()` inserts the row and returns the model; `getKey()`
     * pulls the model's PK (works for auto-increment and UUID alike). The
     * whole expression goes inline as the parent column's value, so the
     * nested child is inserted right before the parent INSERT runs, and its
     * PK lands in the parent column without any variable bookkeeping.
     *
     * The child's `data` is processed by the same `transformValue` recursion
     * as a top-level row, so refs / hash / now / uuid / nested all work
     * inside it. Nested children carry NO `ref` label — they're not
     * addressable from elsewhere.
     *
     * @param array<string, mixed> $directive
     * @param array<string, array<string, mixed>> $schemasByName
     * @param array<string, int> $labelIndex
     * @param array<int, array{set:string,resource:string,data:array<string,mixed>,ref:?string}> $flat
     * @param array<string, string> $natKeyCache
     * @return array<string, array<string, mixed>>
     */
    private function resolveNested(
        array $directive,
        array $schemasByName,
        array $labelIndex,
        array $flat,
        array &$natKeyCache,
        string $path
    ): array {
        $resource = $directive['resource'] ?? null;
        $childData = $directive['data'] ?? null;

        if (! is_string($resource) || $resource === '') {
            throw new InvalidArgumentException(
                "{$path}: nested directive requires a non-empty 'resource' string."
            );
        }
        if (! is_array($childData)) {
            throw new InvalidArgumentException(
                "{$path}: nested directive requires a 'data' object of column → value."
            );
        }

        // The brief is explicit: nested children are not referenceable. Any
        // `ref` field on a nested directive is malformed input.
        if (array_key_exists('ref', $directive)) {
            throw new InvalidArgumentException(
                "{$path}: nested directive cannot carry a 'ref' field — nested children are not addressable."
            );
        }

        $allowedKeys = ['$', 'resource', 'data'];
        foreach (array_keys($directive) as $key) {
            if (! in_array($key, $allowedKeys, true)) {
                throw new InvalidArgumentException(
                    "{$path}: nested directive has unexpected key '{$key}' (allowed: \$, resource, data)."
                );
            }
        }

        // Recursively transform the child's data — same rules as a row's
        // top-level data, so further nested / ref / hash / now / uuid all
        // resolve correctly.
        $transformed = [];
        foreach ($childData as $col => $value) {
            $transformed[$col] = $this->transformValue(
                $value,
                $schemasByName,
                $labelIndex,
                $flat,
                $natKeyCache,
                "{$path}.<nested:{$resource}>.{$col}"
            );
        }

        return [$this->modelClass($resource) => [
            'create' => $transformed,
            'getKey' => null,
        ]];
    }

    /**
     * @param array<string, array<string, mixed>> $schemasByName
     * @param array<string, int> $labelIndex
     * @param array<int, array{set:string,resource:string,data:array<string,mixed>,ref:?string}> $flat
     * @param array<string, string> $natKeyCache
     * @return array<string, array<string, mixed>>
     */
    private function resolveRef(
        string $resourceName,
        string $ref,
        array $schemasByName,
        array $labelIndex,
        array $flat,
        array &$natKeyCache,
        string $path
    ): array {
        $key = "{$resourceName}.{$ref}";
        if (! isset($labelIndex[$key])) {
            throw new InvalidArgumentException("{$path}: ref to '{$key}' has no matching row.");
        }

        $naturalKey = $this->getNaturalKey($resourceName, $schemasByName, $natKeyCache, $path);

        $referencedData = $flat[$labelIndex[$key]]['data'];
        if (! array_key_exists($naturalKey, $referencedData)) {
            throw new InvalidArgumentException(
                "{$path}: ref to '{$key}' resolves to a row in '{$resourceName}' but the row has no '{$naturalKey}' column (the resource's natural key)."
            );
        }
        $naturalValue = $referencedData[$naturalKey];

        if (is_array($naturalValue)) {
            throw new InvalidArgumentException(
                "{$path}: ref natural-key column '{$naturalKey}' for resource '{$resourceName}' is a directive, not a literal scalar. Pre-resolve it in the source data."
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
                "{$path}: cannot resolve ref to unknown resource '{$resourceName}'. "
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
                "{$path}: resource '{$resourceName}' has no unique non-PK column to serve as a natural key for ref resolution. "
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
