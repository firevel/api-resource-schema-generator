<?php

namespace Firevel\ApiResourceSchemaGenerator\Tests;

use Firevel\ApiResourceSchemaGenerator\SeedersTransformerGenerator;

class SeedersTransformerTest extends TestCase
{
    /**
     * Convenience: run the transformer and return the resulting
     * `transformed_seeders` from the shared PipelineContext.
     *
     * @return array<string, array<int, mixed>>
     */
    private function transform(array $input): array
    {
        $generator = $this->runGenerator(SeedersTransformerGenerator::class, $input);
        $out = $generator->context()->get('transformed_seeders');
        return is_array($out) ? $out : [];
    }

    private function schema(string $name, array $fields): array
    {
        return ['name' => $name, 'fields' => $fields];
    }

    private function uniqueField(string $name): array
    {
        return ['name' => $name, 'type' => 'string', 'index' => 'unique'];
    }

    private function pkField(): array
    {
        return ['name' => 'id', 'type' => 'increments', 'index' => 'primary'];
    }

    /**
     * UUID primary key. preAssignIds() skips this PK type, so refs to such
     * a row exercise the natural-key fallback path in resolveRef().
     */
    private function uuidPkField(): array
    {
        return ['name' => 'id', 'type' => 'uuid', 'index' => 'primary'];
    }

    /** @test */
    public function it_passes_through_scalar_only_rows(): void
    {
        $out = $this->transform([
            'schemas' => [],
            'seeders' => [
                [
                    'name' => 'system',
                    'resources' => [
                        [
                            'name' => 'Role',
                            'rows' => [
                                ['ref' => 'admin', 'data' => ['name' => 'admin', 'description' => 'Full access']],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertSame([
            'system' => [
                ['App\\Models\\Role' => ['name' => 'admin', 'description' => 'Full access']],
            ],
        ], $out);
    }

    /** @test */
    public function it_translates_now_uuid_hash_directives(): void
    {
        $out = $this->transform([
            'schemas' => [],
            'seeders' => [
                [
                    'name' => 'demo',
                    'resources' => [
                        [
                            'name' => 'User',
                            'rows' => [[
                                'data' => [
                                    'id' => ['$' => 'uuid'],
                                    'password' => ['$' => 'hash', 'value' => 'secret'],
                                    'created_at' => ['$' => 'now'],
                                ],
                            ]],
                        ],
                    ],
                ],
            ],
        ]);

        $row = $out['demo'][0]['App\\Models\\User'];
        $this->assertSame(['Illuminate\\Support\\Str' => ['uuid' => null]], $row['id']);
        $this->assertSame(['Illuminate\\Support\\Facades\\Hash' => ['make' => 'secret']], $row['password']);
        $this->assertSame(['Illuminate\\Support\\Carbon' => ['now' => null]], $row['created_at']);
    }

    /** @test */
    public function it_resolves_ref_to_pre_assigned_id_for_increments_pk(): void
    {
        $out = $this->transform([
            'schemas' => [
                $this->schema('Role', [$this->pkField(), $this->uniqueField('name')]),
                $this->schema('User', [$this->pkField(), $this->uniqueField('email')]),
            ],
            'seeders' => [
                [
                    'name' => 'system',
                    'resources' => [
                        [
                            'name' => 'Role',
                            'rows' => [
                                ['ref' => 'admin', 'data' => ['name' => 'admin']],
                            ],
                        ],
                        [
                            'name' => 'User',
                            'rows' => [
                                ['data' => [
                                    'name' => 'John',
                                    'email' => 'john@x.com',
                                    'role_id' => ['$' => 'ref', 'resource' => 'Role', 'ref' => 'admin'],
                                ]],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        // Role row comes first via topo sort because User depends on it.
        // The transformer auto-injects `id: 1` (sequential, increments PK).
        $this->assertSame(
            ['App\\Models\\Role' => ['id' => 1, 'name' => 'admin']],
            $out['system'][0]
        );

        // Ref resolves to the pre-assigned scalar id, NOT a deferred
        // Model::where(...)->value('id') lookup.
        $this->assertSame(1, $out['system'][1]['App\\Models\\User']['role_id']);
    }

    /** @test */
    public function ref_falls_back_to_natural_key_lookup_when_pre_assignment_is_skipped(): void
    {
        // UUID PK → preAssignIds skips, so resolveRef must use the natural-key
        // descriptor path: Model::where(<unique>, <value>)->value('id').
        $out = $this->transform([
            'schemas' => [
                $this->schema('Role', [$this->uuidPkField(), $this->uniqueField('name')]),
                $this->schema('User', [$this->pkField(), $this->uniqueField('email')]),
            ],
            'seeders' => [
                [
                    'name' => 'system',
                    'resources' => [
                        ['name' => 'Role', 'rows' => [['ref' => 'admin', 'data' => ['name' => 'admin']]]],
                        ['name' => 'User', 'rows' => [['data' => [
                            'email' => 'john@x.com',
                            'role_id' => ['$' => 'ref', 'resource' => 'Role', 'ref' => 'admin'],
                        ]]]],
                    ],
                ],
            ],
        ]);

        $this->assertSame(
            ['App\\Models\\Role' => ['where' => ['name', 'admin'], 'value' => 'id']],
            $out['system'][1]['App\\Models\\User']['role_id']
        );
    }

    /** @test */
    public function topo_sort_reorders_dependent_rows_within_a_set(): void
    {
        // User resource appears BEFORE Role in input order, but $refs Role.
        // Transformer must reorder so Role gets inserted first.
        $out = $this->transform([
            'schemas' => [
                $this->schema('Role', [$this->pkField(), $this->uniqueField('name')]),
                $this->schema('User', [$this->pkField(), $this->uniqueField('email')]),
            ],
            'seeders' => [
                [
                    'name' => 'system',
                    'resources' => [
                        [
                            'name' => 'User',
                            'rows' => [
                                ['data' => [
                                    'name' => 'John',
                                    'email' => 'john@x.com',
                                    'role_id' => ['$' => 'ref', 'resource' => 'Role', 'ref' => 'admin'],
                                ]],
                            ],
                        ],
                        [
                            'name' => 'Role',
                            'rows' => [
                                ['ref' => 'admin', 'data' => ['name' => 'admin']],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertArrayHasKey('App\\Models\\Role', $out['system'][0]);
        $this->assertArrayHasKey('App\\Models\\User', $out['system'][1]);
    }

    /** @test */
    public function natural_key_picks_preferred_column_when_multiple_uniques_exist(): void
    {
        // UUID PK forces the natural-key path (preAssignIds skips uuid).
        $out = $this->transform([
            'schemas' => [
                $this->schema('Role', [
                    $this->uuidPkField(),
                    $this->uniqueField('email'),
                    $this->uniqueField('slug'),
                    $this->uniqueField('name'),
                    $this->uniqueField('code'),
                ]),
            ],
            'seeders' => [
                [
                    'name' => 'system',
                    'resources' => [
                        [
                            'name' => 'Role',
                            'rows' => [[
                                'ref' => 'admin',
                                'data' => [
                                    'code' => 'ADMIN',
                                    'slug' => 'admin',
                                    'name' => 'Administrator',
                                    'email' => 'admin@x.com',
                                ],
                            ]],
                        ],
                        [
                            'name' => 'OtherTable',
                            'rows' => [
                                ['data' => ['role_id' => ['$' => 'ref', 'resource' => 'Role', 'ref' => 'admin']]],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        // `code` wins over slug/name/email — preferred column for the natural key.
        $this->assertSame(
            ['App\\Models\\Role' => ['where' => ['code', 'ADMIN'], 'value' => 'id']],
            $out['system'][1]['App\\Models\\OtherTable']['role_id']
        );
    }

    /** @test */
    public function natural_key_falls_back_to_first_unique_when_no_preferred_match(): void
    {
        // UUID PK forces the natural-key path (preAssignIds skips uuid).
        $out = $this->transform([
            'schemas' => [
                $this->schema('Token', [
                    $this->uuidPkField(),
                    $this->uniqueField('token_hash'),
                ]),
            ],
            'seeders' => [
                [
                    'name' => 'system',
                    'resources' => [
                        [
                            'name' => 'Token',
                            'rows' => [['ref' => 'one', 'data' => ['token_hash' => 'abc123']]],
                        ],
                        [
                            'name' => 'Audit',
                            'rows' => [['data' => ['token_id' => ['$' => 'ref', 'resource' => 'Token', 'ref' => 'one']]]],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertSame(
            ['App\\Models\\Token' => ['where' => ['token_hash', 'abc123'], 'value' => 'id']],
            $out['system'][1]['App\\Models\\Audit']['token_id']
        );
    }

    /** @test */
    public function it_errors_when_resource_has_no_unique_non_pk_column(): void
    {
        // UUID PK forces the natural-key path; with no unique non-PK column,
        // resolveRef has no fallback and must throw.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/no unique non-PK column/');

        $this->transform([
            'schemas' => [
                $this->schema('Role', [
                    $this->uuidPkField(),
                    ['name' => 'name', 'type' => 'string'], // no `index: unique`
                ]),
            ],
            'seeders' => [
                [
                    'name' => 'system',
                    'resources' => [
                        [
                            'name' => 'Role',
                            'rows' => [['ref' => 'a', 'data' => ['name' => 'a']]],
                        ],
                        [
                            'name' => 'Other',
                            'rows' => [['data' => ['role_id' => ['$' => 'ref', 'resource' => 'Role', 'ref' => 'a']]]],
                        ],
                    ],
                ],
            ],
        ]);
    }

    /** @test */
    public function it_errors_on_dangling_ref(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/no matching row/');

        $this->transform([
            'schemas' => [$this->schema('Role', [$this->pkField(), $this->uniqueField('name')])],
            'seeders' => [
                [
                    'name' => 'system',
                    'resources' => [
                        [
                            'name' => 'Role',
                            'rows' => [['ref' => 'admin', 'data' => ['name' => 'admin']]],
                        ],
                        [
                            'name' => 'Other',
                            'rows' => [['data' => ['role_id' => ['$' => 'ref', 'resource' => 'Role', 'ref' => 'ghost']]]],
                        ],
                    ],
                ],
            ],
        ]);
    }

    /** @test */
    public function it_errors_on_cycle(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/cycle detected/');

        $this->transform([
            'schemas' => [
                $this->schema('A', [$this->pkField(), $this->uniqueField('name')]),
                $this->schema('B', [$this->pkField(), $this->uniqueField('name')]),
            ],
            'seeders' => [
                [
                    'name' => 'system',
                    'resources' => [
                        [
                            'name' => 'A',
                            'rows' => [['ref' => 'a1', 'data' => [
                                'name' => 'a',
                                'b_id' => ['$' => 'ref', 'resource' => 'B', 'ref' => 'b1'],
                            ]]],
                        ],
                        [
                            'name' => 'B',
                            'rows' => [['ref' => 'b1', 'data' => [
                                'name' => 'b',
                                'a_id' => ['$' => 'ref', 'resource' => 'A', 'ref' => 'a1'],
                            ]]],
                        ],
                    ],
                ],
            ],
        ]);
    }

    /** @test */
    public function it_errors_when_natural_key_value_is_itself_a_directive(): void
    {
        // UUID PK forces the natural-key path; the unique column holds a
        // directive instead of a literal, which resolveRef must reject.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/natural-key column .* directive, not a literal/');

        $this->transform([
            'schemas' => [
                $this->schema('Token', [
                    $this->uuidPkField(),
                    $this->uniqueField('hash'),
                ]),
            ],
            'seeders' => [
                [
                    'name' => 'system',
                    'resources' => [
                        [
                            'name' => 'Token',
                            'rows' => [['ref' => 'one', 'data' => ['hash' => ['$' => 'uuid']]]],
                        ],
                        [
                            'name' => 'Audit',
                            'rows' => [['data' => ['token_id' => ['$' => 'ref', 'resource' => 'Token', 'ref' => 'one']]]],
                        ],
                    ],
                ],
            ],
        ]);
    }

    /** @test */
    public function ref_lives_on_row_not_inside_data(): void
    {
        // The `ref` field is at the row level, not inside `data`. Confirm the
        // generated output's `data`-derived columns don't contain a `ref` key.
        $out = $this->transform([
            'schemas' => [],
            'seeders' => [
                [
                    'name' => 'system',
                    'resources' => [
                        [
                            'name' => 'Role',
                            'rows' => [['ref' => 'admin', 'data' => ['name' => 'admin']]],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertSame(['App\\Models\\Role' => ['name' => 'admin']], $out['system'][0]);
        $this->assertArrayNotHasKey('ref', $out['system'][0]['App\\Models\\Role']);
    }

    /** @test */
    public function it_emits_full_input_with_transformed_seeders_for_chaining(): void
    {
        $generator = $this->runGenerator(SeedersTransformerGenerator::class, [
            'service' => ['name' => 'app'],
            'schemas' => [],
            'seeders' => [
                [
                    'name' => 'system',
                    'resources' => [
                        [
                            'name' => 'Role',
                            'rows' => [['ref' => 'admin', 'data' => ['name' => 'admin']]],
                        ],
                    ],
                ],
            ],
        ]);

        $output = $generator->context()->get('output');

        $this->assertIsArray($output);
        $this->assertSame(['name' => 'app'], $output['service']);
        $this->assertArrayHasKey('seeders', $output);
        $this->assertSame(
            [['App\\Models\\Role' => ['name' => 'admin']]],
            $output['seeders']['system']
        );
    }

    /** @test */
    public function it_no_ops_when_no_seeders_block(): void
    {
        $generator = $this->runGenerator(SeedersTransformerGenerator::class, [
            'schemas' => [],
        ]);

        $this->assertNull($generator->context()->get('transformed_seeders'));
        $this->assertNull($generator->context()->get('output'));
    }

    /** @test */
    public function cross_set_refs_resolve_without_constraining_topo_order(): void
    {
        // `demo` row refs a `system` row. The demo row should NOT get re-ordered
        // (no intra-set dep); the FK lookup is emitted in the output.
        $out = $this->transform([
            'schemas' => [
                $this->schema('Role', [$this->pkField(), $this->uniqueField('name')]),
                $this->schema('User', [$this->pkField(), $this->uniqueField('email')]),
            ],
            'seeders' => [
                [
                    'name' => 'system',
                    'resources' => [
                        ['name' => 'Role', 'rows' => [['ref' => 'admin', 'data' => ['name' => 'admin']]]],
                    ],
                ],
                [
                    'name' => 'demo',
                    'resources' => [
                        [
                            'name' => 'User',
                            'rows' => [['data' => [
                                'email' => 'a@b.c',
                                'role_id' => ['$' => 'ref', 'resource' => 'Role', 'ref' => 'admin'],
                            ]]],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertCount(1, $out['system']);
        $this->assertCount(1, $out['demo']);

        // Role's increments PK gets `id: 1`; the demo-set ref resolves to that scalar.
        $this->assertSame(1, $out['demo'][0]['App\\Models\\User']['role_id']);
    }

    /** @test */
    public function duplicate_ref_across_rows_is_an_error(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/duplicate ref/');

        $this->transform([
            'schemas' => [],
            'seeders' => [
                [
                    'name' => 'system',
                    'resources' => [
                        ['name' => 'Role', 'rows' => [['ref' => 'dup', 'data' => ['name' => 'a']]]],
                    ],
                ],
                [
                    'name' => 'demo',
                    'resources' => [
                        ['name' => 'Role', 'rows' => [['ref' => 'dup', 'data' => ['name' => 'b']]]],
                    ],
                ],
            ],
        ]);
    }

    /** @test */
    public function unknown_directive_verb_is_an_error(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/unknown directive verb/');

        $this->transform([
            'schemas' => [],
            'seeders' => [
                [
                    'name' => 'system',
                    'resources' => [
                        [
                            'name' => 'Role',
                            'rows' => [['data' => ['name' => ['$' => 'frobnicate']]]],
                        ],
                    ],
                ],
            ],
        ]);
    }

    /** @test */
    public function worked_example_from_brief_round_trips(): void
    {
        $out = $this->transform([
            'schemas' => [
                $this->schema('Role', [$this->pkField(), $this->uniqueField('name')]),
                $this->schema('Permission', [$this->pkField(), $this->uniqueField('name')]),
                $this->schema('User', [$this->pkField(), $this->uniqueField('email')]),
            ],
            'seeders' => [
                [
                    'name' => 'system',
                    'resources' => [
                        [
                            'name' => 'Role',
                            'rows' => [
                                ['ref' => 'admin', 'data' => ['name' => 'admin', 'description' => 'Full access']],
                                ['ref' => 'member', 'data' => ['name' => 'member', 'description' => 'Standard user']],
                            ],
                        ],
                        [
                            'name' => 'Permission',
                            'rows' => [
                                ['ref' => 'posts_read', 'data' => ['name' => 'posts.read']],
                                ['ref' => 'posts_write', 'data' => ['name' => 'posts.write']],
                            ],
                        ],
                        [
                            'name' => 'RolePermission',
                            'rows' => [
                                ['data' => [
                                    'role_id' => ['$' => 'ref', 'resource' => 'Role', 'ref' => 'admin'],
                                    'permission_id' => ['$' => 'ref', 'resource' => 'Permission', 'ref' => 'posts_read'],
                                ]],
                                ['data' => [
                                    'role_id' => ['$' => 'ref', 'resource' => 'Role', 'ref' => 'admin'],
                                    'permission_id' => ['$' => 'ref', 'resource' => 'Permission', 'ref' => 'posts_write'],
                                ]],
                                ['data' => [
                                    'role_id' => ['$' => 'ref', 'resource' => 'Role', 'ref' => 'member'],
                                    'permission_id' => ['$' => 'ref', 'resource' => 'Permission', 'ref' => 'posts_read'],
                                ]],
                            ],
                        ],
                    ],
                ],
                [
                    'name' => 'demo',
                    'resources' => [
                        [
                            'name' => 'User',
                            'rows' => [[
                                'ref' => 'john',
                                'data' => [
                                    'id' => ['$' => 'uuid'],
                                    'name' => 'John Doe',
                                    'email' => 'john@example.com',
                                    'password' => ['$' => 'hash', 'value' => 'password123'],
                                    'role_id' => ['$' => 'ref', 'resource' => 'Role', 'ref' => 'admin'],
                                    'email_verified_at' => ['$' => 'now'],
                                    'created_at' => ['$' => 'now'],
                                    'updated_at' => ['$' => 'now'],
                                ],
                            ]],
                        ],
                    ],
                ],
            ],
        ]);

        // System set: 4 reference rows + 3 pivot rows, topo-sorted.
        $this->assertCount(7, $out['system']);

        // Sequential ids 1..N per resource auto-injected for `increments` PKs.
        $this->assertSame(['App\\Models\\Role' => ['id' => 1, 'name' => 'admin', 'description' => 'Full access']], $out['system'][0]);
        $this->assertSame(['App\\Models\\Role' => ['id' => 2, 'name' => 'member', 'description' => 'Standard user']], $out['system'][1]);
        $this->assertSame(['App\\Models\\Permission' => ['id' => 1, 'name' => 'posts.read']], $out['system'][2]);
        $this->assertSame(['App\\Models\\Permission' => ['id' => 2, 'name' => 'posts.write']], $out['system'][3]);

        // Refs to increments-PK rows resolve to the pre-assigned scalar id.
        $pivot = $out['system'][4]['App\\Models\\RolePermission'];
        $this->assertSame(1, $pivot['role_id']);          // Role.admin
        $this->assertSame(1, $pivot['permission_id']);    // Permission.posts.read

        $john = $out['demo'][0]['App\\Models\\User'];
        $this->assertSame(['Illuminate\\Support\\Str' => ['uuid' => null]], $john['id']);
        $this->assertSame('John Doe', $john['name']);
        $this->assertSame('john@example.com', $john['email']);
        $this->assertSame(['Illuminate\\Support\\Facades\\Hash' => ['make' => 'password123']], $john['password']);
        $this->assertSame(1, $john['role_id']);
        $this->assertSame(['Illuminate\\Support\\Carbon' => ['now' => null]], $john['email_verified_at']);
        $this->assertSame(['Illuminate\\Support\\Carbon' => ['now' => null]], $john['created_at']);
        $this->assertSame(['Illuminate\\Support\\Carbon' => ['now' => null]], $john['updated_at']);
    }

    /** @test */
    public function nested_directive_hoists_child_as_its_own_insert_with_pre_assigned_id(): void
    {
        $out = $this->transform([
            'schemas' => [
                $this->schema('Address', [$this->pkField()]),
                $this->schema('Store',   [$this->pkField()]),
            ],
            'seeders' => [
                [
                    'name' => 'demo',
                    'resources' => [
                        [
                            'name' => 'Store',
                            'rows' => [[
                                'data' => [
                                    'name' => 'Main Store',
                                    'address_id' => [
                                        '$' => 'nested',
                                        'resource' => 'Address',
                                        'data' => [
                                            'street' => '123 Main',
                                            'city' => 'Springfield',
                                        ],
                                    ],
                                ],
                            ]],
                        ],
                    ],
                ],
            ],
        ]);

        // Child Address is hoisted to its OWN insert before the parent Store,
        // with a pre-assigned id; the parent FK receives that literal id. No
        // create()/getKey() chain is emitted, so no model events fire at seed time.
        $this->assertSame([
            'demo' => [
                ['App\\Models\\Address' => ['id' => 1, 'street' => '123 Main', 'city' => 'Springfield']],
                ['App\\Models\\Store' => ['id' => 1, 'name' => 'Main Store', 'address_id' => 1]],
            ],
        ], $out);
    }

    /** @test */
    public function nested_directive_recurses_with_inner_directives(): void
    {
        // Nested child's `data` itself contains `ref`, `now`, and `hash`.
        $out = $this->transform([
            'schemas' => [
                $this->schema('Country', [$this->pkField(), $this->uniqueField('code')]),
                $this->schema('Address', [$this->pkField()]),
                $this->schema('Store',   [$this->pkField()]),
            ],
            'seeders' => [
                [
                    'name' => 'system',
                    'resources' => [
                        ['name' => 'Country', 'rows' => [['ref' => 'us', 'data' => ['code' => 'US']]]],
                    ],
                ],
                [
                    'name' => 'demo',
                    'resources' => [
                        [
                            'name' => 'Store',
                            'rows' => [[
                                'data' => [
                                    'name' => 'Main',
                                    'address_id' => [
                                        '$' => 'nested',
                                        'resource' => 'Address',
                                        'data' => [
                                            'street' => '123 Main',
                                            'country_id' => ['$' => 'ref', 'resource' => 'Country', 'ref' => 'us'],
                                            'created_at' => ['$' => 'now'],
                                        ],
                                    ],
                                ],
                            ]],
                        ],
                    ],
                ],
            ],
        ]);

        // Address is hoisted to its own insert (out['demo'][0]); the inner
        // ref / now directives still resolve inside it.
        $address = $out['demo'][0]['App\\Models\\Address'];
        $this->assertSame('123 Main', $address['street']);
        // Country has increments PK → ref resolves to pre-assigned scalar id (1).
        $this->assertSame(1, $address['country_id']);
        $this->assertSame(
            ['Illuminate\\Support\\Carbon' => ['now' => null]],
            $address['created_at']
        );
        // Parent Store follows the hoisted child, FK pointing at its id.
        $this->assertSame(1, $out['demo'][1]['App\\Models\\Store']['address_id']);
    }

    /** @test */
    public function nested_directives_can_recurse_into_nested_directives(): void
    {
        // Store → Address → Geolocation (depth 2).
        $out = $this->transform([
            'schemas' => [
                $this->schema('Geolocation', [$this->pkField()]),
                $this->schema('Address',     [$this->pkField()]),
                $this->schema('Store',       [$this->pkField()]),
            ],
            'seeders' => [
                [
                    'name' => 'demo',
                    'resources' => [
                        [
                            'name' => 'Store',
                            'rows' => [[
                                'data' => [
                                    'address_id' => [
                                        '$' => 'nested',
                                        'resource' => 'Address',
                                        'data' => [
                                            'street' => '123 Main',
                                            'geolocation_id' => [
                                                '$' => 'nested',
                                                'resource' => 'Geolocation',
                                                'data' => ['lat' => 40.7, 'lng' => -74.0],
                                            ],
                                        ],
                                    ],
                                ],
                            ]],
                        ],
                    ],
                ],
            ],
        ]);

        // Deepest child first: Geolocation, then Address, then Store.
        $this->assertSame('App\\Models\\Geolocation', array_key_first($out['demo'][0]));
        $this->assertSame('App\\Models\\Address', array_key_first($out['demo'][1]));
        $this->assertSame('App\\Models\\Store', array_key_first($out['demo'][2]));

        $this->assertSame(
            ['id' => 1, 'lat' => 40.7, 'lng' => -74.0],
            $out['demo'][0]['App\\Models\\Geolocation']
        );
        // Address FK references the hoisted Geolocation id; Store FK the Address id.
        $this->assertSame(1, $out['demo'][1]['App\\Models\\Address']['geolocation_id']);
        $this->assertSame(1, $out['demo'][2]['App\\Models\\Store']['address_id']);
    }

    /** @test */
    public function nested_directive_with_ref_field_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/nested directive cannot carry a .ref. field/');

        $this->transform([
            'schemas' => [$this->schema('Address', [$this->pkField()])],
            'seeders' => [
                [
                    'name' => 'demo',
                    'resources' => [
                        [
                            'name' => 'Store',
                            'rows' => [[
                                'data' => [
                                    'address_id' => [
                                        '$' => 'nested',
                                        'ref' => 'should_be_invalid',
                                        'resource' => 'Address',
                                        'data' => ['street' => 'x'],
                                    ],
                                ],
                            ]],
                        ],
                    ],
                ],
            ],
        ]);
    }

    /** @test */
    public function nested_directive_requires_resource_and_data(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->transform([
            'schemas' => [],
            'seeders' => [
                [
                    'name' => 'demo',
                    'resources' => [
                        [
                            'name' => 'Store',
                            'rows' => [[
                                'data' => [
                                    'address_id' => ['$' => 'nested', 'data' => ['street' => 'x']],
                                ],
                            ]],
                        ],
                    ],
                ],
            ],
        ]);
    }

    /** @test */
    public function nested_directive_rejects_extra_keys(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/unexpected key/');

        $this->transform([
            'schemas' => [$this->schema('Address', [$this->pkField()])],
            'seeders' => [
                [
                    'name' => 'demo',
                    'resources' => [
                        [
                            'name' => 'Store',
                            'rows' => [[
                                'data' => [
                                    'address_id' => [
                                        '$' => 'nested',
                                        'resource' => 'Address',
                                        'data' => ['street' => 'x'],
                                        'extra' => 'nope',
                                    ],
                                ],
                            ]],
                        ],
                    ],
                ],
            ],
        ]);
    }

    /** @test */
    public function pre_assigns_sequential_ids_for_increments_pk(): void
    {
        $out = $this->transform([
            'schemas' => [
                $this->schema('Role', [$this->pkField(), $this->uniqueField('name')]),
            ],
            'seeders' => [
                [
                    'name' => 'system',
                    'resources' => [
                        ['name' => 'Role', 'rows' => [
                            ['data' => ['name' => 'admin']],
                            ['data' => ['name' => 'member']],
                            ['data' => ['name' => 'viewer']],
                        ]],
                    ],
                ],
            ],
        ]);

        $this->assertSame(1, $out['system'][0]['App\\Models\\Role']['id']);
        $this->assertSame(2, $out['system'][1]['App\\Models\\Role']['id']);
        $this->assertSame(3, $out['system'][2]['App\\Models\\Role']['id']);
    }

    /** @test */
    public function increments_sequence_continues_across_sets_for_same_resource(): void
    {
        $out = $this->transform([
            'schemas' => [
                $this->schema('Role', [$this->pkField(), $this->uniqueField('name')]),
            ],
            'seeders' => [
                [
                    'name' => 'system',
                    'resources' => [['name' => 'Role', 'rows' => [['data' => ['name' => 'admin']]]]],
                ],
                [
                    'name' => 'demo',
                    'resources' => [['name' => 'Role', 'rows' => [['data' => ['name' => 'guest']]]]],
                ],
            ],
        ]);

        $this->assertSame(1, $out['system'][0]['App\\Models\\Role']['id']);
        $this->assertSame(2, $out['demo'][0]['App\\Models\\Role']['id']);
    }

    /** @test */
    public function pre_assigns_deterministic_64bit_id_for_random_id_pk(): void
    {
        $randomIdField = ['name' => 'id', 'type' => 'random-id', 'index' => 'primary'];

        $first = $this->transform([
            'schemas' => [$this->schema('Event', [$randomIdField, $this->uniqueField('slug')])],
            'seeders' => [
                ['name' => 'demo', 'resources' => [
                    ['name' => 'Event', 'rows' => [['ref' => 'launch', 'data' => ['slug' => 'launch-day']]]],
                ]],
            ],
        ]);
        $second = $this->transform([
            'schemas' => [$this->schema('Event', [$randomIdField, $this->uniqueField('slug')])],
            'seeders' => [
                ['name' => 'demo', 'resources' => [
                    ['name' => 'Event', 'rows' => [['ref' => 'launch', 'data' => ['slug' => 'launch-day']]]],
                ]],
            ],
        ]);

        $id = $first['demo'][0]['App\\Models\\Event']['id'];

        // Determinism: same input → same id.
        $this->assertSame($id, $second['demo'][0]['App\\Models\\Event']['id']);

        // Range: matches the firevel/model-random-id trait's bounds.
        $this->assertGreaterThanOrEqual(3656158440062976, $id);
        $this->assertLessThanOrEqual(9007199254740991, $id);
    }

    /** @test */
    public function existing_id_in_row_data_is_preserved(): void
    {
        $out = $this->transform([
            'schemas' => [
                $this->schema('Role', [$this->pkField(), $this->uniqueField('name')]),
            ],
            'seeders' => [
                [
                    'name' => 'system',
                    'resources' => [['name' => 'Role', 'rows' => [
                        ['data' => ['id' => 999, 'name' => 'admin']],
                    ]]],
                ],
            ],
        ]);

        $this->assertSame(999, $out['system'][0]['App\\Models\\Role']['id']);
    }

    /** @test */
    public function pre_assignment_skips_resources_with_no_schema_or_unknown_pk_type(): void
    {
        // No schema for "Role" → no PK type known → don't inject.
        $out = $this->transform([
            'schemas' => [],
            'seeders' => [
                ['name' => 'system', 'resources' => [
                    ['name' => 'Role', 'rows' => [['data' => ['name' => 'admin']]]],
                ]],
            ],
        ]);

        $this->assertArrayNotHasKey('id', $out['system'][0]['App\\Models\\Role']);
    }

    /** @test */
    public function nested_ref_inside_constrains_parent_topo_order(): void
    {
        // Parent's `nested` child refs another row in the SAME set. The
        // parent must be inserted AFTER that row so the ref resolves.
        $out = $this->transform([
            'schemas' => [
                $this->schema('Country', [$this->pkField(), $this->uniqueField('code')]),
                $this->schema('Address', [$this->pkField()]),
                $this->schema('Store',   [$this->pkField()]),
            ],
            'seeders' => [
                [
                    'name' => 'demo',
                    'resources' => [
                        // Store appears BEFORE Country in input order, but its
                        // nested Address depends on Country.us — so Country
                        // must come first in the emitted order.
                        [
                            'name' => 'Store',
                            'rows' => [[
                                'data' => [
                                    'address_id' => [
                                        '$' => 'nested',
                                        'resource' => 'Address',
                                        'data' => [
                                            'country_id' => ['$' => 'ref', 'resource' => 'Country', 'ref' => 'us'],
                                        ],
                                    ],
                                ],
                            ]],
                        ],
                        ['name' => 'Country', 'rows' => [['ref' => 'us', 'data' => ['code' => 'US']]]],
                    ],
                ],
            ],
        ]);

        // Country first (the nested Address refs it), then the hoisted Address,
        // then the parent Store.
        $this->assertSame('App\\Models\\Country', array_key_first($out['demo'][0]));
        $this->assertSame('App\\Models\\Address', array_key_first($out['demo'][1]));
        $this->assertSame('App\\Models\\Store',   array_key_first($out['demo'][2]));
    }

    /** @test */
    public function ref_to_random_id_row_without_natural_key_resolves_to_scalar_id(): void
    {
        // Bug regression: Address has a random-id PK and NO unique non-PK column,
        // so getNaturalKey() throws. resolveRef() must prefer the literal id that
        // preAssignIds() injected instead of falling through to the natural-key path.
        $randomIdField = ['name' => 'id', 'type' => 'random-id', 'index' => 'primary'];

        $out = $this->transform([
            'schemas' => [
                $this->schema('Address', [$randomIdField]),
                $this->schema('Store',   [$this->pkField()]),
            ],
            'seeders' => [
                ['name' => 'demo', 'resources' => [
                    ['name' => 'Address', 'rows' => [
                        ['ref' => 'home', 'data' => ['street' => '123 Main']],
                    ]],
                    ['name' => 'Store', 'rows' => [
                        ['data' => [
                            'name' => 'Main',
                            'address_id' => ['$' => 'ref', 'resource' => 'Address', 'ref' => 'home'],
                        ]],
                    ]],
                ]],
            ],
        ]);

        $assignedId  = $out['demo'][0]['App\\Models\\Address']['id'];
        $resolvedRef = $out['demo'][1]['App\\Models\\Store']['address_id'];

        $this->assertIsInt($assignedId);
        $this->assertSame($assignedId, $resolvedRef, 'ref should resolve to the scalar id injected by preAssignIds');
    }

    /** @test */
    public function ref_to_random_id_row_prefers_scalar_id_over_natural_key_lookup(): void
    {
        // Even when a natural key IS available, the pre-assigned scalar id wins.
        // Avoids an unnecessary Model::where(...)->value('id') round-trip at seed time.
        $randomIdField = ['name' => 'id', 'type' => 'random-id', 'index' => 'primary'];

        $out = $this->transform([
            'schemas' => [
                $this->schema('Event', [$randomIdField, $this->uniqueField('slug')]),
                $this->schema('Ticket', [$this->pkField()]),
            ],
            'seeders' => [
                ['name' => 'demo', 'resources' => [
                    ['name' => 'Event', 'rows' => [
                        ['ref' => 'launch', 'data' => ['slug' => 'launch-day']],
                    ]],
                    ['name' => 'Ticket', 'rows' => [
                        ['data' => [
                            'event_id' => ['$' => 'ref', 'resource' => 'Event', 'ref' => 'launch'],
                        ]],
                    ]],
                ]],
            ],
        ]);

        $assignedId  = $out['demo'][0]['App\\Models\\Event']['id'];
        $resolvedRef = $out['demo'][1]['App\\Models\\Ticket']['event_id'];

        $this->assertSame($assignedId, $resolvedRef);
        $this->assertIsNotArray($resolvedRef, 'should not emit a Model::where descriptor when a scalar id is available');
    }

    /** @test */
    public function it_backfills_declared_default_when_column_is_absent(): void
    {
        // insert() bypasses Eloquent $attributes defaults, so a column omitted
        // from the row data must be filled from the schema's declared default.
        $out = $this->transform([
            'schemas' => [
                $this->schema('Widget', [
                    $this->uniqueField('name'),
                    ['name' => 'status', 'type' => 'string', 'default' => 'active'],
                    ['name' => 'priority', 'type' => 'integer', 'default' => 0],
                ]),
            ],
            'seeders' => [
                ['name' => 'system', 'resources' => [
                    ['name' => 'Widget', 'rows' => [
                        ['data' => ['name' => 'alpha']],
                    ]],
                ]],
            ],
        ]);

        $this->assertSame([
            'system' => [
                ['App\\Models\\Widget' => ['name' => 'alpha', 'status' => 'active', 'priority' => 0]],
            ],
        ], $out);
    }

    /** @test */
    public function it_does_not_override_an_explicitly_provided_value_with_the_default(): void
    {
        $out = $this->transform([
            'schemas' => [
                $this->schema('Widget', [
                    $this->uniqueField('name'),
                    ['name' => 'status', 'type' => 'string', 'default' => 'active'],
                ]),
            ],
            'seeders' => [
                ['name' => 'system', 'resources' => [
                    ['name' => 'Widget', 'rows' => [
                        ['data' => ['name' => 'alpha', 'status' => 'archived']],
                    ]],
                ]],
            ],
        ]);

        $this->assertSame('archived', $out['system'][0]['App\\Models\\Widget']['status']);
    }

    /** @test */
    public function it_respects_an_explicit_null_over_the_default(): void
    {
        // An explicit null is a deliberate choice — the default must not clobber it.
        $out = $this->transform([
            'schemas' => [
                $this->schema('Widget', [
                    $this->uniqueField('name'),
                    ['name' => 'status', 'type' => 'string', 'default' => 'active'],
                ]),
            ],
            'seeders' => [
                ['name' => 'system', 'resources' => [
                    ['name' => 'Widget', 'rows' => [
                        ['data' => ['name' => 'alpha', 'status' => null]],
                    ]],
                ]],
            ],
        ]);

        $this->assertArrayHasKey('status', $out['system'][0]['App\\Models\\Widget']);
        $this->assertNull($out['system'][0]['App\\Models\\Widget']['status']);
    }

    /** @test */
    public function it_does_not_backfill_fields_without_a_declared_default(): void
    {
        // A null default is treated as "no default" — mirrors SchemaHandler::addDefaults.
        $out = $this->transform([
            'schemas' => [
                $this->schema('Widget', [
                    $this->uniqueField('name'),
                    ['name' => 'note', 'type' => 'string'],
                    ['name' => 'status', 'type' => 'string', 'default' => null],
                ]),
            ],
            'seeders' => [
                ['name' => 'system', 'resources' => [
                    ['name' => 'Widget', 'rows' => [
                        ['data' => ['name' => 'alpha']],
                    ]],
                ]],
            ],
        ]);

        $this->assertSame([
            'system' => [
                ['App\\Models\\Widget' => ['name' => 'alpha']],
            ],
        ], $out);
    }

    /** @test */
    public function it_passes_through_unchanged_when_no_schema_exists_for_the_resource(): void
    {
        // Seeders-only run (no schemas): nothing to backfill, row passes through.
        $out = $this->transform([
            'schemas' => [],
            'seeders' => [
                ['name' => 'system', 'resources' => [
                    ['name' => 'Widget', 'rows' => [
                        ['data' => ['name' => 'alpha']],
                    ]],
                ]],
            ],
        ]);

        $this->assertSame([
            'system' => [
                ['App\\Models\\Widget' => ['name' => 'alpha']],
            ],
        ], $out);
    }

    /** @test */
    public function it_backfills_defaults_into_hoisted_nested_inserts(): void
    {
        // The hoisted child insert must carry the declared default explicitly,
        // since insert() bypasses Eloquent $attributes defaults.
        $out = $this->transform([
            'schemas' => [
                $this->schema('Post', [$this->pkField(), $this->uniqueField('title')]),
                $this->schema('Author', [
                    $this->pkField(),
                    $this->uniqueField('name'),
                    ['name' => 'status', 'type' => 'string', 'default' => 'active'],
                ]),
            ],
            'seeders' => [
                ['name' => 'demo', 'resources' => [
                    ['name' => 'Post', 'rows' => [
                        ['data' => [
                            'title' => 'Hello',
                            'author_id' => [
                                '$' => 'nested',
                                'resource' => 'Author',
                                'data' => ['name' => 'jane'],
                            ],
                        ]],
                    ]],
                ]],
            ],
        ]);

        $this->assertSame(
            ['id' => 1, 'name' => 'jane', 'status' => 'active'],
            $out['demo'][0]['App\\Models\\Author']
        );
        $this->assertSame(1, $out['demo'][1]['App\\Models\\Post']['author_id']);
    }

    /** @test */
    public function hoisted_nested_child_continues_the_increments_sequence_of_its_resource(): void
    {
        // A top-level Tag takes id 1; a nested Tag child must take id 2 so the
        // hoisted insert never collides with the top-level row.
        $out = $this->transform([
            'schemas' => [
                $this->schema('Tag',  [$this->pkField(), $this->uniqueField('name')]),
                $this->schema('Post', [$this->pkField()]),
            ],
            'seeders' => [
                ['name' => 'demo', 'resources' => [
                    ['name' => 'Tag', 'rows' => [['data' => ['name' => 'php']]]],
                    ['name' => 'Post', 'rows' => [[
                        'data' => [
                            'tag_id' => ['$' => 'nested', 'resource' => 'Tag', 'data' => ['name' => 'laravel']],
                        ],
                    ]]],
                ]],
            ],
        ]);

        $this->assertSame(1, $out['demo'][0]['App\\Models\\Tag']['id'], 'top-level Tag keeps id 1');
        $this->assertSame(2, $out['demo'][1]['App\\Models\\Tag']['id'], 'hoisted nested Tag continues the sequence');
        $this->assertSame('laravel', $out['demo'][1]['App\\Models\\Tag']['name']);
        $this->assertSame(2, $out['demo'][2]['App\\Models\\Post']['tag_id']);
    }

    /** @test */
    public function nested_child_with_explicit_literal_id_is_honoured(): void
    {
        $out = $this->transform([
            'schemas' => [
                $this->schema('Address', [$this->pkField()]),
                $this->schema('Store',   [$this->pkField()]),
            ],
            'seeders' => [
                ['name' => 'demo', 'resources' => [
                    ['name' => 'Store', 'rows' => [[
                        'data' => [
                            'address_id' => [
                                '$' => 'nested',
                                'resource' => 'Address',
                                'data' => ['id' => 99, 'street' => 'x'],
                            ],
                        ],
                    ]]],
                ]],
            ],
        ]);

        $this->assertSame(['id' => 99, 'street' => 'x'], $out['demo'][0]['App\\Models\\Address']);
        $this->assertSame(99, $out['demo'][1]['App\\Models\\Store']['address_id']);
    }

    /** @test */
    public function nested_child_with_unassignable_pk_is_rejected(): void
    {
        // A uuid PK can only be known at runtime, so it can't be hoisted into a
        // literal parent FK — the author must use a flat row + ref instead.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/can.t be pre-assigned a literal id/');

        $this->transform([
            'schemas' => [
                $this->schema('Address', [$this->uuidPkField()]),
                $this->schema('Store',   [$this->pkField()]),
            ],
            'seeders' => [
                ['name' => 'demo', 'resources' => [
                    ['name' => 'Store', 'rows' => [[
                        'data' => [
                            'address_id' => [
                                '$' => 'nested',
                                'resource' => 'Address',
                                'data' => ['street' => 'x'],
                            ],
                        ],
                    ]]],
                ]],
            ],
        ]);
    }

    /** @test */
    public function attach_emits_pivot_inserts_after_all_model_rows(): void
    {
        $out = $this->transform([
            'schemas' => [
                [
                    'name' => 'Post',
                    'fields' => [$this->pkField(), $this->uniqueField('title')],
                    'relationships' => [['name' => 'tags', 'type' => 'belongsToMany', 'target' => 'Tag']],
                ],
                $this->schema('Tag', [$this->pkField(), $this->uniqueField('name')]),
            ],
            'seeders' => [
                ['name' => 'demo', 'resources' => [
                    ['name' => 'Tag', 'rows' => [
                        ['ref' => 'php', 'data' => ['name' => 'PHP']],
                        ['ref' => 'laravel', 'data' => ['name' => 'Laravel']],
                    ]],
                    ['name' => 'Post', 'rows' => [
                        ['data' => [
                            'title' => 'Hello',
                            'tags' => ['$' => 'attach', 'refs' => [
                                ['resource' => 'Tag', 'ref' => 'php'],
                                ['resource' => 'Tag', 'ref' => 'laravel'],
                            ]],
                        ]],
                    ]],
                ]],
            ],
        ]);

        // Model rows first, pivot links last; `tags` never lands on the Post row.
        $this->assertCount(5, $out['demo']);
        $this->assertSame(['id' => 1, 'name' => 'PHP'], $out['demo'][0]['App\\Models\\Tag']);
        $this->assertSame(['id' => 2, 'name' => 'Laravel'], $out['demo'][1]['App\\Models\\Tag']);
        $this->assertSame(['id' => 1, 'title' => 'Hello'], $out['demo'][2]['App\\Models\\Post']);
        $this->assertSame(['table' => 'post_tag', 'insert' => ['post_id' => 1, 'tag_id' => 1]], $out['demo'][3]);
        $this->assertSame(['table' => 'post_tag', 'insert' => ['post_id' => 1, 'tag_id' => 2]], $out['demo'][4]);
    }

    /** @test */
    public function attach_dedupes_bidirectional_declarations(): void
    {
        // belongsToMany declared on both ends: attaching from each side produces
        // the same post_tag row, which must be emitted only once.
        $out = $this->transform([
            'schemas' => [
                [
                    'name' => 'Post',
                    'fields' => [$this->pkField(), $this->uniqueField('title')],
                    'relationships' => [['name' => 'tags', 'type' => 'belongsToMany', 'target' => 'Tag']],
                ],
                [
                    'name' => 'Tag',
                    'fields' => [$this->pkField(), $this->uniqueField('name')],
                    'relationships' => [['name' => 'posts', 'type' => 'belongsToMany', 'target' => 'Post']],
                ],
            ],
            'seeders' => [
                ['name' => 'demo', 'resources' => [
                    ['name' => 'Post', 'rows' => [
                        ['ref' => 'hello', 'data' => [
                            'title' => 'Hello',
                            'tags' => ['$' => 'attach', 'refs' => [['resource' => 'Tag', 'ref' => 'php']]],
                        ]],
                    ]],
                    ['name' => 'Tag', 'rows' => [
                        ['ref' => 'php', 'data' => [
                            'name' => 'PHP',
                            'posts' => ['$' => 'attach', 'refs' => [['resource' => 'Post', 'ref' => 'hello']]],
                        ]],
                    ]],
                ]],
            ],
        ]);

        // 2 model rows + exactly 1 pivot row (not 2).
        $this->assertCount(3, $out['demo']);
        $this->assertSame(
            ['table' => 'post_tag', 'insert' => ['post_id' => 1, 'tag_id' => 1]],
            $out['demo'][2]
        );
    }

    /** @test */
    public function attach_to_an_undeclared_belongstomany_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/no belongsToMany pivot/');

        $this->transform([
            'schemas' => [
                $this->schema('Post', [$this->pkField(), $this->uniqueField('title')]),
                $this->schema('Tag', [$this->pkField(), $this->uniqueField('name')]),
            ],
            'seeders' => [
                ['name' => 'demo', 'resources' => [
                    ['name' => 'Tag', 'rows' => [['ref' => 'php', 'data' => ['name' => 'PHP']]]],
                    ['name' => 'Post', 'rows' => [
                        ['data' => [
                            'title' => 'Hello',
                            'tags' => ['$' => 'attach', 'refs' => [['resource' => 'Tag', 'ref' => 'php']]],
                        ]],
                    ]],
                ]],
            ],
        ]);
    }

    /** @test */
    public function attach_nested_inside_a_column_value_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/attach directive is only valid/');

        $this->transform([
            'schemas' => [$this->schema('Address', [$this->pkField()])],
            'seeders' => [
                ['name' => 'demo', 'resources' => [
                    ['name' => 'Store', 'rows' => [[
                        'data' => [
                            'address_id' => [
                                '$' => 'nested',
                                'resource' => 'Address',
                                'data' => [
                                    'extras' => ['$' => 'attach', 'refs' => [['resource' => 'Tag', 'ref' => 'x']]],
                                ],
                            ],
                        ],
                    ]]],
                ]],
            ],
        ]);
    }

    /** @test */
    public function morph_expands_to_id_and_type_columns(): void
    {
        // morphTo: the `commentable` key expands to commentable_id (the parent's
        // id) + commentable_type (the morph-map alias, kebab-singular).
        $out = $this->transform([
            'schemas' => [
                $this->schema('Post', [$this->pkField(), $this->uniqueField('title')]),
                $this->schema('Comment', [$this->pkField()]),
            ],
            'seeders' => [
                ['name' => 'demo', 'resources' => [
                    ['name' => 'Post', 'rows' => [['ref' => 'hello', 'data' => ['title' => 'Hello']]]],
                    ['name' => 'Comment', 'rows' => [[
                        'data' => [
                            'body' => 'Nice',
                            'commentable' => ['$' => 'morph', 'resource' => 'Post', 'ref' => 'hello'],
                        ],
                    ]]],
                ]],
            ],
        ]);

        $this->assertSame(
            ['id' => 1, 'body' => 'Nice', 'commentable_id' => 1, 'commentable_type' => 'post'],
            $out['demo'][1]['App\\Models\\Comment']
        );
    }

    /** @test */
    public function morph_type_uses_kebab_singular_alias_for_multiword_resource(): void
    {
        $out = $this->transform([
            'schemas' => [
                $this->schema('BlogPost', [$this->pkField(), $this->uniqueField('title')]),
                $this->schema('Comment', [$this->pkField()]),
            ],
            'seeders' => [
                ['name' => 'demo', 'resources' => [
                    ['name' => 'BlogPost', 'rows' => [['ref' => 'hello', 'data' => ['title' => 'Hello']]]],
                    ['name' => 'Comment', 'rows' => [[
                        'data' => ['commentable' => ['$' => 'morph', 'resource' => 'BlogPost', 'ref' => 'hello']],
                    ]]],
                ]],
            ],
        ]);

        // Matches MorphMapGenerator's alias: Str::kebab(Str::singular('BlogPost')).
        $this->assertSame('blog-post', $out['demo'][1]['App\\Models\\Comment']['commentable_type']);
    }

    /** @test */
    public function morph_orders_referenced_parent_before_the_row(): void
    {
        // Comment is declared BEFORE Post, but the morph dependency forces Post first.
        $out = $this->transform([
            'schemas' => [
                $this->schema('Post', [$this->pkField(), $this->uniqueField('title')]),
                $this->schema('Comment', [$this->pkField()]),
            ],
            'seeders' => [
                ['name' => 'demo', 'resources' => [
                    ['name' => 'Comment', 'rows' => [[
                        'data' => ['commentable' => ['$' => 'morph', 'resource' => 'Post', 'ref' => 'hello']],
                    ]]],
                    ['name' => 'Post', 'rows' => [['ref' => 'hello', 'data' => ['title' => 'Hello']]]],
                ]],
            ],
        ]);

        $this->assertSame('App\\Models\\Post', array_key_first($out['demo'][0]));
        $this->assertSame('App\\Models\\Comment', array_key_first($out['demo'][1]));
    }

    /** @test */
    public function morphmany_is_seeded_as_morphto_on_each_child(): void
    {
        // morphMany has its FK on the child, so a Post's many Comments are just
        // child rows each carrying a morphTo back at the Post.
        $out = $this->transform([
            'schemas' => [
                $this->schema('Post', [$this->pkField(), $this->uniqueField('title')]),
                $this->schema('Comment', [$this->pkField()]),
            ],
            'seeders' => [
                ['name' => 'demo', 'resources' => [
                    ['name' => 'Post', 'rows' => [['ref' => 'hello', 'data' => ['title' => 'Hello']]]],
                    ['name' => 'Comment', 'rows' => [
                        ['data' => ['body' => 'first',  'commentable' => ['$' => 'morph', 'resource' => 'Post', 'ref' => 'hello']]],
                        ['data' => ['body' => 'second', 'commentable' => ['$' => 'morph', 'resource' => 'Post', 'ref' => 'hello']]],
                    ]],
                ]],
            ],
        ]);

        $this->assertSame(['id' => 1, 'body' => 'first',  'commentable_id' => 1, 'commentable_type' => 'post'], $out['demo'][1]['App\\Models\\Comment']);
        $this->assertSame(['id' => 2, 'body' => 'second', 'commentable_id' => 1, 'commentable_type' => 'post'], $out['demo'][2]['App\\Models\\Comment']);
    }

    /** @test */
    public function morph_nested_inside_a_column_value_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/morph directive is only valid/');

        $this->transform([
            'schemas' => [$this->schema('Address', [$this->pkField()])],
            'seeders' => [
                ['name' => 'demo', 'resources' => [
                    ['name' => 'Store', 'rows' => [[
                        'data' => [
                            'address_id' => [
                                '$' => 'nested',
                                'resource' => 'Address',
                                'data' => [
                                    'owner' => ['$' => 'morph', 'resource' => 'Post', 'ref' => 'x'],
                                ],
                            ],
                        ],
                    ]]],
                ]],
            ],
        ]);
    }

}
