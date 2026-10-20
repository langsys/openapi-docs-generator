<?php

use Illuminate\Support\Str;
use Langsys\OpenApiDocsGenerator\Functions\CustomFunctions;
use Langsys\OpenApiDocsGenerator\Exceptions\OpenApiDocsException;
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

// -------------------------------------------------------------------------
// Spatie Laravel Data Optional unions (T|Optional)
// -------------------------------------------------------------------------

test('Optional union uses underlying type and omits property from request required', function () {
    $schemas = $this->builder->buildAll();
    $schema = collect($schemas)->first(fn (OA\Schema $s) => $s->schema === 'OptionalUnionTestRequest');

    expect($schema)->not->toBeNull()
        ->and($schema->required)->toBe(['required_field']);

    $props = collect($schema->properties);

    $artist = $props->first(fn (OA\Property $p) => $p->property === 'artist');
    expect($artist->type)->toBe('string');

    $title = $props->first(fn (OA\Property $p) => $p->property === 'title_reversed_union');
    expect($title->type)->toBe('string');

    $count = $props->first(fn (OA\Property $p) => $p->property === 'count');
    expect($count->type)->toBe('integer');

    $status = $props->first(fn (OA\Property $p) => $p->property === 'status');
    expect($status->type)->toBe('string')
        ->and($status->enum)->not->toBe(Generator::UNDEFINED)
        ->and($status->default)->toBe('case1');
});

// -------------------------------------------------------------------------
// Formats from laravel-data validation attributes
// -------------------------------------------------------------------------

test('a string property with #[Uuid], #[Date], #[Email] or #[Url] gets that format', function () {
    $schemas = $this->builder->buildAll();
    $schema = collect($schemas)->first(fn (OA\Schema $s) => $s->schema === 'FormatTestData');
    $props = collect($schema->properties)->keyBy(fn (OA\Property $p) => $p->property);

    expect($props['organization_id']->format)->toBe('uuid')
        ->and($props['start_date']->format)->toBe('date')
        ->and($props['email']->format)->toBe('email')
        ->and($props['website_url']->format)->toBe('uri')
        ->and($props['name']->format)->toBe(Generator::UNDEFINED);
});

test('#[DateFormat] is a date for Y-m-d, a date-time for RFC 3339, and nothing for another format or a mix', function () {
    $schemas = $this->builder->buildAll();
    $schema = collect($schemas)->first(fn (OA\Schema $s) => $s->schema === 'FormatTestData');
    $props = collect($schema->properties)->keyBy(fn (OA\Property $p) => $p->property);

    expect($props['day']->format)->toBe('date')
        ->and($props['stamp']->format)->toBe('date-time')
        ->and($props['precise_stamp']->format)->toBe('date-time')
        ->and($props['either_stamp']->format)->toBe('date-time')
        ->and($props['day_or_stamp']->format)->toBe(Generator::UNDEFINED)
        ->and($props['c_stamp']->format)->toBe(Generator::UNDEFINED) // date_format:c accepts nothing
        ->and($props['local_day']->format)->toBe(Generator::UNDEFINED);
});

test('#[DateFormat] decides over #[Date] in either order', function () {
    $schemas = $this->builder->buildAll();
    $schema = collect($schemas)->first(fn (OA\Schema $s) => $s->schema === 'FormatTestData');
    $props = collect($schema->properties)->keyBy(fn (OA\Property $p) => $p->property);

    expect($props['date_then_local_day']->format)->toBe(Generator::UNDEFINED)
        ->and($props['stamp_then_date']->format)->toBe('date-time');
});

test('a generated example fits the format, a declared one is used as written', function () {
    // The package's default hints and custom functions: `_at` makes a "Y-m-d H:i:s" date-time.
    $builder = new DtoSchemaBuilder(
        dtoPaths: dirname(__DIR__, 2) . '/tests/Data',
        exampleGenerator: new ExampleGenerator(
            fakerAttributeMapper: ['_at' => 'date', '_url' => 'url', '_id' => 'id'],
            customFunctions: ['id' => [CustomFunctions::class, 'id'], 'date' => [CustomFunctions::class, 'date']],
        ),
        paginationFields: [],
    );
    $schema = collect($builder->buildAll())->first(fn (OA\Schema $s) => $s->schema === 'FormatTestData');
    $props = collect($schema->properties)->keyBy(fn (OA\Property $p) => $p->property);
    // As Laravel's date_format checks: parsed and formatted back unchanged.
    $fits = fn (string $property, string $format): bool => ($date = DateTime::createFromFormat('!' . $format, $props[$property]->example)) !== false
        && $date->format($format) === $props[$property]->example;

    expect(Str::isUuid($props['organization_id']->example))->toBeTrue()
        ->and($fits('start_date', 'Y-m-d'))->toBeTrue()
        ->and($fits('published_at', 'Y-m-d'))->toBeTrue()
        ->and($fits('day', 'Y-m-d'))->toBeTrue()
        ->and($fits('hinted_day', 'Y-m-d'))->toBeTrue() // #[Example(':date')] runs the custom `date` too
        ->and($fits('stamp', DATE_ATOM))->toBeTrue()
        ->and($fits('precise_stamp', DATE_RFC3339_EXTENDED))->toBeTrue()
        ->and($fits('stamp_then_date', DATE_ATOM))->toBeTrue()
        ->and(filter_var($props['email']->example, FILTER_VALIDATE_EMAIL))->not->toBeFalse()
        ->and(filter_var($props['website_url']->example, FILTER_VALIDATE_URL))->not->toBeFalse()
        ->and($props['legacy_ref']->example)->toBe('legacy-ref');
});

test('an array or a date-time property keeps its own format', function () {
    $schemas = $this->builder->buildAll();
    $schema = collect($schemas)->first(fn (OA\Schema $s) => $s->schema === 'FormatTestData');
    $props = collect($schema->properties)->keyBy(fn (OA\Property $p) => $p->property);

    expect($props['ids']->format)->toBe(Generator::UNDEFINED)
        ->and(json_encode($props['ids']))->not->toContain('format')
        ->and($props['created_at']->format)->toBe('date-time');
});

// -------------------------------------------------------------------------
// DateTime / Carbon support
// -------------------------------------------------------------------------

test('Carbon properties are rendered as string with date-time format', function () {
    $schemas = $this->builder->buildAll();
    $schema = collect($schemas)->first(fn (OA\Schema $s) => $s->schema === 'DateTimeTestData');

    expect($schema)->not->toBeNull();

    $props = collect($schema->properties);

    $createdAt = $props->first(fn (OA\Property $p) => $p->property === 'created_at');
    expect($createdAt->type)->toBe('string')
        ->and($createdAt->format)->toBe('date-time');

    $publishedAt = $props->first(fn (OA\Property $p) => $p->property === 'published_at');
    expect($publishedAt->type)->toBe('string')
        ->and($publishedAt->format)->toBe('date-time');

    $legacyDate = $props->first(fn (OA\Property $p) => $p->property === 'legacy_date');
    expect($legacyDate->type)->toBe('string')
        ->and($legacyDate->format)->toBe('date-time');
});

test('nullable Carbon property sets nullable true', function () {
    $schemas = $this->builder->buildAll();
    $schema = collect($schemas)->first(fn (OA\Schema $s) => $s->schema === 'DateTimeTestData');
    $props = collect($schema->properties);

    $deletedAt = $props->first(fn (OA\Property $p) => $p->property === 'deleted_at');
    expect($deletedAt->type)->toBe('string')
        ->and($deletedAt->format)->toBe('date-time')
        ->and($deletedAt->nullable)->toBeTrue();
});

test('DateTime property with explicit Example uses that value', function () {
    $schemas = $this->builder->buildAll();
    $schema = collect($schemas)->first(fn (OA\Schema $s) => $s->schema === 'DateTimeTestData');
    $props = collect($schema->properties);

    $withExample = $props->first(fn (OA\Property $p) => $p->property === 'with_example');
    expect($withExample->example)->toBe('2025-06-01T00:00:00+00:00');
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

// -------------------------------------------------------------------------
// Properties with default values are not marked required (regardless of
// nullability); nullable: true only fires for actually-nullable types.
// -------------------------------------------------------------------------

test('non-nullable property with default is omitted from required[] and not flagged nullable', function () {
    $schemas = $this->builder->buildAll();
    $schema = collect($schemas)->first(fn (OA\Schema $s) => $s->schema === 'DefaultedRequest');

    expect($schema)->not->toBeNull()
        ->and($schema->required)->toBe(['name']);

    $props = collect($schema->properties);

    $status = $props->first(fn (OA\Property $p) => $p->property === 'status');
    expect($status->default)->toBe('case1')
        ->and($status->nullable)->toBe(Generator::UNDEFINED);

    $active = $props->first(fn (OA\Property $p) => $p->property === 'active');
    expect($active->default)->toBe(true)
        ->and($active->nullable)->toBe(Generator::UNDEFINED);
});

// -------------------------------------------------------------------------
// A property whose type allows null is nullable, whatever its shape, and an
// example nothing produced still has a value.
// -------------------------------------------------------------------------

test('a property whose type allows null is nullable, whatever its shape', function () {
    $schema = collect($this->builder->buildAll())->first(fn (OA\Schema $s) => $s->schema === 'NullableRequest');
    $props = json_decode(json_encode($schema), true)['properties'];

    foreach (['code_verifier', 'retry_count', 'ratio', 'gate', 'ip_allowlist', 'device_info', 'order_by', 'handoff', 'example', 'examples', 'payload'] as $name) {
        expect($props[$name]['nullable'] ?? null)->toBeTrue("{$name} should be nullable");
    }

    expect($props['name'])->not->toHaveKey('nullable')
        ->and($props['device_info']['type'])->toBe('object')
        ->and($props['order_by']['type'])->toBe('array')
        ->and($props['example']['allOf'][0]['$ref'])->toBe('#/components/schemas/ExampleData')
        ->and($props['examples']['items']['allOf'][0]['$ref'])->toBe('#/components/schemas/ExampleData');
});

test('a nullable union is not required, like any nullable property', function () {
    $schema = collect($this->builder->buildAll())->first(fn (OA\Schema $s) => $s->schema === 'NullableRequest');

    expect($schema->required)->toBe(['name']);
});

test('an example no Faker function or hint produces is never empty', function () {
    $schema = collect($this->builder->buildAll())->first(fn (OA\Schema $s) => $s->schema === 'NullableRequest');
    $props = json_decode(json_encode($schema), true)['properties'];

    expect($props['code_verifier']['example'])->toBeString()->not->toBeEmpty()
        ->and($props['handoff']['example'])->toBeString()->not->toBeEmpty()
        ->and($props['payload']['example'])->toBeString()->not->toBeEmpty()
        ->and($props['ip_allowlist']['items'])->toMatchArray(['type' => 'string'])
        ->and($props['ip_allowlist']['items']['example'])->toBeString()->not->toBeEmpty()
        ->and($props['order_by']['items']['example'])->toBeString()->not->toBeEmpty()
        ->and($props['retry_count']['example'])->toBe(0)
        ->and($props['gate']['example'])->toBeBool();
});

test('an array #[Example] is an object\'s example, and a list\'s, whose items take its first element', function () {
    $schema = collect($this->builder->buildAll())->first(fn (OA\Schema $s) => $s->schema === 'ArrayExampleData');
    $props = json_decode(json_encode($schema), true)['properties'];

    expect($props['device_info'])->toBe([
        'type' => 'object',
        'example' => ['browser' => 'Safari 18.1', 'os' => 'macOS 15.1'],
    ])
        ->and($props['ip_allowlist'])->toMatchArray([
            'type' => 'array',
            'items' => ['type' => 'string', 'example' => '203.0.113.0/24'],
            'example' => ['203.0.113.0/24', '198.51.100.7'],
        ])
        ->and($props['weights']['items'])->toBe(['type' => 'number', 'example' => 1.5])
        ->and($props['entries']['items'])->toBe(['type' => 'object', 'example' => ['id' => 'a1']])
        ->and($props['tags']['example'])->toBe([])
        ->and($props['tags']['items'])->toBe(['type' => 'string'])
        ->and($props['ranges']['items'])->toBe(['type' => 'string', 'example' => '203.0.113.0/24'])
        ->and($props['ranges'])->not->toHaveKey('example');
});

/**
 * Build a Data class with one promoted property, written into a temp dir, so an invalid
 * one never reaches the shared fixtures.
 */
function buildArrayExampleFixture(string $class, string $property): void
{
    $dir = sys_get_temp_dir() . '/openapi-array-example-' . uniqid();
    mkdir($dir);
    file_put_contents($dir . "/{$class}.php", <<<PHP
<?php
namespace ArrayExampleFixture;
use Langsys\\OpenApiDocsGenerator\\Generators\\Attributes\\Example;
use Spatie\\LaravelData\\Data;
class {$class} extends Data
{
    public function __construct(
        {$property},
    ) {}
}
PHP);
    require_once $dir . "/{$class}.php";

    try {
        (new DtoSchemaBuilder($dir, new ExampleGenerator([], []), []))->buildAll();
    } finally {
        array_map('unlink', glob($dir . '/*'));
        rmdir($dir);
    }
}

describe('an array #[Example] that does not fit the property fails generation', function () {
    test('on a property that is not a plain array', function () {
        expect(fn () => buildArrayExampleFixture('ScalarArrayExample', "#[Example(['a', 'b'])] public string \$name"))
            ->toThrow(OpenApiDocsException::class, '#[Example] on ArrayExampleFixture\\ScalarArrayExample::$name is an array, which only a plain array property takes');
    });

    test('with keys, on a list', function () {
        expect(fn () => buildArrayExampleFixture('KeyedListExample', "#[Example(['a' => 1])] public array \$options"))
            ->toThrow(OpenApiDocsException::class, '#[Example] on ArrayExampleFixture\\KeyedListExample::$options has keys, but the property is documented as a list');
    });

    test('as a list, on a string map', function () {
        expect(fn () => buildArrayExampleFixture('ListMapExample', "/** @var array<string, mixed> */ #[Example(['a', 'b'])] public array \$device_info"))
            ->toThrow(OpenApiDocsException::class, '#[Example] on ArrayExampleFixture\\ListMapExample::$device_info is a list, but the property is a string map');
    });

    test('but an empty one fits a string map', function () {
        expect(fn () => buildArrayExampleFixture('EmptyMapExample', "/** @var array<string, mixed> */ #[Example([])] public array \$device_info"))
            ->not->toThrow(OpenApiDocsException::class);
    });
});

// -------------------------------------------------------------------------
// #[ItemType] / #[OneOfItemsFrom] — polymorphic arrays of DTO variants
// -------------------------------------------------------------------------

test('OneOfItemsFrom property emits an array of oneOf wrapper item schemas', function () {
    $schemas = $this->builder->buildAll();
    $container = collect($schemas)->first(fn (OA\Schema $s) => $s->schema === 'BlockContainer');

    expect($container)->not->toBeNull();

    $contentProp = collect($container->properties)
        ->first(fn (OA\Property $p) => $p->property === 'content');

    expect($contentProp->type)->toBe('array')
        ->and($contentProp->items)->toBeInstanceOf(OA\Items::class);

    $oneOfRefs = array_map(fn (OA\Schema $s) => $s->ref, $contentProp->items->oneOf);

    expect($oneOfRefs)->toEqualCanonicalizing([
        '#/components/schemas/ImageItem',
        '#/components/schemas/ParagraphItem',
    ]);
});

test('ItemType emits a wrapper schema per variant with type/data shape', function () {
    $schemas = $this->builder->buildAll();
    $names = array_map(fn (OA\Schema $s) => $s->schema, $schemas);

    expect($names)->toContain('ParagraphItem')
        ->and($names)->toContain('ImageItem');

    $paragraphItem = collect($schemas)->first(fn (OA\Schema $s) => $s->schema === 'ParagraphItem');
    expect($paragraphItem->required)->toBe(['type', 'data']);

    $props = collect($paragraphItem->properties);
    $typeProp = $props->first(fn (OA\Property $p) => $p->property === 'type');
    expect($typeProp->type)->toBe('string')
        ->and($typeProp->enum)->toBe(['paragraph'])
        ->and($typeProp->example)->toBe('paragraph');

    $dataProp = $props->first(fn (OA\Property $p) => $p->property === 'data');
    expect($dataProp->allOf[0]->ref)->toBe('#/components/schemas/Paragraph');
});

test('ItemType handle defaults to snake_case of basename when omitted, custom handle wins when provided', function () {
    $schemas = $this->builder->buildAll();

    $paragraphItem = collect($schemas)->first(fn (OA\Schema $s) => $s->schema === 'ParagraphItem');
    $paragraphType = collect($paragraphItem->properties)
        ->first(fn (OA\Property $p) => $p->property === 'type');
    expect($paragraphType->enum)->toBe(['paragraph']);

    $imageItem = collect($schemas)->first(fn (OA\Schema $s) => $s->schema === 'ImageItem');
    $imageType = collect($imageItem->properties)
        ->first(fn (OA\Property $p) => $p->property === 'type');
    expect($imageType->enum)->toBe(['picture']);
});

test('abstract Data subclasses are skipped from auto-schema generation', function () {
    $schemas = $this->builder->buildAll();
    $names = array_map(fn (OA\Schema $s) => $s->schema, $schemas);

    expect($names)->not->toContain('AbstractBlock');
});

describe('string-keyed map docblocks', function () {
    it('emits array<string, T> docblock properties as object with additionalProperties', function () {
        $dir = sys_get_temp_dir() . '/openapi-map-' . uniqid();
        mkdir($dir);
        file_put_contents($dir . '/MapData.php', <<<'PHP'
<?php
namespace MapDocblockFixture;
use Spatie\LaravelData\Data;
class MapData extends Data
{
    public function __construct(
        /** @var array<string, string[]> */
        public array $messages,
        /** @var array<string, int> */
        public array $counts,
        /** @var array<string, mixed> */
        public array $meta,
        /** @var array<int, string> */
        public array $list,
        public array $plain,
    ) {}
}
PHP);
        require_once $dir . '/MapData.php';

        $builder = new DtoSchemaBuilder($dir, new ExampleGenerator([], []), []);
        $schema = json_decode(json_encode($builder->buildAll()[0]), true);
        $props = $schema['properties'];

        expect($props['messages'])->toMatchArray(['type' => 'object', 'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']]])
            ->and($props['counts'])->toMatchArray(['type' => 'object', 'additionalProperties' => ['type' => 'integer']])
            ->and($props['meta']['type'])->toBe('object')
            ->and($props['list']['type'])->toBe('array')
            ->and($props['plain']['type'])->toBe('array');

        array_map('unlink', glob($dir . '/*'));
        rmdir($dir);
    });
});
