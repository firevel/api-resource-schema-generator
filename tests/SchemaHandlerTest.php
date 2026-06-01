<?php

namespace Firevel\ApiResourceSchemaGenerator\Tests;

use Firevel\ApiResourceSchemaGenerator\SchemaHandler;

class SchemaHandlerTest extends TestCase
{
    protected function runHandler(array $attributes): array
    {
        // Delegated to the firevel/generator MakesGenerators trait so we get
        // a NullLogger + PipelineContext wired automatically.
        $handler = $this->runGenerator(SchemaHandler::class, $attributes);
        return $handler->resource()->output;
    }

    /** @test */
    public function belongs_to_without_target_infers_class_from_name()
    {
        $output = $this->runHandler([
            'name' => 'post',
            'fields' => [],
            'relationships' => [
                ['name' => 'user', 'type' => 'belongsTo'],
            ],
        ]);

        $this->assertSame(
            'belongsTo',
            $output['model']['relationships']['user'],
            'belongsTo without target or foreignKey should be the bare type string'
        );
    }

    /** @test */
    public function belongs_to_with_target_but_no_foreign_key_emits_bare_type()
    {
        // Locks in current behavior: belongsTo with target but no foreignKey
        // emits the bare type string and lets Laravel infer the FK.
        $output = $this->runHandler([
            'name' => 'post',
            'fields' => [],
            'relationships' => [
                ['name' => 'creator', 'type' => 'belongsTo', 'target' => 'User'],
            ],
        ]);

        $this->assertSame('belongsTo', $output['model']['relationships']['creator']);
    }

    /** @test */
    public function belongs_to_with_foreign_key_emits_array_form()
    {
        $output = $this->runHandler([
            'name' => 'post',
            'fields' => [],
            'relationships' => [
                ['name' => 'author', 'type' => 'belongsTo', 'target' => 'User', 'foreignKey' => 'author_id'],
            ],
        ]);

        $this->assertSame(
            ['belongsTo' => ['User::class', 'author_id']],
            $output['model']['relationships']['author']
        );
    }

    /** @test */
    public function has_many_with_target()
    {
        $output = $this->runHandler([
            'name' => 'post',
            'fields' => [],
            'relationships' => [
                ['name' => 'comments', 'type' => 'hasMany', 'target' => 'Comment'],
            ],
        ]);

        $this->assertSame('hasMany', $output['model']['relationships']['comments']);
    }

    /** @test */
    public function has_one_with_target_and_foreign_key()
    {
        $output = $this->runHandler([
            'name' => 'user',
            'fields' => [],
            'relationships' => [
                ['name' => 'profile', 'type' => 'hasOne', 'target' => 'Profile', 'foreignKey' => 'user_id'],
            ],
        ]);

        $this->assertSame(
            ['hasOne' => ['Profile::class', 'user_id']],
            $output['model']['relationships']['profile']
        );
    }

    /** @test */
    public function belongs_to_many_emits_target_only_and_ignores_foreign_key()
    {
        // Schema declares foreignKey is ignored for belongsToMany.
        $output = $this->runHandler([
            'name' => 'post',
            'fields' => [],
            'relationships' => [
                ['name' => 'tags', 'type' => 'belongsToMany', 'target' => 'Tag', 'foreignKey' => 'post_tag'],
            ],
        ]);

        $this->assertSame(
            ['belongsToMany' => ['Tag::class']],
            $output['model']['relationships']['tags']
        );
    }

    /** @test */
    public function belongs_to_many_emits_pivot_migration()
    {
        $output = $this->runHandler([
            'name' => 'post',
            'fields' => [],
            'relationships' => [
                ['name' => 'tags', 'type' => 'belongsToMany', 'target' => 'Tag'],
            ],
        ]);

        $this->assertSame(
            [[
                'table' => 'post_tag',
                'fields' => [
                    ['name' => 'post_id', 'type' => 'id'],
                    ['name' => 'tag_id', 'type' => 'id'],
                ],
            ]],
            $output['migrations']['pivot']
        );
    }

    /** @test */
    public function belongs_to_many_pivot_table_name_is_alphabetical_regardless_of_side()
    {
        // Same pivot table emitted whether `user` or `tag` declares the relationship.
        $output = $this->runHandler([
            'name' => 'user',
            'fields' => [],
            'relationships' => [
                ['name' => 'tags', 'type' => 'belongsToMany', 'target' => 'Tag'],
            ],
        ]);

        $this->assertSame('tag_user', $output['migrations']['pivot'][0]['table']);
    }

    /** @test */
    public function morph_to_drops_redundant_morph_name_matching_method()
    {
        // morphName matches what Laravel auto-derives from the method name
        // (Str::snake of the method) — explicit arg is redundant, emit bare.
        $output = $this->runHandler([
            'name' => 'comment',
            'fields' => [],
            'relationships' => [
                ['name' => 'commentable', 'type' => 'morphTo', 'morphName' => 'commentable'],
            ],
        ]);

        $this->assertSame('morphTo', $output['model']['relationships']['commentable']);
    }

    /** @test */
    public function morph_to_keeps_explicit_morph_name_when_it_differs_from_method()
    {
        // morphName differs from the auto-derived value, so Laravel CAN'T
        // infer it — keep the explicit arg.
        $output = $this->runHandler([
            'name' => 'earning',
            'fields' => [],
            'relationships' => [
                ['name' => 'parent', 'type' => 'morphTo', 'morphName' => 'earnable'],
            ],
        ]);

        $this->assertSame(
            ['morphTo' => ['earnable']],
            $output['model']['relationships']['parent']
        );
    }

    /** @test */
    public function morph_to_without_morph_name_emits_bare_type()
    {
        $output = $this->runHandler([
            'name' => 'comment',
            'fields' => [],
            'relationships' => [
                ['name' => 'commentable', 'type' => 'morphTo'],
            ],
        ]);

        $this->assertSame('morphTo', $output['model']['relationships']['commentable']);
    }

    /** @test */
    public function morph_many_emits_target_and_morph_name()
    {
        $output = $this->runHandler([
            'name' => 'post',
            'fields' => [],
            'relationships' => [
                ['name' => 'comments', 'type' => 'morphMany', 'target' => 'Comment', 'morphName' => 'commentable'],
            ],
        ]);

        $this->assertSame(
            ['morphMany' => ['Comment::class', 'commentable']],
            $output['model']['relationships']['comments']
        );
    }

    /** @test */
    public function relationship_name_is_camel_cased_for_method_key()
    {
        $output = $this->runHandler([
            'name' => 'post',
            'fields' => [],
            'relationships' => [
                ['name' => 'blog-posts', 'type' => 'hasMany', 'target' => 'BlogPost'],
            ],
        ]);

        $this->assertArrayHasKey('blogPosts', $output['model']['relationships']);
        $this->assertArrayNotHasKey('blog-posts', $output['model']['relationships']);
    }

    /** @test */
    public function transformer_include_uses_target_not_relationship_name()
    {
        // Bug regression: relationship "creator" with target "User" was emitting
        // CreatorTransformer instead of UserTransformer.
        $output = $this->runHandler([
            'name' => 'post',
            'fields' => [],
            'relationships' => [
                [
                    'name' => 'creator',
                    'type' => 'belongsTo',
                    'target' => 'User',
                    'transform' => true,
                ],
            ],
        ]);

        $this->assertSame(
            ['creator' => 'UserTransformer'],
            $output['transformer']['availableIncludes']
        );
    }

    /** @test */
    public function transformer_include_falls_back_to_name_when_no_target()
    {
        $output = $this->runHandler([
            'name' => 'comment',
            'fields' => [],
            'relationships' => [
                ['name' => 'user', 'type' => 'belongsTo', 'transform' => true],
            ],
        ]);

        $this->assertSame(
            ['user' => 'UserTransformer'],
            $output['transformer']['availableIncludes']
        );
    }

    /** @test */
    public function transformer_include_respects_explicit_transformer_override()
    {
        $output = $this->runHandler([
            'name' => 'post',
            'fields' => [],
            'relationships' => [
                [
                    'name' => 'creator',
                    'type' => 'belongsTo',
                    'target' => 'User',
                    'transformer' => 'AdminUserTransformer',
                    'transform' => true,
                ],
            ],
        ]);

        $this->assertSame(
            ['creator' => 'AdminUserTransformer'],
            $output['transformer']['availableIncludes']
        );
    }

    /** @test */
    public function transformer_include_pluralized_target_is_singularized()
    {
        // Schema documents target as plural kebab-case; verify singularization happens.
        $output = $this->runHandler([
            'name' => 'comment',
            'fields' => [],
            'relationships' => [
                [
                    'name' => 'author',
                    'type' => 'belongsTo',
                    'target' => 'BlogUsers',
                    'transform' => true,
                ],
            ],
        ]);

        $this->assertSame(
            ['author' => 'BlogUserTransformer'],
            $output['transformer']['availableIncludes']
        );
    }

    /** @test */
    public function relationships_without_transform_do_not_create_available_includes()
    {
        $output = $this->runHandler([
            'name' => 'post',
            'fields' => [],
            'relationships' => [
                ['name' => 'user', 'type' => 'belongsTo'],
            ],
        ]);

        $this->assertArrayNotHasKey('availableIncludes', $output['transformer']);
    }

    /** @test */
    public function multiple_relationships_emit_all_keys()
    {
        $output = $this->runHandler([
            'name' => 'post',
            'fields' => [],
            'relationships' => [
                ['name' => 'creator', 'type' => 'belongsTo', 'target' => 'User', 'transform' => true],
                ['name' => 'comments', 'type' => 'hasMany', 'target' => 'Comment', 'transform' => true],
                ['name' => 'tags', 'type' => 'belongsToMany', 'target' => 'Tag'],
            ],
        ]);

        $this->assertSame(
            ['creator', 'comments', 'tags'],
            array_keys($output['model']['relationships'])
        );

        $this->assertSame(
            ['creator' => 'UserTransformer', 'comments' => 'CommentTransformer'],
            $output['transformer']['availableIncludes']
        );
    }

    /** @test */
    public function deleted_at_timestamp_field_adds_soft_deletes_trait()
    {
        $output = $this->runHandler([
            'name' => 'post',
            'fields' => [
                ['name' => 'deleted_at', 'type' => 'timestamp'],
            ],
            'relationships' => [],
        ]);

        $this->assertArrayHasKey('SoftDeletes', $output['model']['use']);
        $this->assertSame(
            'Illuminate\Database\Eloquent\SoftDeletes',
            $output['model']['use']['SoftDeletes']
        );
    }

    /** @test */
    public function deleted_at_datetime_field_adds_soft_deletes_trait()
    {
        $output = $this->runHandler([
            'name' => 'post',
            'fields' => [
                ['name' => 'deleted_at', 'type' => 'datetime'],
            ],
            'relationships' => [],
        ]);

        $this->assertArrayHasKey('SoftDeletes', $output['model']['use']);
    }

    /** @test */
    public function no_deleted_at_field_does_not_add_soft_deletes_trait()
    {
        $output = $this->runHandler([
            'name' => 'post',
            'fields' => [
                ['name' => 'title', 'type' => 'string'],
            ],
            'relationships' => [],
        ]);

        $this->assertArrayNotHasKey('SoftDeletes', $output['model']['use'] ?? []);
    }

    /** @test */
    public function deleted_at_with_non_temporal_type_does_not_add_soft_deletes_trait()
    {
        // A `deleted_at` field with the wrong type is not a soft-delete column.
        $output = $this->runHandler([
            'name' => 'post',
            'fields' => [
                ['name' => 'deleted_at', 'type' => 'integer'],
            ],
            'relationships' => [],
        ]);

        $this->assertArrayNotHasKey('SoftDeletes', $output['model']['use'] ?? []);
    }

    /** @test */
    public function searchable_field_registers_laravel_scout_composer_require()
    {
        $handler = $this->runGenerator(SchemaHandler::class, [
            'name' => 'post',
            'fields' => [
                ['name' => 'title', 'type' => 'string', 'searchable' => true],
            ],
            'relationships' => [],
        ]);

        $requires = $handler->context()->get('composer_requires', []);
        $this->assertSame('*', $requires['laravel/scout'] ?? null);
    }

    /** @test */
    public function no_searchable_fields_does_not_register_composer_require()
    {
        $handler = $this->runGenerator(SchemaHandler::class, [
            'name' => 'post',
            'fields' => [
                ['name' => 'title', 'type' => 'string'],
            ],
            'relationships' => [],
        ]);

        $requires = $handler->context()->get('composer_requires', []);
        $this->assertArrayNotHasKey('laravel/scout', $requires);
    }

    /** @test */
    public function sortable_field_registers_firevel_sortable_composer_require()
    {
        $handler = $this->runGenerator(SchemaHandler::class, [
            'name' => 'post',
            'fields' => [
                ['name' => 'title', 'type' => 'string', 'sortable' => true],
            ],
            'relationships' => [],
        ]);

        $requires = $handler->context()->get('composer_requires', []);
        $this->assertSame('*', $requires['firevel/sortable'] ?? null);
    }

    /** @test */
    public function no_sortable_fields_does_not_register_composer_require()
    {
        $handler = $this->runGenerator(SchemaHandler::class, [
            'name' => 'post',
            'fields' => [
                ['name' => 'title', 'type' => 'string'],
            ],
            'relationships' => [],
        ]);

        $requires = $handler->context()->get('composer_requires', []);
        $this->assertArrayNotHasKey('firevel/sortable', $requires);
    }

    /** @test */
    public function filterable_field_registers_firevel_filterable_composer_require()
    {
        $handler = $this->runGenerator(SchemaHandler::class, [
            'name' => 'post',
            'fields' => [
                ['name' => 'title', 'type' => 'string', 'filterable' => true],
            ],
            'relationships' => [],
        ]);

        $requires = $handler->context()->get('composer_requires', []);
        $this->assertSame('*', $requires['firevel/filterable'] ?? null);
    }

    /** @test */
    public function no_filterable_fields_does_not_register_composer_require()
    {
        $handler = $this->runGenerator(SchemaHandler::class, [
            'name' => 'post',
            'fields' => [
                ['name' => 'title', 'type' => 'string'],
            ],
            'relationships' => [],
        ]);

        $requires = $handler->context()->get('composer_requires', []);
        $this->assertArrayNotHasKey('firevel/filterable', $requires);
    }

    /** @test */
    public function random_id_field_emits_bigint_primary_migration()
    {
        $output = $this->runHandler([
            'name' => 'post',
            'fields' => [
                ['name' => 'id', 'type' => 'random-id'],
            ],
            'relationships' => [],
        ]);

        $this->assertSame(
            [
                'bigInteger' => 'id',
                'unsigned' => null,
                'primary' => null,
            ],
            $output['migrations']['create'][0],
            'random-id should emit bigInteger+unsigned+primary with no nullable/autoIncrement'
        );
    }

    /** @test */
    public function random_id_field_adds_has_random_id_trait()
    {
        $output = $this->runHandler([
            'name' => 'post',
            'fields' => [
                ['name' => 'id', 'type' => 'random-id'],
            ],
            'relationships' => [],
        ]);

        $this->assertArrayHasKey('HasRandomId', $output['model']['use']);
        $this->assertSame(
            'Firevel\ModelRandomId\HasRandomId',
            $output['model']['use']['HasRandomId']
        );
    }

    /** @test */
    public function random_id_field_registers_firevel_model_random_id_composer_require()
    {
        $handler = $this->runGenerator(SchemaHandler::class, [
            'name' => 'post',
            'fields' => [
                ['name' => 'id', 'type' => 'random-id'],
            ],
            'relationships' => [],
        ]);

        $requires = $handler->context()->get('composer_requires', []);
        $this->assertSame('*', $requires['firevel/model-random-id'] ?? null);
    }

    /** @test */
    public function no_random_id_field_does_not_register_composer_require()
    {
        $handler = $this->runGenerator(SchemaHandler::class, [
            'name' => 'post',
            'fields' => [
                ['name' => 'id', 'type' => 'increments'],
            ],
            'relationships' => [],
        ]);

        $requires = $handler->context()->get('composer_requires', []);
        $this->assertArrayNotHasKey('firevel/model-random-id', $requires);
    }

    /** @test */
    public function random_id_filterable_maps_to_id()
    {
        $output = $this->runHandler([
            'name' => 'post',
            'fields' => [
                ['name' => 'id', 'type' => 'random-id', 'filterable' => true],
            ],
            'relationships' => [],
        ]);

        $this->assertSame('id', $output['model']['filterable']['id']);
    }

    /** @test */
    public function random_id_creatable_field_emits_integer_rule()
    {
        $output = $this->runHandler([
            'name' => 'post',
            'fields' => [
                ['name' => 'id', 'type' => 'random-id', 'creatable' => true],
            ],
            'relationships' => [],
        ]);

        $this->assertSame('integer', $output['requests']['store']['rules']['id']);
    }

    /** @test */
    public function filterable_field_with_unknown_type_does_not_register_composer_require()
    {
        // A filterable field whose type has no mapping is skipped — no require should land.
        $handler = $this->runGenerator(SchemaHandler::class, [
            'name' => 'post',
            'fields' => [
                ['name' => 'mystery', 'type' => 'unknown_type', 'filterable' => true],
            ],
            'relationships' => [],
        ]);

        $requires = $handler->context()->get('composer_requires', []);
        $this->assertArrayNotHasKey('firevel/filterable', $requires);
    }

    /** @test */
    public function casts_maps_basic_scalar_and_structured_types()
    {
        $output = $this->runHandler([
            'name' => 'post',
            'fields' => [
                ['name' => 'views', 'type' => 'integer'],
                ['name' => 'price', 'type' => 'decimal'],
                ['name' => 'rating', 'type' => 'float'],
                ['name' => 'active', 'type' => 'boolean'],
                ['name' => 'published_on', 'type' => 'date'],
                ['name' => 'published_at', 'type' => 'datetime'],
                ['name' => 'synced_at', 'type' => 'timestamp'],
                ['name' => 'meta', 'type' => 'object'],
                ['name' => 'tags', 'type' => 'array'],
            ],
            'relationships' => [],
        ]);

        $this->assertSame([
            'views' => 'integer',
            'price' => 'decimal:2',
            'rating' => 'float',
            'active' => 'boolean',
            'published_on' => 'date',
            'published_at' => 'datetime',
            'synced_at' => 'datetime',
            'meta' => 'object',
            'tags' => 'array',
        ], $output['model']['casts']);
    }

    /** @test */
    public function casts_leaves_keys_ids_and_freeform_strings_uncast()
    {
        // increments / random-id / id / uuid / string / text / enum are intentionally not cast.
        $output = $this->runHandler([
            'name' => 'post',
            'fields' => [
                ['name' => 'id', 'type' => 'increments'],
                ['name' => 'token', 'type' => 'random-id'],
                ['name' => 'user_id', 'type' => 'id'],
                ['name' => 'public_id', 'type' => 'uuid'],
                ['name' => 'title', 'type' => 'string'],
                ['name' => 'body', 'type' => 'text'],
                ['name' => 'status', 'type' => 'enum'],
            ],
            'relationships' => [],
        ]);

        $this->assertSame([], $output['model']['casts']);
    }

    /** @test */
    public function casts_skips_framework_managed_timestamp_columns()
    {
        // Eloquent already casts created_at / updated_at / deleted_at.
        $output = $this->runHandler([
            'name' => 'post',
            'fields' => [
                ['name' => 'created_at', 'type' => 'timestamp'],
                ['name' => 'updated_at', 'type' => 'timestamp'],
                ['name' => 'deleted_at', 'type' => 'datetime'],
                ['name' => 'reviewed_at', 'type' => 'timestamp'],
            ],
            'relationships' => [],
        ]);

        $this->assertSame(['reviewed_at' => 'datetime'], $output['model']['casts']);
    }

    /** @test */
    public function casts_is_empty_array_when_no_castable_fields()
    {
        $output = $this->runHandler([
            'name' => 'post',
            'fields' => [
                ['name' => 'title', 'type' => 'string'],
            ],
            'relationships' => [],
        ]);

        $this->assertSame([], $output['model']['casts']);
    }

    /** @test */
    public function casts_skips_fields_with_unknown_type()
    {
        $output = $this->runHandler([
            'name' => 'post',
            'fields' => [
                ['name' => 'mystery', 'type' => 'unknown_type'],
                ['name' => 'active', 'type' => 'boolean'],
            ],
            'relationships' => [],
        ]);

        $this->assertSame(['active' => 'boolean'], $output['model']['casts']);
    }
}
