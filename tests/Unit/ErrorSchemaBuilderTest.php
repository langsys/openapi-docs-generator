<?php

use Langsys\OpenApiDocsGenerator\Data\ErrorDefinition;
use Langsys\OpenApiDocsGenerator\Exceptions\OpenApiDocsException;
use Langsys\OpenApiDocsGenerator\Generators\DtoSchemaBuilder;
use Langsys\OpenApiDocsGenerator\Generators\ExampleGenerator;
use OpenApi\Generator;

function errorFixturesDir(): string
{
    return dirname(__DIR__) . '/ErrorFixtures';
}

function makeErrorBuilder(array $errorConfig = [], ?string $dir = null): DtoSchemaBuilder
{
    return new DtoSchemaBuilder($dir ?? errorFixturesDir(), new ExampleGenerator([], []), [], $errorConfig);
}

/** @return array<string, array> schema name => decoded JSON */
function schemasByName(DtoSchemaBuilder $builder): array
{
    $out = [];
    foreach ($builder->buildAll() as $schema) {
        $out[$schema->schema] = json_decode(json_encode($schema), true);
    }

    return $out;
}

function propsOf(array $schema): array
{
    $props = [];
    foreach ($schema['properties'] as $name => $prop) {
        $props[$name] = $prop;
    }

    return $props;
}

it('discovers error classes by #[ErrorCode] presence and records definitions', function () {
    $builder = makeErrorBuilder();
    $builder->buildAll();

    $defs = collect($builder->getErrorDefinitions())->keyBy('code');

    expect($defs)->toHaveCount(3)
        ->and($defs->keys()->sort()->values()->all())->toBe(['insufficient_balance', 'unauthenticated', 'validation_failed']);

    /** @var ErrorDefinition $balance */
    $balance = $defs['insufficient_balance'];
    expect($balance->schemaName)->toBe('InsufficientBalanceError')
        ->and($balance->responseSchemaName)->toBe('InsufficientBalanceErrorResponse')
        ->and($balance->status)->toBe(402)
        ->and($balance->message)->toBe('Insufficient balance to complete this request')
        ->and($balance->description)->toBe('The account balance cannot cover the requested operation.')
        ->and($balance->hasDetails)->toBeTrue();

    expect($builder->getErrorCodeSchemaName())->toBe('ErrorCode');
});

it('inherits #[HttpStatus] from a parent class', function () {
    $builder = makeErrorBuilder();
    $builder->buildAll();

    $unauth = collect($builder->getErrorDefinitions())->firstWhere('code', 'unauthenticated');

    expect($unauth->status)->toBe(401)
        ->and($unauth->hasDetails)->toBeFalse();
});

it('builds the details schema from non-envelope properties', function () {
    $schemas = schemasByName(makeErrorBuilder());

    expect($schemas)->toHaveKey('InsufficientBalanceError');
    $props = propsOf($schemas['InsufficientBalanceError']);
    expect(array_keys($props))->toBe(['required', 'available'])
        ->and($props['required']['type'])->toBe('integer');

    // ValidationError's only property is an envelope field, so no details schema.
    expect($schemas)->not->toHaveKey('ValidationError')
        ->and($schemas)->not->toHaveKey('UnauthenticatedError');
});

it('builds the {Name}Response envelope with the agreed shape', function () {
    $schemas = schemasByName(makeErrorBuilder());
    $envelope = $schemas['InsufficientBalanceErrorResponse'];
    $props = propsOf($envelope);

    expect(array_keys($props))->toBe(['status', 'data', 'error', 'code', 'details'])
        ->and($envelope['required'])->toBe(['status', 'error', 'code']);

    expect($props['status'])->toMatchArray(['type' => 'boolean', 'example' => false]);
    expect($props['data'])->toMatchArray(['type' => 'array', 'maxItems' => 0, 'items' => ['type' => 'object']])
        ->and($props['data'])->not->toHaveKey('default');
    expect($props['error'])->toMatchArray(['type' => 'string', 'example' => 'Insufficient balance to complete this request'])
        ->and($props['error'])->not->toHaveKey('default');
    expect($props['code'])->toMatchArray(['type' => 'string', 'enum' => ['insufficient_balance'], 'example' => 'insufficient_balance']);
    expect($props['details']['allOf'][0]['$ref'])->toBe('#/components/schemas/InsufficientBalanceError');
});

it('omits details when the class has no non-envelope properties and lifts #[EnvelopeField] props to the top level', function () {
    $schemas = schemasByName(makeErrorBuilder());
    $props = propsOf($schemas['ValidationErrorResponse']);

    expect(array_keys($props))->toBe(['status', 'data', 'error', 'code', 'errors'])
        ->and($props['errors']['type'])->toBe('object')
        ->and($props['errors']['additionalProperties'])->toBe(['type' => 'array', 'items' => ['type' => 'string']])
        ->and($props['errors']['description'])->toBe('Field name to list of validation messages')
        ->and($schemas['ValidationErrorResponse']['required'])->toBe(['status', 'error', 'code', 'errors']);

    expect(array_keys(propsOf($schemas['UnauthenticatedErrorResponse'])))->toBe(['status', 'data', 'error', 'code']);
});

it('builds the shared ErrorCode enum listing every code with its status and description', function () {
    $schemas = schemasByName(makeErrorBuilder());
    $enum = $schemas['ErrorCode'];

    expect($enum['type'])->toBe('string')
        ->and($enum['enum'])->toBe(['insufficient_balance', 'unauthenticated', 'validation_failed'])
        ->and($enum['description'])->toContain('`insufficient_balance` (HTTP 402): The account balance cannot cover the requested operation.')
        ->and($enum['description'])->toContain('`unauthenticated` (HTTP 401): Unauthenticated');
});

it('honours envelope field renames, omissions and the code schema name from config', function () {
    $schemas = schemasByName(makeErrorBuilder([
        'code_schema' => 'ApiErrorCode',
        'fields' => ['status' => 'ok', 'data' => null, 'error' => 'message', 'code' => 'code', 'details' => 'meta'],
    ]));

    $props = propsOf($schemas['InsufficientBalanceErrorResponse']);
    expect(array_keys($props))->toBe(['ok', 'message', 'code', 'meta'])
        ->and($schemas['InsufficientBalanceErrorResponse']['required'])->toBe(['ok', 'message', 'code'])
        ->and($schemas)->toHaveKey('ApiErrorCode')
        ->and($schemas)->not->toHaveKey('ErrorCode');
});

it('scans extra errors.paths in addition to the DTO paths', function () {
    $builder = new DtoSchemaBuilder(
        dirname(__DIR__) . '/Data',
        new ExampleGenerator([], []),
        [],
        ['paths' => [errorFixturesDir()]],
    );
    $names = array_map(fn ($s) => $s->schema, $builder->buildAll());

    expect($names)->toContain('InsufficientBalanceErrorResponse')
        ->and($names)->toContain('ErrorCode')
        ->and($names)->toContain('ExampleData'); // a regular DTO from tests/Data still builds
});

it('returns no error definitions or code schema when there are no error classes', function () {
    $builder = makeErrorBuilder([], dirname(__DIR__) . '/Data');
    $names = array_map(fn ($s) => $s->schema, $builder->buildAll());

    expect($builder->getErrorDefinitions())->toBe([])
        ->and($builder->getErrorCodeSchemaName())->toBeNull()
        ->and($names)->not->toContain('ErrorCode');
});

it('fails with a clear message when an error class lacks #[HttpStatus]', function () {
    makeErrorBuilder([], dirname(__DIR__) . '/ErrorFixturesInvalid')->buildAll();
})->throws(OpenApiDocsException::class, 'MissingStatusError carries #[ErrorCode(\'missing_status\')] but no #[HttpStatus]');
