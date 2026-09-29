<?php

use Langsys\OpenApiDocsGenerator\Data\ErrorDefinition;
use Langsys\OpenApiDocsGenerator\Exceptions\OpenApiDocsException;
use Langsys\OpenApiDocsGenerator\Generators\DtoSchemaBuilder;
use Langsys\OpenApiDocsGenerator\Generators\ExampleGenerator;
use Langsys\OpenApiDocsGenerator\Generators\OperationErrorAttacher;
use Langsys\OpenApiDocsGenerator\Tests\ErrorCatalogFixtures\CatalogThrowsController;
use Langsys\OpenApiDocsGenerator\Tests\ErrorCatalogFixtures\PlanCycleAlreadySetClassError;
use Langsys\OpenApiDocsGenerator\Tests\ErrorCatalogFixtures\PlanErrors;
use Langsys\OpenApiDocsGenerator\Tests\ErrorFixtures\ApiError;
use OpenApi\Annotations as OA;
use OpenApi\Context;

function catalogDir(): string
{
    return dirname(__DIR__) . '/ErrorCatalogFixtures';
}

/**
 * @param  string|string[]|null  $dirs
 */
function catalogBuilder(array $errorConfig = [], string|array|null $dirs = null): DtoSchemaBuilder
{
    return new DtoSchemaBuilder(
        $dirs ?? catalogDir(),
        new ExampleGenerator([], []),
        [],
        array_merge(['base_class' => ApiError::class], $errorConfig),
    );
}

/** @return array<string, array> schema name => decoded JSON */
function catalogSchemas(DtoSchemaBuilder $builder): array
{
    $out = [];
    foreach ($builder->buildAll() as $schema) {
        $out[$schema->schema] = json_decode(json_encode($schema), true);
    }

    return $out;
}

/** @return array<string, ErrorDefinition> code => definition */
function catalogDefinitions(DtoSchemaBuilder $builder): array
{
    $builder->buildAll();

    $out = [];
    foreach ($builder->getErrorDefinitions() as $definition) {
        $out[$definition->code] = $definition;
    }
    ksort($out);

    return $out;
}

/** Write one enum declaration into its own temp directory and load it. */
function catalogTempDir(string $declaration): string
{
    $dir = sys_get_temp_dir() . '/openapi-catalog-' . str_replace('.', '', uniqid('', true));
    mkdir($dir);
    $namespace = 'ErrorCatalogFixture\\N' . str_replace('.', '', uniqid('', true));
    $file = $dir . '/Fixture.php';

    file_put_contents($file, implode("\n", [
        '<?php',
        "namespace {$namespace};",
        'use Langsys\\OpenApiDocsGenerator\\Contracts\\ErrorCatalog;',
        'use Langsys\\OpenApiDocsGenerator\\Generators\\Attributes\\ErrorCase;',
        '',
        $declaration,
        '',
    ]));
    require_once $file;

    return $dir;
}

function removeCatalogTempDir(string $dir): void
{
    array_map('unlink', glob($dir . '/*'));
    rmdir($dir);
}

// -----------------------------------------------------------------------------
// Discovery
// -----------------------------------------------------------------------------

it('discovers an error catalog with no configuration of its own', function () {
    $definitions = catalogDefinitions(catalogBuilder());

    expect(array_keys($definitions))->toBe([
        'plan_cycle_already_set_class',
        'plan_cycle_already_set_enum',
        'plan_not_found',
        'same_plan_cycle',
    ]);

    $case = $definitions['same_plan_cycle'];

    expect($case->case)->toBe('SamePlanCycle')
        ->and($case->identifier())->toBe(PlanErrors::class . '::SamePlanCycle')
        ->and($case->className)->toBe(PlanErrors::class)
        ->and($case->schemaName)->toBe('SamePlanCycleError')
        ->and($case->bodySchemaName)->toBe('SamePlanCycleErrorBody')
        ->and($case->responseSchemaName)->toBe('SamePlanCycleErrorResponse')
        ->and($case->status)->toBe(409)
        ->and($case->message)->toBe('The plan cycle is already that one.')
        ->and($case->hasDetails)->toBeFalse()
        ->and($case->codeAndMessage())->toBe('`same_plan_cycle`: The plan cycle is already that one.');

    // A status may be an int-backed enum case, exactly as a class STATUS may be.
    expect($definitions['plan_not_found']->status)->toBe(404);
});

it('generates the same schemas for a case and the equivalent class', function () {
    $schemas = catalogSchemas(catalogBuilder());

    // The pair differ only in name and code; normalise those and the rest must match.
    $normalise = static fn (array $schema, string $name, string $code): string => str_replace(
        [$name, $code],
        ['Equivalent', 'equivalent'],
        json_encode($schema),
    );

    foreach (['Body', 'Response'] as $suffix) {
        $fromClass = $normalise(
            $schemas['PlanCycleAlreadySetClassError' . $suffix],
            'PlanCycleAlreadySetClassError',
            'plan_cycle_already_set_class',
        );
        $fromCase = $normalise(
            $schemas['PlanCycleAlreadySetEnumError' . $suffix],
            'PlanCycleAlreadySetEnumError',
            'plan_cycle_already_set_enum',
        );

        expect($fromCase)->toBe($fromClass);
    }

    // A case has no properties, so it has no details schema, like a class without any.
    expect($schemas)->not->toHaveKey('SamePlanCycleError')
        ->and($schemas)->toHaveKeys(['SamePlanCycleErrorBody', 'SamePlanCycleErrorResponse']);
});

it('names case schemas with the configured suffix', function () {
    $schemas = catalogSchemas(catalogBuilder(['case_suffix' => 'Failure']));

    expect($schemas)->toHaveKeys(['SamePlanCycleFailureBody', 'SamePlanCycleFailureResponse'])
        ->and($schemas)->not->toHaveKey('SamePlanCycleErrorBody')
        // A class error keeps its own name, which never used the suffix.
        ->and($schemas)->toHaveKey('PlanCycleAlreadySetClassErrorBody');
});

it('ignores an enum that is not an error catalog', function () {
    $names = array_keys(catalogSchemas(catalogBuilder([], dirname(__DIR__) . '/ErrorFixtures')));

    // tests/ErrorFixtures holds the HttpCode enum, which is not a catalog.
    expect($names)->not->toContain('UnauthorizedBody')
        ->and($names)->toContain('InsufficientBalanceErrorBody');
});

// -----------------------------------------------------------------------------
// The case contract
// -----------------------------------------------------------------------------

it('enforces the error case contract before anything is written', function (string $declaration, string $message) {
    $dir = catalogTempDir($declaration);

    try {
        expect(fn () => catalogBuilder([], $dir)->buildAll())->toThrow(OpenApiDocsException::class, $message);
    } finally {
        removeCatalogTempDir($dir);
    }
})->with([
    'case without #[ErrorCase]' => [
        'enum NoAttributeErrors: string implements ErrorCatalog
{
    case Missing = "missing_attribute";
}',
        'NoAttributeErrors::Missing must carry #[ErrorCase(status: …, message: …)]',
    ],
    'enum not backed by string' => [
        'enum IntBackedErrors: int implements ErrorCatalog
{
    #[ErrorCase(status: 409, message: "m")]
    case One = 1;
}',
        'must be a string-backed enum whose backing value is the error code; got int',
    ],
    'marker in a case message' => [
        'enum MarkerErrors: string implements ErrorCatalog
{
    #[ErrorCase(status: 422, message: "Up to {limit} items")]
    case TooMany = "too_many";
}',
        'uses the marker {limit}, but an enum case has no properties to fill it',
    ],
    'status outside the HTTP range' => [
        'enum BadStatusErrors: string implements ErrorCatalog
{
    #[ErrorCase(status: 42, message: "m")]
    case Bad = "bad_status";
}',
        'must be an HTTP status code between 100 and 599',
    ],
    'empty message' => [
        'enum EmptyMessageErrors: string implements ErrorCatalog
{
    #[ErrorCase(status: 409, message: "")]
    case Empty = "empty_message";
}',
        'message on ErrorCatalogFixture',
    ],
]);

it('reports a duplicate code across a class and a case, naming both', function () {
    $dir = catalogTempDir('enum DuplicateOfClassErrors: string implements ErrorCatalog
{
    #[ErrorCase(status: 409, message: "The plan cycle is already that one.")]
    case Dupe = "plan_cycle_already_set_class";
}');

    try {
        expect(fn () => catalogBuilder([], [catalogDir(), $dir])->buildAll())
            ->toThrow(OpenApiDocsException::class, "Duplicate error code 'plan_cycle_already_set_class'")
            ->and(fn () => catalogBuilder([], [catalogDir(), $dir])->buildAll())
            ->toThrow(OpenApiDocsException::class, '::Dupe');
    } finally {
        removeCatalogTempDir($dir);
    }
});

// -----------------------------------------------------------------------------
// Referring to a case
// -----------------------------------------------------------------------------

it('attaches a class error and an enum case declared together', function () {
    $builder = catalogBuilder();
    $schemas = $builder->buildAll();

    $operation = new OA\Get(['responses' => [new OA\Response(['response' => '200', 'description' => 'OK'])]]);
    $operation->_context = new Context([
        'namespace' => 'Langsys\\OpenApiDocsGenerator\\Tests\\ErrorCatalogFixtures',
        'class' => 'CatalogThrowsController',
        'method' => 'mixedErrors',
    ]);

    $pathItem = new OA\PathItem(['path' => '/api/plans']);
    $pathItem->get = $operation;
    $openapi = new OA\OpenApi([
        'info' => new OA\Info(['title' => 'T', 'version' => '1.0']),
        'components' => new OA\Components(['schemas' => $schemas]),
    ]);
    $openapi->paths = [$pathItem];

    (new OperationErrorAttacher())->attach($openapi, $builder->getErrorDefinitions(), $builder->getErrorEnvelope());

    $response = json_decode(json_encode($operation->responses[1]), true);
    $media = $response['content']['application/json'];

    // Both errors answer 409, so they share the status: the case is just another variant.
    expect($response['response'] ?? 409)->not->toBeNull()
        ->and($media['schema']['properties']['error']['oneOf'])->toBe([
            ['$ref' => '#/components/schemas/PlanCycleAlreadySetClassErrorBody'],
            ['$ref' => '#/components/schemas/SamePlanCycleErrorBody'],
        ])
        ->and($media['schema']['properties']['error']['discriminator']['mapping'])->toBe([
            'plan_cycle_already_set_class' => '#/components/schemas/PlanCycleAlreadySetClassErrorBody',
            'same_plan_cycle' => '#/components/schemas/SamePlanCycleErrorBody',
        ])
        ->and(array_keys($media['examples']))->toBe(['plan_cycle_already_set_class', 'same_plan_cycle'])
        ->and($response['description'])->toContain('- `same_plan_cycle`: The plan cycle is already that one.');
});

it('fails when a declared case is not from an error catalog', function () {
    $operation = new OA\Get(['responses' => []]);
    $openapi = new OA\OpenApi(['info' => new OA\Info(['title' => 'T', 'version' => '1.0'])]);
    $pathItem = new OA\PathItem(['path' => '/api/plans']);
    $pathItem->get = $operation;
    $openapi->paths = [$pathItem];

    $rule = new class implements \Langsys\OpenApiDocsGenerator\Contracts\ImpliedErrorRule {
        public function errorsFor(\Langsys\OpenApiDocsGenerator\Data\OperationContext $context, ?ReflectionMethod $action): array
        {
            return [\Langsys\OpenApiDocsGenerator\Tests\ErrorFixtures\HttpCode::NOT_FOUND];
        }
    };

    (new OperationErrorAttacher(impliedErrors: ['rules' => [$rule]]))->attach($openapi, []);
})->throws(OpenApiDocsException::class, 'is not an error: its enum must implement');

it('keys a reference by its case, whether given as a case or a class', function () {
    expect(ErrorDefinition::keyFor(PlanErrors::SamePlanCycle))->toBe(PlanErrors::class . '::SamePlanCycle')
        ->and(ErrorDefinition::keyFor(PlanCycleAlreadySetClassError::class))->toBe(PlanCycleAlreadySetClassError::class)
        ->and(ErrorDefinition::keyFor('\\' . PlanCycleAlreadySetClassError::class))->toBe(PlanCycleAlreadySetClassError::class);
});
