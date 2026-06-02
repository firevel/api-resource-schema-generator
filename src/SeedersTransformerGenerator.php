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
    /**
     * Bounds the `firevel/model-random-id` trait uses for its `creating`
     * hook. Pre-assigned IDs land in the same range so the generated
     * literal in the seeder file is indistinguishable from one the trait
     * would produce at runtime.
     */
    private const RANDOM_ID_MIN = 3656158440062976;
    private const RANDOM_ID_MAX = 9007199254740991;

    /**
     * Per-resource auto-increment counters, shared between the top-level
     * preAssignIds() pass and hoisted nested children so a nested insert never
     * collides with a top-level id for the same resource. Reset per handle().
     *
     * @var array<string, int>
     */
    private array $idSequences = [];

    /**
     * Set of pivot table names derived from `belongsToMany` relationships across
     * all schemas (same convention as the pivot migrations). Used to validate
     * that an `$attach` directive targets a real many-to-many relationship.
     * Keyed by snake_case table name → true. Reset per handle().
     *
     * @var array<string, bool>
     */
    private array $pivotTables = [];

    /**
     * Guards against duplicate pivot rows when a belongsToMany is attached from
     * both ends (post→tags AND tag→posts produce the same `post_tag` row).
     * Keyed by "table|<sorted col=>val json>" → true. Reset per handle().
     *
     * @var array<string, bool>
     */
    private array $emittedPivotRows = [];

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

        $this->idSequences = [];
        $this->emittedPivotRows = [];
        $this->pivotTables = $this->collectPivotTables($schemasByName);

        // Build global flat list + label index across all sets so a ref
        // from one set can resolve to a row in another (demo → system).
        [$flat, $labelIndex] = $this->buildIndex($seeders);

        // Pre-assign literal IDs to every row whose `data.id` isn't already
        // set, so the emitted seeder uses stable, deterministic IDs that
        // re-runs of the same schema reproduce.
        $flat = $this->preAssignIds($flat, $schemasByName);

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
        // belongsToMany pivot links are collected here and appended AFTER every
        // model row in the set, so each referenced row already exists regardless
        // of the order it was declared in.
        $pivots = [];
        foreach ($sortedLocal as $localPosIdx) {
            $globalIdx = $setIndices[$localPosIdx];
            $item = $flat[$globalIdx];
            $rowEntries = $this->transformRow(
                $item['resource'],
                $item['data'],
                $schemasByName,
                $labelIndex,
                $flat,
                $natKeyCache,
                $pivots,
                "{$setName}.{$item['resource']}[{$globalIdx}]"
            );
            foreach ($rowEntries as $entry) {
                $entries[] = $entry;
            }
        }

        foreach ($pivots as $pivot) {
            $entries[] = $pivot;
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
            if ($value['$'] === 'attach') {
                // attach produces pivot rows emitted AFTER all model rows in the
                // set (see transformSet), so by then every referenced row already
                // exists — attach imposes no intra-set ordering constraint, and
                // declaring it from both ends therefore can't create a cycle.
                return;
            }
            if ($value['$'] === 'morph') {
                // morph sets a polymorphic FK on THIS row, so the referenced
                // parent must be inserted first (same as `ref`).
                $resource = $value['resource'] ?? null;
                $ref = $value['ref'] ?? null;
                if (is_string($resource) && is_string($ref)) {
                    $depGlobalIdx = $labelIndex["{$resource}.{$ref}"] ?? null;
                    if ($depGlobalIdx !== null && isset($localPos[$depGlobalIdx])) {
                        $out[] = $localPos[$depGlobalIdx];
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
     * @param array<int, array{table:string, insert:array<string, mixed>}> $pivots sink for belongsToMany pivot links (emitted after all set rows)
     * @return array<int, array<string, array<string, mixed>>> hoisted nested children (in insert order) followed by this row
     */
    private function transformRow(
        string $resourceName,
        array $data,
        array $schemasByName,
        array $labelIndex,
        array $flat,
        array &$natKeyCache,
        array &$pivots,
        string $path
    ): array {
        // Nested children resolve to their own insert() entries, collected here
        // and emitted before this row so their pre-assigned ids exist when this
        // row's FK columns reference them.
        $hoisted = [];

        // `$attach` directives are belongsToMany associations, not columns —
        // peel them off so they don't land in the model row; resolve them into
        // pivot inserts that transformSet appends after every model row.
        $attachments = [];

        $transformed = [];
        foreach ($data as $col => $value) {
            if ($this->isAttachDirective($value)) {
                $attachments[$col] = $value;
                continue;
            }
            if ($this->isMorphDirective($value)) {
                // A morphTo association: the key is the morphName, expanded into
                // the two real polymorphic columns `<morphName>_id` / `_type`.
                [$morphId, $morphType] = $this->resolveMorph(
                    $value,
                    $schemasByName,
                    $labelIndex,
                    $flat,
                    $natKeyCache,
                    "{$path}.{$col}"
                );
                $transformed[$col . '_id'] = $morphId;
                $transformed[$col . '_type'] = $morphType;
                continue;
            }
            $transformed[$col] = $this->transformValue(
                $value,
                $schemasByName,
                $labelIndex,
                $flat,
                $natKeyCache,
                $hoisted,
                "{$path}.{$col}"
            );
        }

        $transformed = $this->backfillDefaults($resourceName, $transformed, $schemasByName);

        foreach ($attachments as $relationship => $directive) {
            $ownerId = $transformed['id'] ?? null;
            if (! is_int($ownerId) && ! is_string($ownerId)) {
                throw new InvalidArgumentException(
                    "{$path}.{$relationship}: cannot attach — owner '{$resourceName}' has no literal id "
                    . '(a runtime-generated PK cannot be linked in a pivot insert).'
                );
            }
            foreach ($this->resolveAttach(
                $resourceName,
                $ownerId,
                $directive,
                $schemasByName,
                $labelIndex,
                $flat,
                $natKeyCache,
                "{$path}.{$relationship}"
            ) as $pivotEntry) {
                $pivots[] = $pivotEntry;
            }
        }

        $hoisted[] = [$this->modelClass($resourceName) => $transformed];
        return $hoisted;
    }

    /**
     * Backfill schema defaults for columns ABSENT from the row, mirroring the
     * model's $attributes defaults (see SchemaHandler::addDefaults). The
     * generated DataSeeder uses Model::insert(), which bypasses Eloquent
     * defaults — so a required column omitted from the seed data (trusting a
     * model/DB default) would otherwise hit a NOT NULL violation at seed time.
     *
     * Only fills when the column is genuinely absent: an explicit null is a
     * deliberate choice and is left untouched. Only fields declaring a non-null
     * `default` are considered. When schemas are absent (seeders-only run with
     * no `$ref`s) there is nothing to backfill and the row passes through.
     *
     * @param array<string, mixed> $transformed
     * @param array<string, array<string, mixed>> $schemasByName
     * @return array<string, mixed>
     */
    private function backfillDefaults(string $resourceName, array $transformed, array $schemasByName): array
    {
        $fields = $schemasByName[$resourceName]['fields'] ?? [];
        if (! is_array($fields)) {
            return $transformed;
        }

        foreach ($fields as $field) {
            if (! is_array($field) || empty($field['name'])) {
                continue;
            }
            $name = $field['name'];
            // Present already (including an explicit null) — respect it.
            if (array_key_exists($name, $transformed)) {
                continue;
            }
            // Only backfill a declared, non-null default.
            if (! array_key_exists('default', $field) || $field['default'] === null) {
                continue;
            }
            $transformed[$name] = $field['default'];
        }

        return $transformed;
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
        array &$hoisted,
        string $path
    ): mixed {
        if (! is_array($value)) {
            return $value;
        }

        // Directive — object with a "$" string discriminator.
        if (isset($value['$']) && is_string($value['$'])) {
            return $this->resolveDirective($value, $schemasByName, $labelIndex, $flat, $natKeyCache, $hoisted, $path);
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
     * @return mixed array descriptor for most verbs; `ref` may return a scalar id when preAssignIds injected one.
     */
    private function resolveDirective(
        array $directive,
        array $schemasByName,
        array $labelIndex,
        array $flat,
        array &$natKeyCache,
        array &$hoisted,
        string $path
    ): mixed {
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
                return $this->resolveNested($directive, $schemasByName, $labelIndex, $flat, $natKeyCache, $hoisted, $path);

            case 'attach':
                // attach is a belongsToMany association handled at the row level
                // (transformRow peels it off before transformValue runs). Reaching
                // here means it was nested inside a column value, which is invalid.
                throw new InvalidArgumentException(
                    "{$path}: attach directive is only valid as a direct relationship key on a row's data, "
                    . 'not nested inside a column value.'
                );

            case 'morph':
                // morph is a morphTo association handled at the row level
                // (transformRow expands it into two columns before transformValue
                // runs). Reaching here means it was nested inside a column value.
                throw new InvalidArgumentException(
                    "{$path}: morph directive is only valid as a direct relationship key on a row's data, "
                    . 'not nested inside a column value.'
                );

            default:
                throw new InvalidArgumentException("{$path}: unknown directive verb '{$verb}'.");
        }
    }

    /**
     * Resolve a `nested` directive into a hoisted, event-free insert.
     *
     * The child is pre-assigned a literal id and pushed onto `$hoisted` as its
     * own `Model::insert([…])` entry (emitted before the parent row by
     * transformRow), and the directive resolves to that literal id — which the
     * parent's FK column receives. No `Model::create()` / `getKey()` is emitted,
     * so model events / observers never fire during seeding, matching the
     * top-level insert() path.
     *
     * The child's `data` is processed by the same `transformValue` recursion as
     * a top-level row, so refs / hash / now / uuid / further nesting all work
     * inside it (grandchildren hoist ahead of the child). Nested children carry
     * NO `ref` label — they're not addressable from elsewhere.
     *
     * The child's PK must be resolvable to a literal at transform time: an
     * `increments` or `random-id` PK is pre-assigned, and an explicit literal
     * `id` in the data is honoured. A runtime-generated PK (uuid, or an `id`
     * given as a directive) cannot be hoisted — declare such a child as its own
     * row and reference it with a `ref` instead.
     *
     * @param array<string, mixed> $directive
     * @param array<string, array<string, mixed>> $schemasByName
     * @param array<string, int> $labelIndex
     * @param array<int, array{set:string,resource:string,data:array<string,mixed>,ref:?string}> $flat
     * @param array<string, string> $natKeyCache
     * @param array<int, array<string, array<string, mixed>>> $hoisted child insert entries, appended in insert order
     * @return int|string the child's literal id, for the parent FK column
     */
    private function resolveNested(
        array $directive,
        array $schemasByName,
        array $labelIndex,
        array $flat,
        array &$natKeyCache,
        array &$hoisted,
        string $path
    ): int|string {
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
        // resolve correctly. Grandchildren land in $hoisted ahead of this child.
        $transformed = [];
        foreach ($childData as $col => $value) {
            $transformed[$col] = $this->transformValue(
                $value,
                $schemasByName,
                $labelIndex,
                $flat,
                $natKeyCache,
                $hoisted,
                "{$path}.<nested:{$resource}>.{$col}"
            );
        }

        // Defaults must be explicit: insert() bypasses Eloquent $attributes.
        $transformed = $this->backfillDefaults($resource, $transformed, $schemasByName);

        $id = $this->assignNestedId($resource, $transformed, $schemasByName, $path);
        // Place `id` first so the rendered INSERT reads naturally.
        if (! array_key_exists('id', $transformed)) {
            $transformed = ['id' => $id] + $transformed;
        }

        $hoisted[] = [$this->modelClass($resource) => $transformed];

        return $id;
    }

    /**
     * Determine the literal id for a hoisted nested child. Honours an explicit
     * literal `id` in the data; otherwise pre-assigns one from the PK type
     * (`increments` → shared sequence, `random-id` → deterministic). Throws when
     * the id can only be known at runtime (uuid PK, or an `id` directive), since
     * a hoisted insert needs a literal to plug into the parent FK.
     *
     * @param array<string, mixed> $transformed
     * @param array<string, array<string, mixed>> $schemasByName
     * @return int|string
     */
    private function assignNestedId(string $resource, array $transformed, array $schemasByName, string $path): int|string
    {
        if (array_key_exists('id', $transformed)) {
            $id = $transformed['id'];
            if (! is_int($id) && ! is_string($id)) {
                throw new InvalidArgumentException(
                    "{$path}: nested '{$resource}' child has a non-literal 'id' that can't be hoisted into the parent FK. "
                    . 'Provide a literal id, or declare the child as its own row and use a ref.'
                );
            }
            return $id;
        }

        $pkType = $this->detectPkType($resource, $schemasByName);
        if ($pkType === 'increments') {
            return $this->nextSequenceId($resource);
        }
        if ($pkType === 'random-id') {
            return $this->deterministicRandomId('nested|' . $path);
        }

        throw new InvalidArgumentException(
            "{$path}: nested '{$resource}' child has PK type '" . ($pkType ?? 'undeclared')
            . "' which can't be pre-assigned a literal id for a hoisted insert. "
            . 'Declare it as its own row and reference it with a ref instead.'
        );
    }

    private function isAttachDirective(mixed $value): bool
    {
        return is_array($value) && ($value['$'] ?? null) === 'attach';
    }

    private function isMorphDirective(mixed $value): bool
    {
        return is_array($value) && ($value['$'] ?? null) === 'morph';
    }

    /**
     * Resolve a `$morph` morphTo association into the two polymorphic column
     * values: the referenced row's id and its morph-map alias `_type`. The alias
     * matches MorphMapGenerator's convention (`Str::kebab(Str::singular(name))`),
     * so the seeded `_type` lines up with the registered morph map.
     *
     * @param array<string, mixed> $directive
     * @param array<string, array<string, mixed>> $schemasByName
     * @param array<string, int> $labelIndex
     * @param array<int, array{set:string,resource:string,data:array<string,mixed>,ref:?string}> $flat
     * @param array<string, string> $natKeyCache
     * @return array{0: int|string|array<string, mixed>, 1: string} [id, type]
     */
    private function resolveMorph(
        array $directive,
        array $schemasByName,
        array $labelIndex,
        array $flat,
        array &$natKeyCache,
        string $path
    ): array {
        $resource = $directive['resource'] ?? null;
        $ref = $directive['ref'] ?? null;
        if (! is_string($resource) || $resource === '' || ! is_string($ref) || $ref === '') {
            throw new InvalidArgumentException(
                "{$path}: morph directive requires non-empty 'resource' and 'ref' strings."
            );
        }

        $id = $this->resolveRef($resource, $ref, $schemasByName, $labelIndex, $flat, $natKeyCache, $path);
        $type = Str::kebab(Str::singular($resource));

        return [$id, $type];
    }

    /**
     * Resolve an `$attach` belongsToMany directive into raw pivot-table inserts.
     *
     * The pivot table and its two FK columns are derived from the owner and each
     * referenced resource using the same convention as the pivot migration
     * (sorted singular snake names, `<singular>_id` columns). The owner's literal
     * id and each referenced row's id (via resolveRef) populate the two columns.
     * Bi-directional declarations are de-duplicated, so attaching from both ends
     * is harmless.
     *
     * @param array<string, mixed> $directive
     * @param array<string, array<string, mixed>> $schemasByName
     * @param array<string, int> $labelIndex
     * @param array<int, array{set:string,resource:string,data:array<string,mixed>,ref:?string}> $flat
     * @param array<string, string> $natKeyCache
     * @return array<int, array{table:string, insert:array<string, mixed>}>
     */
    private function resolveAttach(
        string $ownerResource,
        int|string $ownerId,
        array $directive,
        array $schemasByName,
        array $labelIndex,
        array $flat,
        array &$natKeyCache,
        string $path
    ): array {
        $refs = $directive['refs'] ?? null;
        if (! is_array($refs) || $refs === []) {
            throw new InvalidArgumentException("{$path}: attach directive requires a non-empty 'refs' array.");
        }

        $ownerSingular = Str::singular(Str::snake($ownerResource));

        $entries = [];
        foreach ($refs as $i => $refEntry) {
            if (! is_array($refEntry)
                || ! isset($refEntry['resource'], $refEntry['ref'])
                || ! is_string($refEntry['resource'])
                || ! is_string($refEntry['ref'])
            ) {
                throw new InvalidArgumentException(
                    "{$path}.refs[{$i}]: each attach ref requires string 'resource' and 'ref'."
                );
            }

            $relatedResource = $refEntry['resource'];
            $relatedSingular = Str::singular(Str::snake($relatedResource));

            if ($ownerSingular === $relatedSingular) {
                throw new InvalidArgumentException(
                    "{$path}.refs[{$i}]: self-referential belongsToMany ('{$ownerResource}' to '{$relatedResource}') "
                    . 'is not supported by the derived pivot convention.'
                );
            }

            $pivotParts = [$ownerSingular, $relatedSingular];
            sort($pivotParts);
            $pivotTable = implode('_', $pivotParts);

            if (! isset($this->pivotTables[$pivotTable])) {
                throw new InvalidArgumentException(
                    "{$path}.refs[{$i}]: no belongsToMany pivot '{$pivotTable}' between '{$ownerResource}' and "
                    . "'{$relatedResource}'. Declare the belongsToMany relationship in the schema first."
                );
            }

            $relatedId = $this->resolveRef(
                $relatedResource,
                $refEntry['ref'],
                $schemasByName,
                $labelIndex,
                $flat,
                $natKeyCache,
                "{$path}.refs[{$i}]"
            );

            // Column order mirrors the pivot migration (sorted singular names).
            $insert = [];
            foreach ($pivotParts as $part) {
                $insert[$part . '_id'] = $part === $ownerSingular ? $ownerId : $relatedId;
            }

            // Dedupe bi-directional declarations: post->tags and tag->posts emit
            // the same pivot row.
            $dedupeKey = $pivotTable . '|' . json_encode($insert);
            if (isset($this->emittedPivotRows[$dedupeKey])) {
                continue;
            }
            $this->emittedPivotRows[$dedupeKey] = true;

            $entries[] = ['table' => $pivotTable, 'insert' => $insert];
        }

        return $entries;
    }

    /**
     * Build the set of pivot table names from every `belongsToMany` relationship
     * across all schemas, using the same derivation as the pivot migration
     * (sorted singular snake names of the two sides). Self-referential pairs are
     * skipped (unsupported by the convention).
     *
     * @param array<string, array<string, mixed>> $schemasByName
     * @return array<string, bool>
     */
    private function collectPivotTables(array $schemasByName): array
    {
        $tables = [];
        foreach ($schemasByName as $schema) {
            if (! is_array($schema)) {
                continue;
            }
            $ownerName = $schema['name'] ?? null;
            $rels = $schema['relationships'] ?? [];
            if (! is_string($ownerName) || ! is_array($rels)) {
                continue;
            }
            foreach ($rels as $rel) {
                if (! is_array($rel) || ($rel['type'] ?? null) !== 'belongsToMany') {
                    continue;
                }
                $rawTarget = ! empty($rel['target']) ? $rel['target'] : ($rel['name'] ?? '');
                if (! is_string($rawTarget) || $rawTarget === '') {
                    continue;
                }
                $ownerSingular = Str::singular(Str::snake($ownerName));
                $targetSingular = Str::singular(Str::snake(str_replace('-', '_', $rawTarget)));
                if ($ownerSingular === $targetSingular) {
                    continue;
                }
                $parts = [$ownerSingular, $targetSingular];
                sort($parts);
                $tables[implode('_', $parts)] = true;
            }
        }
        return $tables;
    }

    /**
     * @param array<string, array<string, mixed>> $schemasByName
     * @param array<string, int> $labelIndex
     * @param array<int, array{set:string,resource:string,data:array<string,mixed>,ref:?string}> $flat
     * @param array<string, string> $natKeyCache
     * @return mixed scalar id (when preAssignIds injected one) or a `Model::where(...)->value('id')` descriptor
     */
    private function resolveRef(
        string $resourceName,
        string $ref,
        array $schemasByName,
        array $labelIndex,
        array $flat,
        array &$natKeyCache,
        string $path
    ): mixed {
        $key = "{$resourceName}.{$ref}";
        if (! isset($labelIndex[$key])) {
            throw new InvalidArgumentException("{$path}: ref to '{$key}' has no matching row.");
        }

        $referencedData = $flat[$labelIndex[$key]]['data'];

        // Prefer the literal id assigned by preAssignIds(): emit as a scalar so
        // the runtime template plugs it straight into the FK column. Only valid
        // when the id is a literal — directives stay as directives.
        if (array_key_exists('id', $referencedData) && ! is_array($referencedData['id'])) {
            return $referencedData['id'];
        }

        // Fallback: deferred `Model::where(natKey, val)->value('id')` lookup.
        // Reached when preAssignIds skipped the row (uuid / unknown PK).
        $naturalKey = $this->getNaturalKey($resourceName, $schemasByName, $natKeyCache, $path);

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

    /**
     * Inject a literal `id` into every row whose `data` doesn't already
     * carry one. Strategy depends on the resource's PK type:
     *
     *   - `random-id`  → deterministic 64-bit int in the trait's range,
     *                    derived from (set, resource, ref-or-position) so
     *                    re-runs of the same schema produce identical IDs.
     *   - `increments` → per-resource sequence (1..N) shared across all sets
     *                    so multiple sets seeding the same resource don't
     *                    collide on PK.
     *   - other         → leave the row alone; we don't know the PK shape.
     *
     * Rows that already declare `data.id` are passed through unchanged —
     * the LLM gets the final word.
     *
     * @param array<int, array{set:string,resource:string,data:array<string,mixed>,ref:?string}> $flat
     * @param array<string, array<string, mixed>> $schemasByName
     * @return array<int, array{set:string,resource:string,data:array<string,mixed>,ref:?string}>
     */
    private function preAssignIds(array $flat, array $schemasByName): array
    {
        foreach ($flat as $globalIdx => &$item) {
            if (array_key_exists('id', $item['data'])) {
                continue;
            }

            $pkType = $this->detectPkType($item['resource'], $schemasByName);

            if ($pkType === 'random-id') {
                $seedKey = $item['set'] . '|' . $item['resource'] . '|' . ($item['ref'] ?? $globalIdx);
                $id = $this->deterministicRandomId($seedKey);
            } elseif ($pkType === 'increments') {
                $id = $this->nextSequenceId($item['resource']);
            } else {
                // PK type is uuid / unknown / undeclared: don't presume to
                // assign anything. The row's other directives ($uuid etc.)
                // can still handle the id column explicitly.
                continue;
            }

            // Place `id` first so the rendered INSERT reads naturally.
            $item['data'] = ['id' => $id] + $item['data'];
        }
        unset($item);

        return $flat;
    }

    /**
     * Read the PK column's `type` from the resource's schema. Assumes the
     * Laravel convention that the PK is named `id`.
     *
     * @param array<string, array<string, mixed>> $schemasByName
     */
    private function detectPkType(string $resourceName, array $schemasByName): ?string
    {
        if (! isset($schemasByName[$resourceName])) {
            return null;
        }
        $fields = $schemasByName[$resourceName]['fields'] ?? [];
        if (! is_array($fields)) {
            return null;
        }
        foreach ($fields as $field) {
            if (is_array($field) && ($field['name'] ?? null) === 'id') {
                return $field['type'] ?? null;
            }
        }
        return null;
    }

    /**
     * Next auto-increment id for a resource, from the shared per-resource
     * counter. Used by both the top-level pass and hoisted nested children so
     * their ids never overlap.
     */
    private function nextSequenceId(string $resourceName): int
    {
        $this->idSequences[$resourceName] = ($this->idSequences[$resourceName] ?? 0) + 1;
        return $this->idSequences[$resourceName];
    }

    /**
     * Deterministic 64-bit int in [RANDOM_ID_MIN, RANDOM_ID_MAX]. Uses
     * SHA-256 of the seed key, truncated to 56 bits so the parsed value
     * is always a positive PHP int even on 32-bit builds.
     */
    private function deterministicRandomId(string $seedKey): int
    {
        $hash = hash('sha256', $seedKey);
        $value = hexdec(substr($hash, 0, 14)); // 14 hex chars = 56 bits
        $range = self::RANDOM_ID_MAX - self::RANDOM_ID_MIN + 1;
        return self::RANDOM_ID_MIN + ((int) $value % $range);
    }
}
