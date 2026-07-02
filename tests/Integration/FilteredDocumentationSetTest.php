<?php

use Illuminate\Routing\Router;
use Langsys\OpenApiDocsGenerator\Filters\OperationFilterFactory;
use Langsys\OpenApiDocsGenerator\Generators\DtoSchemaBuilder;
use Langsys\OpenApiDocsGenerator\Generators\ExampleGenerator;
use Langsys\OpenApiDocsGenerator\Generators\OpenApiGenerator;
use Langsys\OpenApiDocsGenerator\Generators\OperationSelector;
use Langsys\OpenApiDocsGenerator\Routing\LaravelRouteResolver;
use Langsys\OpenApiDocsGenerator\Tests\Fixtures\TestController;
use Psr\Log\NullLogger;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir() . '/openapi-filtered-' . uniqid();
    mkdir($this->tempDir, 0777, true);
    $this->docsFile = $this->tempDir . '/api-docs.json';
    $this->yamlFile = $this->tempDir . '/api-docs.yaml';
});

afterEach(function () {
    @unlink($this->docsFile);
    @unlink($this->yamlFile);
    @rmdir($this->tempDir);
});

function makeFilteredGenerator(
    string $docsFile,
    string $yamlFile,
    ?OperationSelector $selector,
    ?array $securityOverride = null,
    array $securitySchemes = [],
): OpenApiGenerator {
    $packageRoot = dirname(__DIR__, 2);

    $dtoSchemaBuilder = new DtoSchemaBuilder(
        dtoPaths: $packageRoot . '/tests/Data',
        exampleGenerator: new ExampleGenerator(fakerAttributeMapper: [], customFunctions: []),
        paginationFields: [],
    );

    return new OpenApiGenerator(
        annotationsDir: [$packageRoot . '/tests/Fixtures'],
        docsFile: $docsFile,
        yamlDocsFile: $yamlFile,
        securitySchemesConfig: $securitySchemes,
        securityConfig: [],
        scanOptions: ['open_api_spec_version' => '3.0.0'],
        constants: [],
        basePath: null,
        yamlCopy: false,
        endpointParametersConfig: [],
        dtoSchemaBuilder: $dtoSchemaBuilder,
        logger: new NullLogger(),
        operationSelector: $selector,
        securityOverride: $securityOverride,
        pruneComponents: true,
    );
}

function apiKeySelector(string $unmatched = 'exclude'): OperationSelector
{
    /** @var Router $router */
    $router = app('router');

    return new OperationSelector(
        routeResolver: new LaravelRouteResolver($router),
        include: (new OperationFilterFactory($router->getMiddleware()))->makeMany([['middleware' => 'auth.apikey']]),
        unmatched: $unmatched,
    );
}

function registerApiKeyAlias(): Router
{
    /** @var Router $router */
    $router = app('router');
    $router->aliasMiddleware('auth.apikey', 'App\\Http\\Middleware\\AuthorizeApiKey');

    return $router;
}

test('keeps an operation whose route carries the api-key middleware and its referenced schema', function () {
    $router = registerApiKeyAlias();
    $router->group(['middleware' => ['auth.apikey']], function () use ($router) {
        $router->get('api/examples', [TestController::class, 'index']);
    });

    $generator = makeFilteredGenerator($this->docsFile, $this->yamlFile, apiKeySelector());
    $generator->generateDocs();

    $json = json_decode(file_get_contents($this->docsFile), true);

    expect($json['paths'])->toHaveKey('/api/examples')
        ->and(array_keys($json['components']['schemas']))->toContain('ExampleData')
        // A DTO built from tests/Data that the surviving operation does not reference is pruned.
        ->and(array_keys($json['components']['schemas']))->not->toContain('TestData');

    expect($generator->getSelectionReport()->counts())->toBe(['kept' => 1, 'dropped' => 0, 'unmatched' => 0]);
});

test('drops an operation whose route lacks the api-key middleware, pruning its now-orphaned schema', function () {
    $router = registerApiKeyAlias();
    // Registered, but without the api-key middleware.
    $router->get('api/examples', [TestController::class, 'index']);

    $generator = makeFilteredGenerator($this->docsFile, $this->yamlFile, apiKeySelector());
    $generator->generateDocs();

    $json = json_decode(file_get_contents($this->docsFile), true);

    expect($json['paths'] ?? [])->not->toHaveKey('/api/examples')
        ->and(array_keys($json['components']['schemas'] ?? []))->not->toContain('ExampleData');

    expect($generator->getSelectionReport()->counts())->toBe(['kept' => 0, 'dropped' => 1, 'unmatched' => 0]);
});

test('excludes an operation that cannot be matched to any route (unmatched=exclude)', function () {
    registerApiKeyAlias(); // alias exists, but no route for /api/examples is registered

    $generator = makeFilteredGenerator($this->docsFile, $this->yamlFile, apiKeySelector('exclude'));
    $generator->generateDocs();

    $json = json_decode(file_get_contents($this->docsFile), true);

    expect($json['paths'] ?? [])->not->toHaveKey('/api/examples');

    $counts = $generator->getSelectionReport()->counts();
    expect($counts['kept'])->toBe(0)
        ->and($counts['unmatched'])->toBe(1);
});

test('security_override forces the operation security and restricts advertised schemes', function () {
    $router = registerApiKeyAlias();
    $router->group(['middleware' => ['auth.apikey']], function () use ($router) {
        $router->get('api/examples', [TestController::class, 'index']);
    });

    $generator = makeFilteredGenerator(
        $this->docsFile,
        $this->yamlFile,
        apiKeySelector(),
        securityOverride: [['apiKey' => []]],
        securitySchemes: [
            'apiKey' => ['type' => 'apiKey', 'name' => 'X-Api-Key', 'in' => 'header'],
            'bearerAuth' => ['type' => 'http', 'scheme' => 'bearer'],
        ],
    );
    $generator->generateDocs();

    $json = json_decode(file_get_contents($this->docsFile), true);

    expect($json['paths']['/api/examples']['get']['security'])->toBe([['apiKey' => []]])
        ->and($json['components']['securitySchemes'])->toHaveKey('apiKey')
        ->and($json['components']['securitySchemes'])->not->toHaveKey('bearerAuth')
        ->and($json['security'])->toBe([['apiKey' => []]]);
});
