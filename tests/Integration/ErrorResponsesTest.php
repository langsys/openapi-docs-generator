<?php

use Langsys\OpenApiDocsGenerator\Generators\DtoSchemaBuilder;
use Langsys\OpenApiDocsGenerator\Generators\ExampleGenerator;
use Langsys\OpenApiDocsGenerator\Generators\OpenApiGenerator;
use Langsys\OpenApiDocsGenerator\Tests\ErrorFixtures\ApiError;
use Psr\Log\NullLogger;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir() . '/openapi-errors-' . uniqid();
    mkdir($this->tempDir, 0777, true);
    $this->docsFile = $this->tempDir . '/api-docs.json';
    $this->yamlFile = $this->tempDir . '/api-docs.yaml';
});

afterEach(function () {
    @unlink($this->docsFile);
    @unlink($this->yamlFile);
    @rmdir($this->tempDir);
});

// tests/ErrorFixtures holds five error classes; its controller references only
// InsufficientBalanceError, through a hand-written @OA\Response(ref=...).
function makeErrorGenerator(string $docsFile, string $yamlFile, bool $prune = true): OpenApiGenerator
{
    $dir = dirname(__DIR__, 2) . '/tests/ErrorFixtures';

    return new OpenApiGenerator(
        annotationsDir: [$dir],
        docsFile: $docsFile,
        yamlDocsFile: $yamlFile,
        securitySchemesConfig: [],
        securityConfig: [],
        scanOptions: ['open_api_spec_version' => '3.0.0'],
        constants: [],
        basePath: null,
        yamlCopy: false,
        endpointParametersConfig: [],
        dtoSchemaBuilder: new DtoSchemaBuilder($dir, new ExampleGenerator([], []), [], ['base_class' => ApiError::class]),
        logger: new NullLogger(),
        pruneComponents: $prune,
        validateRefs: 'strict',
    );
}

test('emits components.responses.{Name} with MESSAGE as description, an envelope $ref and x-http-status', function () {
    $generator = makeErrorGenerator($this->docsFile, $this->yamlFile);
    $generator->generateDocs();
    $doc = json_decode(file_get_contents($this->docsFile), true);

    $response = $doc['components']['responses']['InsufficientBalanceError'];

    expect($response['description'])->toBe('Insufficient balance to complete this request')
        ->and($response['x-http-status'])->toBe(402)
        ->and($response['content']['application/json']['schema']['$ref'])->toBe('#/components/schemas/InsufficientBalanceErrorResponse');

    expect($generator->getErrorDefinitions())->toHaveCount(5);
});

test('a hand-written @OA\Response(ref=...) to an error response resolves and keeps its closure', function () {
    makeErrorGenerator($this->docsFile, $this->yamlFile)->generateDocs(); // validate_refs strict: would throw on a dangling ref
    $doc = json_decode(file_get_contents($this->docsFile), true);

    expect($doc['paths']['/api/purchase']['post']['responses']['402']['$ref'])->toBe('#/components/responses/InsufficientBalanceError')
        ->and($doc['components']['schemas'])->toHaveKeys([
            'InsufficientBalanceError',
            'InsufficientBalanceErrorBody',
            'InsufficientBalanceErrorResponse',
        ]);
});

test('the ErrorCode enum lists only the codes the operations reference', function () {
    makeErrorGenerator($this->docsFile, $this->yamlFile)->generateDocs();
    $doc = json_decode(file_get_contents($this->docsFile), true);
    $enum = $doc['components']['schemas']['ErrorCode'];

    expect($enum['enum'])->toBe(['insufficient_balance'])
        ->and($enum['description'])->toContain('`insufficient_balance` (HTTP 402): Insufficient balance to complete this request')
        ->and($enum['description'])->not->toContain('unauthenticated')
        ->and($doc['components']['responses'])->not->toHaveKey('ValidationError')
        ->and($doc['components']['schemas'])->not->toHaveKey('ValidationErrorBody');
});

test('unreferenced error components are removed even with pruning off', function () {
    makeErrorGenerator($this->docsFile, $this->yamlFile, prune: false)->generateDocs();
    $doc = json_decode(file_get_contents($this->docsFile), true);
    $schemas = $doc['components']['schemas'];
    $responses = $doc['components']['responses'];

    expect($responses)->toHaveKey('InsufficientBalanceError')
        ->and($schemas)->toHaveKeys(['InsufficientBalanceError', 'InsufficientBalanceErrorBody', 'InsufficientBalanceErrorResponse']);

    foreach (['ValidationError', 'UnauthenticatedError', 'NotFoundError', 'ProjectNotFoundError'] as $internal) {
        expect($responses)->not->toHaveKey($internal)
            ->and($schemas)->not->toHaveKey($internal . 'Body')
            ->and($schemas)->not->toHaveKey($internal . 'Response');
    }

    expect($schemas['ErrorCode']['enum'])->toBe(['insufficient_balance']);
});
