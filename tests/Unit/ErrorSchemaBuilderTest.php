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

it('builds the {Name}Body error object with message, code and details', function () {
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

it('builds the shared ErrorCode enum listing every code with its status and description', function () {
    $schemas = schemasByName(makeErrorBuilder());
    $enum = $schemas['ErrorCode'];

    expect($enum['type'])->toBe('string')
        ->and($enum['enum'])->toBe(['insufficient_balance', 'unauthenticated', 'validation_failed'])
        ->and($enum['description'])->toContain('returned in `error.code`')
        ->and($enum['description'])->toContain('`insufficient_balance` (HTTP 402): The account balance cannot cover the requested operation.')
        ->and($enum['description'])->toContain('`unauthenticated` (HTTP 401): Unauthenticated');
});

it('honours response- and error-level field renames, omissions and the code schema name from config', function () {
    $schemas = schemasByName(makeErrorBuilder([
        'code_schema' => 'ApiErrorCode',
        'response_fields' => ['status' => 'ok', 'data' => null, 'error' => 'failure'],
        'error_fields' => ['message' => 'text', 'code' => 'reason', 'details' => 'meta'],
    ]));

    $response = $schemas['InsufficientBalanceErrorResponse'];
    $body = $schemas['InsufficientBalanceErrorBody'];

    expect(array_keys(propsOf($response)))->toBe(['ok', 'failure'])
        ->and($response['required'])->toBe(['ok', 'failure'])
        ->and(array_keys(propsOf($body)))->toBe(['text', 'reason', 'meta'])
        ->and($body['required'])->toBe(['text', 'reason'])
        ->and($schemas)->toHaveKey('ApiErrorCode')
        ->and($schemas)->not->toHaveKey('ErrorCode')
        ->and($schemas['ApiErrorCode']['description'])->toContain('returned in `failure.reason`');
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

it('rejects an #[EnvelopeField] property that reuses an error-object field name', function () {
    $dir = sys_get_temp_dir() . '/openapi-collide-' . uniqid();
    mkdir($dir);
    file_put_contents($dir . '/CollidingError.php', <<<'PHP'
<?php
namespace ErrorCollisionFixture;
use Langsys\OpenApiDocsGenerator\Generators\Attributes\EnvelopeField;
use Langsys\OpenApiDocsGenerator\Generators\Attributes\ErrorCode;
use Langsys\OpenApiDocsGenerator\Generators\Attributes\HttpStatus;
use Spatie\LaravelData\Data;
#[ErrorCode('colliding')]
#[HttpStatus(400)]
class CollidingError extends Data
{
    public function __construct(
        #[EnvelopeField]
        public string $code,
    ) {}
}
PHP);
    require_once $dir . '/CollidingError.php';

    try {
        expect(fn () => makeErrorBuilder([], $dir)->buildAll())
            ->toThrow(OpenApiDocsException::class, 'CollidingError marks `code` as #[EnvelopeField]');
    } finally {
        array_map('unlink', glob($dir . '/*'));
        rmdir($dir);
    }
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
