<?php

use Langsys\OpenApiDocsGenerator\Generators\DtoSchemaBuilder;
use Langsys\OpenApiDocsGenerator\Generators\ExampleGenerator;
use OpenApi\Annotations as OA;
use OpenApi\Generator;

beforeEach(function () {
    $packageRoot = dirname(__DIR__, 2);

    $this->builder = new DtoSchemaBuilder(
        dtoPaths: $packageRoot . '/tests/Data',
        exampleGenerator: new ExampleGenerator(
            fakerAttributeMapper: [],
            customFunctions: [],
        ),
        paginationFields: [],
    );
});

test('buildAll returns an array of OA\Schema objects', function () {
    $schemas = $this->builder->buildAll();

    expect($schemas)->toBeArray()
        ->and($schemas)->not->toBeEmpty();

    foreach ($schemas as $schema) {
        expect($schema)->toBeInstanceOf(OA\Schema::class);
    }
});

test('it generates a schema for ExampleData', function () {
    $schemas = $this->builder->buildAll();
    $schemaNames = array_map(fn (OA\Schema $s) => $s->schema, $schemas);

    expect($schemaNames)->toContain('ExampleData');

    $exampleSchema = collect($schemas)->first(fn (OA\Schema $s) => $s->schema === 'ExampleData');
    $properties = $exampleSchema->properties;

    expect($properties)->not->toBe(Generator::UNDEFINED)
        ->and($properties)->toHaveCount(1);

    $prop = $properties[0];
    expect($prop->property)->toBe('example')
        ->and($prop->type)->toBe('string')
        ->and($prop->example)->toBe('test');
});

test('it generates a schema for TestData with correct properties', function () {
    $schemas = $this->builder->buildAll();
    $testSchema = collect($schemas)->first(fn (OA\Schema $s) => $s->schema === 'TestData');

    expect($testSchema)->not->toBeNull();

    $properties = $testSchema->properties;
    expect($properties)->not->toBe(Generator::UNDEFINED);

    $propNames = array_map(fn (OA\Property $p) => $p->property, $properties);

    expect($propNames)->toContain('id')
        ->and($propNames)->toContain('another_id')
        ->and($propNames)->toContain('collection')
        ->and($propNames)->toContain('array')
        ->and($propNames)->toContain('default_string')
        ->and($propNames)->toContain('default_int')
        ->and($propNames)->toContain('default_bool')
        ->and($propNames)->toContain('enum');
});

test('it sets correct types for properties', function () {
    $schemas = $this->builder->buildAll();
    $testSchema = collect($schemas)->first(fn (OA\Schema $s) => $s->schema === 'TestData');
    $properties = collect($testSchema->properties);

    $idProp = $properties->first(fn (OA\Property $p) => $p->property === 'id');
    expect($idProp->type)->toBe('integer')
        ->and($idProp->example)->toBe(468);

    $stringProp = $properties->first(fn (OA\Property $p) => $p->property === 'another_id');
    expect($stringProp->type)->toBe('string')
        ->and($stringProp->example)->toBe('368c23fe-ae9c-4052-9f8c-0bb5622cf3ca');
});

test('it handles default values', function () {
    $schemas = $this->builder->buildAll();
    $testSchema = collect($schemas)->first(fn (OA\Schema $s) => $s->schema === 'TestData');
    $properties = collect($testSchema->properties);

    $defaultString = $properties->first(fn (OA\Property $p) => $p->property === 'default_string');
    expect($defaultString->default)->toBe('defaultString')
        ->and($defaultString->example)->toBe('A String');

    $defaultInt = $properties->first(fn (OA\Property $p) => $p->property === 'default_int');
    expect($defaultInt->default)->toBe(3);

    $defaultBool = $properties->first(fn (OA\Property $p) => $p->property === 'default_bool');
    expect($defaultBool->default)->toBe(true);
});

test('it handles enum properties', function () {
    $schemas = $this->builder->buildAll();
    $testSchema = collect($schemas)->first(fn (OA\Schema $s) => $s->schema === 'TestData');
    $properties = collect($testSchema->properties);

    $enumProp = $properties->first(fn (OA\Property $p) => $p->property === 'enum');
    expect($enumProp)->not->toBeNull()
        ->and($enumProp->enum)->not->toBe(Generator::UNDEFINED)
        ->and($enumProp->enum)->toContain('case1')
        ->and($enumProp->enum)->toContain('case2')
        ->and($enumProp->example)->toBe('case2')
        ->and($enumProp->default)->toBe('case1');
});

test('it handles nullable enum properties with null default', function () {
    $schemas = $this->builder->buildAll();
    $testSchema = collect($schemas)->first(fn (OA\Schema $s) => $s->schema === 'TestData');
    $properties = collect($testSchema->properties);

    $nullableEnumProp = $properties->first(fn (OA\Property $p) => $p->property === 'nullable_enum');
    expect($nullableEnumProp)->not->toBeNull()
        ->and($nullableEnumProp->enum)->not->toBe(Generator::UNDEFINED)
        ->and($nullableEnumProp->enum)->toContain('case1')
        ->and($nullableEnumProp->enum)->toContain('case2')
        ->and($nullableEnumProp->default)->toBeNull()
        ->and($nullableEnumProp->nullable)->toBeTrue();
});

test('it handles array properties', function () {
    $schemas = $this->builder->buildAll();
    $testSchema = collect($schemas)->first(fn (OA\Schema $s) => $s->schema === 'TestData');
    $properties = collect($testSchema->properties);

    $arrayProp = $properties->first(fn (OA\Property $p) => $p->property === 'array');
    expect($arrayProp->type)->toBe('array');
});

// -------------------------------------------------------------------------
// Laravel Data v4 collection patterns
// -------------------------------------------------------------------------

test('v4: array with @var ClassName[] docblock generates typed array schema', function () {
    $schemas = $this->builder->buildAll();
    $schema = collect($schemas)->first(fn (OA\Schema $s) => $s->schema === 'TestDataV4');

    expect($schema)->not->toBeNull();

    $prop = collect($schema->properties)->first(fn (OA\Property $p) => $p->property === 'items');
    expect($prop->type)->toBe('array')
        ->and($prop->items)->toBeInstanceOf(OA\Items::class)
        ->and($prop->items->allOf)->toBeArray()
        ->and($prop->items->allOf[0])->toBeInstanceOf(OA\Schema::class)
        ->and($prop->items->allOf[0]->ref)->toBe('#/components/schemas/ExampleData');
});

test('v4: Collection with @var Collection<int, ClassName> docblock generates typed array schema', function () {
    $schemas = $this->builder->buildAll();
    $schema = collect($schemas)->first(fn (OA\Schema $s) => $s->schema === 'TestDataV4');

    $prop = collect($schema->properties)->first(fn (OA\Property $p) => $p->property === 'collection_items');
    expect($prop->type)->toBe('array')
        ->and($prop->items)->toBeInstanceOf(OA\Items::class)
        ->and($prop->items->allOf)->toBeArray()
        ->and($prop->items->allOf[0]->ref)->toBe('#/components/schemas/ExampleData');
});

test('v4: grouped collection with docblock generates object with additionalProperties', function () {
    $schemas = $this->builder->buildAll();
    $schema = collect($schemas)->first(fn (OA\Schema $s) => $s->schema === 'TestDataV4');

    $prop = collect($schema->properties)->first(fn (OA\Property $p) => $p->property === 'grouped_items');
    expect($prop->type)->toBe('object')
        ->and($prop->additionalProperties)->toBeInstanceOf(OA\AdditionalProperties::class)
        ->and($prop->additionalProperties->type)->toBe('array')
        ->and($prop->additionalProperties->items->ref)->toBe('#/components/schemas/ExampleData');
});

test('v3: DataCollection with DataCollectionOf still works (backward compat)', function () {
    $schemas = $this->builder->buildAll();
    $testSchema = collect($schemas)->first(fn (OA\Schema $s) => $s->schema === 'TestData');
    $properties = collect($testSchema->properties);

    $prop = $properties->first(fn (OA\Property $p) => $p->property === 'grouped_collection');
    expect($prop->type)->toBe('object')
        ->and($prop->additionalProperties)->toBeInstanceOf(OA\AdditionalProperties::class)
        ->and($prop->additionalProperties->type)->toBe('array')
        ->and($prop->additionalProperties->items->ref)->toBe('#/components/schemas/ExampleData');
});
