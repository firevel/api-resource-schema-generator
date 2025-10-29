<?php

namespace Firevel\ApiResourceSchemaGenerator;

use Firevel\Generator\Generators\BaseGenerator;
use Firevel\Generator\Resource;
use Illuminate\Support\Str;

class SchemaHandler extends BaseGenerator
{
    public function handle()
    {
        $resource = $this->resource();

        $resource->output = [
            'name' => $resource->name,
            'model' => [],
        ];
        $this->addFillables($resource);
        $this->addDefaults($resource);
        $this->addSortable($resource);
        $this->addTransformer($resource);
        $this->addFilterables($resource);
        $this->addMigrations($resource);
        $this->addRequests($resource);
        $this->addRelationships($resource);
    }

    public function addFillables($resource)
    {
        $output = $resource->output;
        $output['model']['fillable'] = [];
        foreach ($resource->fields as $field) {
            if (!empty($field['fillable'])) {
                $output['model']['fillable'][] = $field['name'];
            }
        }
        $resource->output = $output;
        return $resource;
    }

    public function addDefaults($resource)
    {
        $output = $resource->output;
        $output['model']['attributes'] = [];
        foreach ($resource->fields as $field) {
            if (!empty($field['default'])) {
                $output['model']['attributes'][$field['name']] = $field['default'];
            }
        }
        $resource->output = $output;
        return $resource;
    }

    public function addSortable($resource)
    {
        $output = $resource->output;
        $output['model']['sortable'] = [];
        foreach ($resource->fields as $field) {
            if (!empty($field['sortable'])) {
                $output['model']['sortable'][] = $field['name'];
            }
        }
        $resource->output = $output;
        return $resource;
    }

    public function addTransformer($resource)
    {
        $output = $resource->output;
        $output['transformer'] = ['transform' => []];
        foreach ($resource->fields as $field) {
            if (!empty($field['transform'])) {
                $output['transformer']['transform'][$field['name']] = $field['name'];
            }
        }
        if (!empty($resource->relationships)) {
            $availableIncludes = [];

            foreach ($resource->relationships as $field) {
                if (!empty($field['transform'])) {
                    if (empty($field['transformer'])) {
                        $tranformerName = Str::studly(Str::singular($field['name'])) . 'Transformer';
                    } else {
                        $tranformerName = $field['transformer'];
                    }
                    $availableIncludes[Str::kebab($field['name'])] = $tranformerName;
                }
            }
            if (count($availableIncludes) > 0) {
                $output['transformer']['availableIncludes'] = $availableIncludes;
            }
        }

        $resource->output = $output;
        return $resource;
    }

    public function addFilterables($resource)
    {
        $output = $resource->output;
        $output['model']['filterable'] = [];
        foreach ($resource->fields as $field) {
            if (!empty($field['filterable'])) {
                $output['model']['filterable'][$field['name']] = $this->getFilterableByType($field['type']);
            }
        }
        $resource->output = $output;
        return $resource;
    }

    public function addMigrations($resource)
    {
        $output = $resource->output;
        $output['migrations'] = ['create' => []];
        foreach ($resource->fields as $field) {
            $output['migrations']['create'][] = $this->getMigrationByField($field);
        }
        $resource->output = $output;
        return $resource;
    }

    public function getMigrationByField($field)
    {
        $migration = [];
        $autoSet = false;
        switch ($field['type']) {
            case 'increments':
                $autoSet = true;
                $migration['increments'] = $field['name'];
                unset($migration['nullable']);
                break;
            case 'id':
                $migration['bigInteger'] = $field['name'];
                $migration['unsigned'] = null;
                break;
            case 'integer':
            case 'decimal':
            case 'date':
            case 'uuid':
            case 'string':
            case 'text':
            case 'boolean':
            case 'json':
            case 'timestamp':
                $migration[$field['type']] = $field['name'];
                break;
            case 'enum':
                $migration['string'] = $field['name'];
                break;
            case 'object':
                $migration['json'] = $field['name'];
                break;
            case 'datetime':
                $migration['dateTime'] = $field['name'];
                break;
            case 'array':
                $migration['json'] = $field['name'];
                break;
        }
        if (empty($field['required']) && !$autoSet) {
            $migration['nullable'] = null;
        }
        if (!empty($field['index'])) {
            switch ($field['index']) {
                case 'index':
                    $migration['index'] = null;
                    break;
                case 'primary':
                    unset($migration['nullable']);
                    $migration['primary'] = null;
                    break;
                case 'auto-increment':
                    unset($migration['nullable']);
                    $migration['autoIncrement'] = null;
                    break;
                case 'unique':
                    $migration['unique'] = null;
                    break;
            }
        }

        return $migration;
    }

    public function addRequests($resource)
    {
        $types = [
            'increments' => 'integer',
            'integer' => 'integer',
            'decimal' => 'numeric',
            'date' => 'date',
            'datetime' => 'date',
            'timestamp' => 'date',
            'id' => 'integer',
            'uuid' => 'uuid',
            'string' => 'string',
            'enum' => 'string',
            'text' => 'string',
            'boolean' => 'boolean',
            'json' => 'string',
            'object' => 'array',
            'array' => 'string',
        ];

        $output = $resource->output;
        if (empty($output['requests'])) {
            $output['requests'] = [
                'store' => ['rules' => []],
                'update' => ['rules' => []]
            ];
        }

        foreach ($resource->fields as $field) {
            if (!empty($field['creatable'])) {
                $rule = $types[$field['type']];
                if (! empty($field['required'])) {
                    $rule .= "|required";
                }
                $output['requests']['store']['rules'][$field['name']] = $rule;
            }
            if (!empty($field['editable'])) {
                $rule = $types[$field['type']];
                if (empty($field['required'])) {
                    $rule .= "|nullable";
                }
                $output['requests']['update']['rules'][$field['name']] = $rule;
            }
        }
        $resource->output = $output;
    }

    public function addRelationships($resource)
    {
        $output = $resource->output;
        if (empty($resource->relationships)) {
            return;
        }
        $output['model']['relationships'] = [];

        foreach ($resource->relationships as $field) {
            $name = Str::camel($field['name']);
            switch ($field['type']) {
                case 'belongsToMany':
                    $related = $field['related'] ?? Str::studly(Str::singular($field['name'])) . '::class';
                    $output['model']['relationships'][$name] = [$field['type'] => [$related]];
                    break;
                case 'morphMany':
                    $output['model']['relationships'][$name] = [$field['related'], $field['field']];
                    break;
                case 'morphTo':
                case 'belongsTo':
                case 'hasMany':
                    $output['model']['relationships'][$name] = $field['type'];
                    break;
            }
        }
        $resource->output = $output;
        return $resource;
    }


    public function getFilterableByType($type)
    {
        $types = [
            'increments' => 'id',
            'integer' => 'integer',
            'decimal' => 'integer',
            'date' => 'date',
            'datetime' => 'datetime',
            'timestamp' => 'datetime',
            'id' => 'id',
            'uuid' => 'id',
            'string' => 'string',
            'enum' => 'string',
            'text' => 'string',
            'boolean' => 'boolean',
            'json' => 'json',
            'object' => 'json',
            'array' => 'array',
        ];
        if (empty($types[$type])) {
            throw new \Exception("Missing match for filterable " . $type);
        }
        return $types[$type];
    }
}
