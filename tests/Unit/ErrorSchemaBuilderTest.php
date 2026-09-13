<?php

use Langsys\OpenApiDocsGenerator\Data\ErrorDefinition;
use Langsys\OpenApiDocsGenerator\Exceptions\OpenApiDocsException;
use Langsys\OpenApiDocsGenerator\Generators\DtoSchemaBuilder;
use Langsys\OpenApiDocsGenerator\Generators\ExampleGenerator;
use Langsys\OpenApiDocsGenerator\Tests\ErrorFixtures\ApiError;

function errorFixturesDir(): string
{
    return dirname(__DIR__) . '/ErrorFixtures';
}

/**
 * @param  string|string[]|null  $dirs  Directories to scan; defaults to the error fixtures.
 */
function makeErrorBuilder(array $errorConfig = [], string|array|null $dirs = null): DtoSchemaBuilder
{
    return new DtoSchemaBuilder(
        $dirs ?? errorFixturesDir(),
        new ExampleGenerator([], []),
        [],
        array_merge(['base_class' => ApiError::class], $errorConfig),
    );
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

function jsonOf(?object $schema): ?array
{
    return $schema === null ? null : json_decode(json_encode($schema), true);
}

/** @return array<string, ErrorDefinition> code => definition, sorted by code */
function definitionsByCode(DtoSchemaBuilder $builder): array
{
    $builder->buildAll();

    $out = [];
    foreach ($builder->getErrorDefinitions() as $definition) {
        $out[$definition->code] = $definition;
    }
    ksort($out);

    return $out;
}

/**
 * Write one class declaration into its own temp directory, in a unique namespace,
 * and load it. The declaration can use ApiError, NotFoundError, Description and
 * EnvelopeField unqualified.
 */
function errorClassDir(string $declaration): string
{
    $dir = sys_get_temp_dir() . '/openapi-contract-' . str_replace('.', '', uniqid('', true));
    mkdir($dir);
    $namespace = 'ErrorContractFixture\\N' . str_replace('.', '', uniqid('', true));
    $file = $dir . '/Fixture.php';

    file_put_contents($file, implode("\n", [
        '<?php',
        "namespace {$namespace};",
        'use Langsys\\OpenApiDocsGenerator\\Generators\\Attributes\\Description;',
        'use Langsys\\OpenApiDocsGenerator\\Generators\\Attributes\\EnvelopeField;',
        'use Langsys\\OpenApiDocsGenerator\\Tests\\ErrorFixtures\\ApiError;',
        'use Langsys\\OpenApiDocsGenerator\\Tests\\ErrorFixtures\\NotFoundError;',
        '',
        $declaration,
        '',
    ]));
    require_once $file;

    return $dir;
}

function removeErrorClassDir(string $dir): void
{
    array_map('unlink', glob($dir . '/*'));
    rmdir($dir);
}

// -----------------------------------------------------------------------------
// Discovery and the constants contract
// -----------------------------------------------------------------------------

it('discovers every concrete subclass of errors.base_class and reads its constants', function () {
    $defs = definitionsByCode(makeErrorBuilder());

    expect(array_keys($defs))->toBe(['insufficient_balance', 'not_found', 'project_not_found', 'unauthenticated', 'validation_failed']);

    $balance = $defs['insufficient_balance'];
    expect($balance->schemaName)->toBe('InsufficientBalanceError')
        ->and($balance->responseSchemaName)->toBe('InsufficientBalanceErrorResponse')
        ->and($balance->bodySchemaName)->toBe('InsufficientBalanceErrorBody')
        ->and($balance->status)->toBe(402)
        ->and($balance->message)->toBe('Insufficient balance to complete this request')
        ->and($balance->hasDetails)->toBeTrue()
        ->and($balance->codeAndMessage())->toBe('`insufficient_balance`: Insufficient balance to complete this request');
});

it('resolves STATUS from an int, an int-backed enum, and abstract or concrete parents', function () {
    $defs = definitionsByCode(makeErrorBuilder());

    expect($defs['insufficient_balance']->status)->toBe(402)   // int
        ->and($defs['validation_failed']->status)->toBe(422)   // int-backed enum case
        ->and($defs['unauthenticated']->status)->toBe(401)     // inherited from an abstract parent
        ->and($defs['not_found']->status)->toBe(404)
        ->and($defs['project_not_found']->status)->toBe(404);  // inherited from a concrete parent
});

it('discovers no errors when errors.base_class is not configured', function () {
    $builder = makeErrorBuilder(['base_class' => null]);
    $names = array_keys(schemasByName($builder));

    expect($builder->getErrorDefinitions())->toBe([])
        ->and($names)->not->toContain('InsufficientBalanceErrorBody');
});

it('accepts a list of base classes', function () {
    expect(definitionsByCode(makeErrorBuilder(['base_class' => [ApiError::class]])))->toHaveCount(5);
});

it('rejects a base class that does not exist or is not a Data class', function (string $baseClass, string $message) {
    expect(fn () => makeErrorBuilder(['base_class' => $baseClass]))->toThrow(OpenApiDocsException::class, $message);
})->with([
    'missing class' => ['App\\Nope\\ApiError', 'errors.base_class App\\Nope\\ApiError does not exist'],
    'not a Data class' => [stdClass::class, 'errors.base_class stdClass must extend Spatie\\LaravelData\\Data'],
]);

it('enforces the error class contract before anything is written', function (string $declaration, string $message) {
    $dir = errorClassDir($declaration);

    try {
        expect(fn () => makeErrorBuilder([], $dir)->buildAll())->toThrow(OpenApiDocsException::class, $message);
    } finally {
        removeErrorClassDir($dir);
    }
})->with([
    'CODE missing' => [
        'class NoCodeError extends ApiError
{
    public const MESSAGE = "m";
    public const STATUS = 400;
}',
        'NoCodeError must declare const CODE',
    ],
    'CODE inherited' => [
        'class InheritsCodeError extends NotFoundError
{
    public const MESSAGE = "A more specific not found";
}',
        'InheritsCodeError inherits CODE from Langsys\OpenApiDocsGenerator\Tests\ErrorFixtures\NotFoundError',
    ],
    'MESSAGE inherited' => [
        'class InheritsMessageError extends NotFoundError
{
    public const CODE = "specific_not_found";
}',
        'InheritsMessageError inherits MESSAGE from Langsys\OpenApiDocsGenerator\Tests\ErrorFixtures\NotFoundError',
    ],
    'CODE empty' => [
        'class EmptyCodeError extends ApiError
{
    public const CODE = "";
    public const MESSAGE = "m";
    public const STATUS = 400;
}',
        'EmptyCodeError::CODE must be a non-empty string',
    ],
    'STATUS missing' => [
        'class NoStatusError extends ApiError
{
    public const CODE = "no_status";
    public const MESSAGE = "m";
}',
        'NoStatusError has no STATUS constant',
    ],
    'STATUS not an HTTP status' => [
        'class BadStatusError extends ApiError
{
    public const CODE = "bad_status";
    public const MESSAGE = "m";
    public const STATUS = 42;
}',
        'BadStatusError::STATUS must be an HTTP status code',
    ],
    'class-level Description' => [
        '#[Description("Said twice")]
class DescribedError extends ApiError
{
    public const CODE = "described";
    public const MESSAGE = "m";
    public const STATUS = 400;
}',
        'DescribedError has a class-level #[Description]',
    ],
    'envelope field reusing an error-object name' => [
        'class CollidingError extends ApiError
{
    public const CODE = "colliding";
    public const MESSAGE = "m";
    public const STATUS = 400;

    public function __construct(
        #[EnvelopeField]
        public string $code,
    ) {}
}',
        'CollidingError marks `code` as #[EnvelopeField]',
    ],
]);

it('rejects two error classes declaring the same CODE', function () {
    $dir = errorClassDir('class DuplicateCodeError extends ApiError
{
    public const CODE = "not_found";
    public const MESSAGE = "Also not found";
    public const STATUS = 404;
}');

    try {
        expect(fn () => makeErrorBuilder([], [$dir, errorFixturesDir()])->buildAll())
            ->toThrow(OpenApiDocsException::class, "Duplicate error code 'not_found'");
    } finally {
        removeErrorClassDir($dir);
    }
});

// -----------------------------------------------------------------------------
// Schemas
// -----------------------------------------------------------------------------

it('builds the details schema from non-envelope properties', function () {
    $schemas = schemasByName(makeErrorBuilder());

    expect($schemas)->toHaveKey('InsufficientBalanceError');
    $props = propsOf($schemas['InsufficientBalanceError']);
    expect(array_keys($props))->toBe(['required', 'available'])
        ->and($props['required']['type'])->toBe('integer');

    // No non-envelope properties, so no details schema.
    expect($schemas)->not->toHaveKey('ValidationError')
        ->and($schemas)->not->toHaveKey('UnauthenticatedError')
        ->and($schemas)->not->toHaveKey('NotFoundError');
});

it('builds the {Name}Response envelope around the error object', function () {
    $schemas = schemasByName(makeErrorBuilder());
    $envelope = $schemas['InsufficientBalanceErrorResponse'];
    $props = propsOf($envelope);

    expect(array_keys($props))->toBe(['status', 'data', 'error'])
        ->and($envelope['required'])->toBe(['status', 'error']);

    expect($props['status'])->toMatchArray(['type' => 'boolean', 'example' => false]);
    expect($props['data'])->toMatchArray(['type' => 'array', 'maxItems' => 0, 'items' => ['type' => 'object']]);
    expect($props['error']['allOf'][0]['$ref'])->toBe('#/components/schemas/InsufficientBalanceErrorBody');
});

it('builds the {Name}Body error object with MESSAGE as the message example', function () {
    $schemas = schemasByName(makeErrorBuilder());
    $body = $schemas['InsufficientBalanceErrorBody'];
    $props = propsOf($body);

    expect(array_keys($props))->toBe(['message', 'code', 'details'])
        ->and($body['required'])->toBe(['message', 'code']);

    expect($props['message'])->toMatchArray(['type' => 'string', 'example' => 'Insufficient balance to complete this request'])
        ->and($props['message'])->not->toHaveKey('default');
    expect($props['code'])->toMatchArray(['type' => 'string', 'enum' => ['insufficient_balance'], 'example' => 'insufficient_balance']);
    expect($props['details']['allOf'][0]['$ref'])->toBe('#/components/schemas/InsufficientBalanceError');

    // Open schema: undocumented fields such as debug output still validate.
    expect($body)->not->toHaveKey('additionalProperties');
});

it('omits details when the class has no non-envelope properties and lifts #[EnvelopeField] props into the error object', function () {
    $schemas = schemasByName(makeErrorBuilder());
    $props = propsOf($schemas['ValidationErrorBody']);

    expect(array_keys($props))->toBe(['message', 'code', 'errors'])
        ->and($props['errors']['type'])->toBe('object')
        ->and($props['errors']['additionalProperties'])->toBe(['type' => 'array', 'items' => ['type' => 'string']])
        ->and($props['errors']['description'])->toBe('Field name to list of validation messages')
        ->and($schemas['ValidationErrorBody']['required'])->toBe(['message', 'code', 'errors']);

    // Envelope fields never leak to the response level.
    expect(array_keys(propsOf($schemas['ValidationErrorResponse'])))->toBe(['status', 'data', 'error'])
        ->and(array_keys(propsOf($schemas['UnauthenticatedErrorBody'])))->toBe(['message', 'code']);
});

it('builds the ErrorCode enum only for the errors it is given, documented by MESSAGE', function () {
    $builder = makeErrorBuilder();
    $defs = definitionsByCode($builder);
    $enum = jsonOf($builder->buildErrorCodeSchema(array_values($defs)));

    expect($enum['type'])->toBe('string')
        ->and($enum['enum'])->toBe(['insufficient_balance', 'not_found', 'project_not_found', 'unauthenticated', 'validation_failed'])
        ->and($enum['description'])->toContain('returned in `error.code`')
        ->and($enum['description'])->toContain('`insufficient_balance` (HTTP 402): Insufficient balance to complete this request')
        ->and($enum['description'])->toContain('`project_not_found` (HTTP 404): No project with that id');

    expect(jsonOf($builder->buildErrorCodeSchema([$defs['not_found']]))['enum'])->toBe(['not_found'])
        ->and($builder->buildErrorCodeSchema([]))->toBeNull();

    // buildAll() never emits it: the generator adds it once it knows which errors are referenced.
    expect(array_keys(schemasByName(makeErrorBuilder())))->not->toContain('ErrorCode');
});

// -----------------------------------------------------------------------------
// Configuration
// -----------------------------------------------------------------------------

it('honours response- and error-level field renames, omissions and the code schema name from config', function () {
    $builder = makeErrorBuilder([
        'code_schema' => 'ApiErrorCode',
        'response_fields' => ['status' => 'ok', 'data' => null, 'error' => 'failure'],
        'error_fields' => ['message' => 'text', 'code' => 'reason', 'details' => 'meta'],
    ]);
    $schemas = schemasByName($builder);

    $response = $schemas['InsufficientBalanceErrorResponse'];
    $body = $schemas['InsufficientBalanceErrorBody'];

    expect(array_keys(propsOf($response)))->toBe(['ok', 'failure'])
        ->and($response['required'])->toBe(['ok', 'failure'])
        ->and(array_keys(propsOf($body)))->toBe(['text', 'reason', 'meta'])
        ->and($body['required'])->toBe(['text', 'reason']);

    $enum = $builder->buildErrorCodeSchema($builder->getErrorDefinitions());

    expect($builder->getErrorCodeSchemaName())->toBe('ApiErrorCode')
        ->and($enum->schema)->toBe('ApiErrorCode')
        ->and(jsonOf($enum)['description'])->toContain('returned in `failure.reason`');
});

it('omits message from the error object when its name is null', function () {
    $body = schemasByName(makeErrorBuilder(['error_fields' => ['message' => null]]))['InsufficientBalanceErrorBody'];

    expect(array_keys(propsOf($body)))->toBe(['code', 'details'])
        ->and($body['required'])->toBe(['code']);
});

it('rejects the removed errors.fields key with a pointer to its replacements', function () {
    makeErrorBuilder(['fields' => ['code' => 'code']]);
})->throws(OpenApiDocsException::class, 'errors.fields was replaced by errors.response_fields');

it('refuses to omit the error object or its code', function (array $config, string $message) {
    expect(fn () => makeErrorBuilder($config))->toThrow(OpenApiDocsException::class, $message);
})->with([
    'the error object' => [['response_fields' => ['error' => null]], 'errors.response_fields.error cannot be null'],
    'the code' => [['error_fields' => ['code' => null]], 'errors.error_fields.code cannot be null'],
]);

it('rejects unknown and duplicate field names so a typo cannot silently fall back', function (array $config, string $message) {
    expect(fn () => makeErrorBuilder($config))->toThrow(OpenApiDocsException::class, $message);
})->with([
    'unknown key' => [['error_fields' => ['mesage' => 'msg']], 'Unknown errors.error_fields key(s): mesage'],
    'duplicate name' => [['response_fields' => ['status' => 'error']], 'errors.response_fields uses the name `error` more than once'],
]);

it('scans extra errors.paths in addition to the DTO paths', function () {
    $names = array_keys(schemasByName(makeErrorBuilder(['paths' => [errorFixturesDir()]], dirname(__DIR__) . '/Data')));

    expect($names)->toContain('InsufficientBalanceErrorResponse')
        ->and($names)->toContain('ExampleData'); // a regular DTO from tests/Data still builds
});
