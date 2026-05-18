<?php

namespace Firevel\ApiResourceSchemaGenerator\Tests;

use Firevel\ApiResourceSchemaGenerator\SeedersTransformerGenerator;

class SeedersTransformerTest extends TestCase
{
    /**
     * Convenience: run the transformer and return the resulting
     * `transformed_seeders` from the shared PipelineContext.
     *
     * @param array $input  the full pre-scoped input (with `seeders` + optional `schemas`)
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
                'system' => [
                    'Role' => [
                        ['_ref' => 'admin', 'name' => 'admin', 'description' => 'Full access'],
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
                'demo' => [
                    'User' => [[
                        'id' => ['$uuid' => true],
                        'password' => ['$hash' => 'secret'],
                        'created_at' => ['$now' => true],
                    ]],
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
                'system' => [
                    'Role' => [
                        ['_ref' => 'admin', 'name' => 'admin'],
                    ],
                    'User' => [
                        ['name' => 'John', 'email' => 'john@x.com', 'role_id' => ['$ref' => 'Role.admin']],
                    ],
                ],
            ],
        ]);

        // Role row comes first via topo sort because User depends on it.
        $this->assertSame(
            ['App\\Models\\Role' => ['name' => 'admin']],
            $out['system'][0]
        );

        // User's role_id becomes a chained lookup.
        $userRow = $out['system'][1]['App\\Models\\User'];
        $this->assertSame(
            ['App\\Models\\Role' => ['where' => ['name', 'admin'], 'value' => 'id']],
            $userRow['role_id']
        );
    }

    /** @test */
    public function topo_sort_reorders_dependent_rows_within_a_set(): void
    {
        // User row appears BEFORE Role row in input order, but $refs Role.
        // Transformer must reorder so Role gets inserted first.
        $out = $this->transform([
            'schemas' => [
                $this->schema('Role', [$this->pkField(), $this->uniqueField('name')]),
                $this->schema('User', [$this->pkField(), $this->uniqueField('email')]),
            ],
            'seeders' => [
                'system' => [
                    'User' => [
                        ['name' => 'John', 'email' => 'john@x.com', 'role_id' => ['$ref' => 'Role.admin']],
                    ],
                    'Role' => [
                        ['_ref' => 'admin', 'name' => 'admin'],
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
                'system' => [
                    'Role' => [
                        [
                            '_ref' => 'admin',
                            'code' => 'ADMIN',
                            'slug' => 'admin',
                            'name' => 'Administrator',
                            'email' => 'admin@x.com',
                        ],
                    ],
                    'OtherTable' => [
                        ['role_id' => ['$ref' => 'Role.admin']],
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
                'system' => [
                    'Token' => [['_ref' => 'one', 'token_hash' => 'abc123']],
                    'Audit' => [['token_id' => ['$ref' => 'Token.one']]],
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
                'system' => [
                    'Role' => [['_ref' => 'a', 'name' => 'a']],
                    'Other' => [['role_id' => ['$ref' => 'Role.a']]],
                ],
            ],
        ]);
    }

    /** @test */
    public function it_errors_on_dangling_ref_label(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/no matching _ref label/');

        $this->transform([
            'schemas' => [$this->schema('Role', [$this->pkField(), $this->uniqueField('name')])],
            'seeders' => [
                'system' => [
                    'Role' => [['_ref' => 'admin', 'name' => 'admin']],
                    'Other' => [['role_id' => ['$ref' => 'Role.ghost']]],
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
                'system' => [
                    'A' => [['_ref' => 'a1', 'name' => 'a', 'b_id' => ['$ref' => 'B.b1']]],
                    'B' => [['_ref' => 'b1', 'name' => 'b', 'a_id' => ['$ref' => 'A.a1']]],
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
                'system' => [
                    // The natural-key column itself is a directive — unresolvable.
                    'Token' => [['_ref' => 'one', 'hash' => ['$uuid' => true]]],
                    'Audit' => [['token_id' => ['$ref' => 'Token.one']]],
                ],
            ],
        ]);
    }

    /** @test */
    public function it_strips_underscore_ref_labels(): void
    {
        $out = $this->transform([
            'schemas' => [],
            'seeders' => [
                'system' => [
                    'Role' => [['_ref' => 'admin', 'name' => 'admin']],
                ],
            ],
        ]);

        $this->assertArrayNotHasKey('_ref', $out['system'][0]['App\\Models\\Role']);
    }

    /** @test */
    public function it_emits_full_input_with_transformed_seeders_for_chaining(): void
    {
        $generator = $this->runGenerator(SeedersTransformerGenerator::class, [
            'service' => ['name' => 'app'],
            'schemas' => [],
            'seeders' => [
                'system' => [
                    'Role' => [['_ref' => 'admin', 'name' => 'admin']],
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
    public function worked_example_from_brief_round_trips(): void
    {
        // Mirrors the LLM brief verbatim and checks each translated piece.
        $out = $this->transform([
            'schemas' => [
                $this->schema('Role', [$this->pkField(), $this->uniqueField('name')]),
                $this->schema('Permission', [$this->pkField(), $this->uniqueField('name')]),
                $this->schema('User', [$this->pkField(), $this->uniqueField('email')]),
            ],
            'seeders' => [
                'system' => [
                    'Role' => [
                        ['_ref' => 'admin', 'name' => 'admin', 'description' => 'Full access'],
                        ['_ref' => 'member', 'name' => 'member', 'description' => 'Standard user'],
                    ],
                    'Permission' => [
                        ['_ref' => 'posts_read', 'name' => 'posts.read'],
                        ['_ref' => 'posts_write', 'name' => 'posts.write'],
                    ],
                    'RolePermission' => [
                        ['role_id' => ['$ref' => 'Role.admin'], 'permission_id' => ['$ref' => 'Permission.posts_read']],
                        ['role_id' => ['$ref' => 'Role.admin'], 'permission_id' => ['$ref' => 'Permission.posts_write']],
                        ['role_id' => ['$ref' => 'Role.member'], 'permission_id' => ['$ref' => 'Permission.posts_read']],
                    ],
                ],
                'demo' => [
                    'User' => [[
                        '_ref' => 'john',
                        'id' => ['$uuid' => true],
                        'name' => 'John Doe',
                        'email' => 'john@example.com',
                        'password' => ['$hash' => 'password123'],
                        'role_id' => ['$ref' => 'Role.admin'],
                        'email_verified_at' => ['$now' => true],
                        'created_at' => ['$now' => true],
                        'updated_at' => ['$now' => true],
                    ]],
                ],
            ],
        ]);

        // System set: 4 reference rows + 3 pivot rows, topo-sorted.
        $this->assertCount(7, $out['system']);

        // Role rows come first (no deps).
        $this->assertSame(['App\\Models\\Role' => ['name' => 'admin', 'description' => 'Full access']], $out['system'][0]);
        $this->assertSame(['App\\Models\\Role' => ['name' => 'member', 'description' => 'Standard user']], $out['system'][1]);

        // Permissions next.
        $this->assertSame(['App\\Models\\Permission' => ['name' => 'posts.read']], $out['system'][2]);
        $this->assertSame(['App\\Models\\Permission' => ['name' => 'posts.write']], $out['system'][3]);

        // RolePermission pivot rows last — each FK is a Eloquent lookup chain.
        $pivot = $out['system'][4]['App\\Models\\RolePermission'];
        $this->assertSame(
            ['App\\Models\\Role' => ['where' => ['name', 'admin'], 'value' => 'id']],
            $pivot['role_id']
        );
        $this->assertSame(
            ['App\\Models\\Permission' => ['where' => ['name', 'posts.read'], 'value' => 'id']],
            $pivot['permission_id']
        );

        // Demo set: John has 5 directives translated + the FK lookup.
        $john = $out['demo'][0]['App\\Models\\User'];
        $this->assertSame(['Illuminate\\Support\\Str' => ['uuid' => null]], $john['id']);
        $this->assertSame('John Doe', $john['name']);
        $this->assertSame('john@example.com', $john['email']);
        $this->assertSame(['Illuminate\\Support\\Facades\\Hash' => ['make' => 'password123']], $john['password']);
        $this->assertSame(['App\\Models\\Role' => ['where' => ['name', 'admin'], 'value' => 'id']], $john['role_id']);
        $this->assertSame(['Illuminate\\Support\\Carbon' => ['now' => null]], $john['email_verified_at']);
        $this->assertSame(['Illuminate\\Support\\Carbon' => ['now' => null]], $john['created_at']);
        $this->assertSame(['Illuminate\\Support\\Carbon' => ['now' => null]], $john['updated_at']);
        $this->assertArrayNotHasKey('_ref', $john);
    }
}
