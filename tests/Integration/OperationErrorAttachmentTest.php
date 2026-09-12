<?php

use Illuminate\Routing\Route;
use Langsys\OpenApiDocsGenerator\Contracts\RouteResolver;
use Langsys\OpenApiDocsGenerator\Data\ResolvableOperation;
use Langsys\OpenApiDocsGenerator\Data\ResolvedRoute;
use Langsys\OpenApiDocsGenerator\Generators\DtoSchemaBuilder;
use Langsys\OpenApiDocsGenerator\Generators\ExampleGenerator;
use Langsys\OpenApiDocsGenerator\Generators\OpenApiGenerator;
use Langsys\OpenApiDocsGenerator\Generators\OperationErrorAttacher;
use Langsys\OpenApiDocsGenerator\Tests\ErrorFixtures\UnauthenticatedError;
use Langsys\OpenApiDocsGenerator\Tests\ErrorFixtures\ValidationError;
use Psr\Log\NullLogger;

const ATTACH_MW = 'App\\Middleware\\ApiKeyAuth';

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir() . '/openapi-attach-' . uniqid();
    mkdir($this->tempDir, 0777, true);
    $this->docsFile = $this->tempDir . '/api-docs.json';
    $this->yamlFile = $this->tempDir . '/api-docs.yaml';
});

afterEach(function () {
    @unlink($this->docsFile);
    @unlink($this->yamlFile);
    @rmdir($this->tempDir);
});

/** Resolves every operation to a route whose URI mirrors the documented path. */
function attachmentResolver(array $middleware): RouteResolver
{
    return new class($middleware) implements RouteResolver {
        public function __construct(private array $middleware) {}

        public function resolve(ResolvableOperation $operation): ?ResolvedRoute
        {
            return new ResolvedRoute(
                new Route([strtoupper($operation->httpMethod)], ltrim($operation->path, '/'), fn () => null),
                $this->middleware,
                $operation->action,
            );
        }
    };
}

function generateWithErrors(string $docsFile, string $yamlFile, array $impliedErrors = [], bool $withRoutes = true): array
{
    $operationsDir = dirname(__DIR__, 2) . '/tests/ErrorOperationFixtures';
    $errorsDir = dirname(__DIR__, 2) . '/tests/ErrorFixtures';

    (new OpenApiGenerator(
        annotationsDir: [$operationsDir],
        docsFile: $docsFile,
        yamlDocsFile: $yamlFile,
        securitySchemesConfig: [],
        securityConfig: [],
        scanOptions: ['open_api_spec_version' => '3.0.0'],
        constants: [],
        basePath: null,
        yamlCopy: false,
        endpointParametersConfig: [],
        dtoSchemaBuilder: new DtoSchemaBuilder(
            $operationsDir,
            new ExampleGenerator([], []),
            [],
            ['paths' => [$errorsDir]],
        ),
        logger: new NullLogger(),
        validateRefs: 'strict',
        errorAttacher: new OperationErrorAttacher(
            routeResolver: $withRoutes ? attachmentResolver([ATTACH_MW]) : null,
            impliedErrors: $impliedErrors,
        ),
    ))->generateDocs();

    return json_decode(file_get_contents($docsFile), true);
}

test('a single declared error becomes a $ref to its reusable response, and strict ref validation passes', function () {
    $doc = generateWithErrors($this->docsFile, $this->yamlFile);

    expect($doc['paths']['/api/purchase']['post']['responses']['402'])
        ->toBe(['$ref' => '#/components/responses/InsufficientBalanceError'])
        ->and($doc['components']['responses']['InsufficientBalanceError']['x-http-status'])->toBe(402);
});

test('errors sharing a status become one envelope whose error property is a oneOf discriminated on code', function () {
    $doc = generateWithErrors($this->docsFile, $this->yamlFile); // validate_refs strict: every Body ref must resolve

    $schema = $doc['paths']['/api/batch']['post']['responses']['422']['content']['application/json']['schema'];
    $error = $schema['properties']['error'];

    expect($schema)->not->toHaveKey('oneOf')
        ->and($error['oneOf'])->toBe([
            ['$ref' => '#/components/schemas/BatchTooLargeErrorBody'],
            ['$ref' => '#/components/schemas/ValidationErrorBody'],
        ])
        ->and($error['discriminator']['propertyName'])->toBe('code')
        ->and($error['discriminator']['mapping'])->toBe([
            'batch_too_large' => '#/components/schemas/BatchTooLargeErrorBody',
            'validation_failed' => '#/components/schemas/ValidationErrorBody',
        ]);
});

test('a hand-written response for the same status wins', function () {
    $doc = generateWithErrors($this->docsFile, $this->yamlFile);

    expect($doc['paths']['/api/projects/{project}']['get']['responses']['402']['description'])
        ->toBe('Hand-written, wins over the attached one');
});

test('pruning keeps exactly the schemas the attached responses reach', function () {
    $doc = generateWithErrors($this->docsFile, $this->yamlFile);
    $schemas = $doc['components']['schemas'];

    expect($schemas)->toHaveKeys([
        // /api/purchase: response ref -> Response -> Body -> details
        'InsufficientBalanceErrorResponse',
        'InsufficientBalanceErrorBody',
        'InsufficientBalanceError',
        // /api/batch shared 422: the Bodies directly, plus BatchTooLargeError's details
        'ValidationErrorBody',
        'BatchTooLargeErrorBody',
        'BatchTooLargeError',
        'ErrorCode',
    ]);

    // The shared 422 references Bodies, not envelopes, so those envelopes are unreached.
    expect($schemas)->not->toHaveKey('ValidationErrorResponse')
        ->and($schemas)->not->toHaveKey('BatchTooLargeErrorResponse')
        // Nothing references the 401 error at all.
        ->and($schemas)->not->toHaveKey('UnauthenticatedErrorBody')
        ->and($doc['components']['responses'])->not->toHaveKey('UnauthenticatedError');
});

test('middleware-implied errors are attached to every route carrying the middleware', function () {
    $doc = generateWithErrors($this->docsFile, $this->yamlFile, [
        'middleware' => [ATTACH_MW => [UnauthenticatedError::class]],
    ]);

    foreach (['/api/purchase' => 'post', '/api/batch' => 'post', '/api/projects/{project}' => 'get'] as $path => $method) {
        expect($doc['paths'][$path][$method]['responses']['401'])
            ->toBe(['$ref' => '#/components/responses/UnauthenticatedError']);
    }

    expect($doc['components']['responses'])->toHaveKey('UnauthenticatedError');
});

test('the structural rules imply validation for a Data-typed action and not-found for a model-bound route', function () {
    $doc = generateWithErrors($this->docsFile, $this->yamlFile, [
        'validation' => ValidationError::class,
        'not_found' => UnauthenticatedError::class, // stands in for a not-found error (401 keeps statuses distinct)
    ]);

    // POST /api/projects takes StoreProjectRequest (a Data class) -> 422
    expect($doc['paths']['/api/projects']['post']['responses']['422'])
        ->toBe(['$ref' => '#/components/responses/ValidationError']);

    // GET /api/projects/{project} binds a Project model -> the not-found error
    expect($doc['paths']['/api/projects/{project}']['get']['responses']['401'])
        ->toBe(['$ref' => '#/components/responses/UnauthenticatedError']);

    // /api/purchase has no Data parameter and no route parameter -> neither
    expect($doc['paths']['/api/purchase']['post']['responses'])->not->toHaveKey('401');
});

test('without a route resolver only declared errors are attached', function () {
    $doc = generateWithErrors($this->docsFile, $this->yamlFile, [
        'middleware' => [ATTACH_MW => [UnauthenticatedError::class]],
    ], withRoutes: false);

    expect($doc['paths']['/api/purchase']['post']['responses'])->toHaveKey('402')
        ->and($doc['paths']['/api/purchase']['post']['responses'])->not->toHaveKey('401');
});
