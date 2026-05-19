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
    public function it_resolves_ref_via_natural_key_lookup(): void
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
        $this->assertSame(
            ['App\\Models\\Role' => ['name' => 'admin']],
            $out['system'][0]
        );

        $userRow = $out['system'][1]['App\\Models\\User'];
        $this->assertSame(
            ['App\\Models\\Role' => ['where' => ['name', 'admin'], 'value' => 'id']],
            $userRow['role_id']
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
        $out = $this->transform([
            'schemas' => [
                $this->schema('Role', [
                    $this->pkField(),
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
        $out = $this->transform([
            'schemas' => [
                $this->schema('Token', [
                    $this->pkField(),
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
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/no unique non-PK column/');

        $this->transform([
            'schemas' => [
                $this->schema('Role', [
                    $this->pkField(),
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
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/natural-key column .* directive, not a literal/');

        $this->transform([
            'schemas' => [
                $this->schema('Token', [
                    $this->pkField(),
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

        $this->assertSame(
            ['App\\Models\\Role' => ['where' => ['name', 'admin'], 'value' => 'id']],
            $out['demo'][0]['App\\Models\\User']['role_id']
        );
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

        $this->assertSame(['App\\Models\\Role' => ['name' => 'admin', 'description' => 'Full access']], $out['system'][0]);
        $this->assertSame(['App\\Models\\Role' => ['name' => 'member', 'description' => 'Standard user']], $out['system'][1]);
        $this->assertSame(['App\\Models\\Permission' => ['name' => 'posts.read']], $out['system'][2]);
        $this->assertSame(['App\\Models\\Permission' => ['name' => 'posts.write']], $out['system'][3]);

        $pivot = $out['system'][4]['App\\Models\\RolePermission'];
        $this->assertSame(
            ['App\\Models\\Role' => ['where' => ['name', 'admin'], 'value' => 'id']],
            $pivot['role_id']
        );
        $this->assertSame(
            ['App\\Models\\Permission' => ['where' => ['name', 'posts.read'], 'value' => 'id']],
            $pivot['permission_id']
        );

        $john = $out['demo'][0]['App\\Models\\User'];
        $this->assertSame(['Illuminate\\Support\\Str' => ['uuid' => null]], $john['id']);
        $this->assertSame('John Doe', $john['name']);
        $this->assertSame('john@example.com', $john['email']);
        $this->assertSame(['Illuminate\\Support\\Facades\\Hash' => ['make' => 'password123']], $john['password']);
        $this->assertSame(['App\\Models\\Role' => ['where' => ['name', 'admin'], 'value' => 'id']], $john['role_id']);
        $this->assertSame(['Illuminate\\Support\\Carbon' => ['now' => null]], $john['email_verified_at']);
        $this->assertSame(['Illuminate\\Support\\Carbon' => ['now' => null]], $john['created_at']);
        $this->assertSame(['Illuminate\\Support\\Carbon' => ['now' => null]], $john['updated_at']);
    }
}
