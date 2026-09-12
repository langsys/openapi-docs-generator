<?php

use Langsys\OpenApiDocsGenerator\Generators\DtoSchemaBuilder;
use Langsys\OpenApiDocsGenerator\Generators\ExampleGenerator;
use Langsys\OpenApiDocsGenerator\Generators\OpenApiGenerator;
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
        dtoSchemaBuilder: new DtoSchemaBuilder($dir, new ExampleGenerator([], []), []),
        logger: new NullLogger(),
        pruneComponents: $prune,
        validateRefs: 'strict',
    );
}

test('emits components.responses.{Name} with description, envelope $ref and x-http-status', function () {
    $generator = makeErrorGenerator($this->docsFile, $this->yamlFile);
    $generator->generateDocs();
    $doc = json_decode(file_get_contents($this->docsFile), true);

    $response = $doc['components']['responses']['InsufficientBalanceError'];

    expect($response['description'])->toBe('The account balance cannot cover the requested operation.')
        ->and($response['x-http-status'])->toBe(402)
        ->and($response['content']['application/json']['schema']['$ref'])->toBe('#/components/schemas/InsufficientBalanceErrorResponse');

    expect($generator->getErrorDefinitions())->toHaveCount(3);
});

test('a hand-written @OA\Response(ref=...) to an error response resolves and keeps its closure through pruning', function () {
    makeErrorGenerator($this->docsFile, $this->yamlFile)->generateDocs(); // validate_refs strict: would throw on a dangling ref
    $doc = json_decode(file_get_contents($this->docsFile), true);

    expect($doc['paths']['/api/purchase']['post']['responses']['402']['$ref'])->toBe('#/components/responses/InsufficientBalanceError')
        ->and($doc['components']['schemas'])->toHaveKeys(['InsufficientBalanceError', 'InsufficientBalanceErrorResponse']);
});

test('the error-code enum survives pruning as a reference page while unreferenced error responses are pruned', function () {
    makeErrorGenerator($this->docsFile, $this->yamlFile)->generateDocs();
    $doc = json_decode(file_get_contents($this->docsFile), true);

    expect($doc['components']['schemas'])->toHaveKey('ErrorCode')
        ->and($doc['components']['schemas']['ErrorCode']['enum'])->toBe(['insufficient_balance', 'unauthenticated', 'validation_failed'])
        ->and($doc['components']['responses'])->not->toHaveKey('ValidationError')
        ->and($doc['components']['schemas'])->not->toHaveKey('ValidationErrorResponse');
});

test('with pruning off every error response and envelope is emitted', function () {
    makeErrorGenerator($this->docsFile, $this->yamlFile, prune: false)->generateDocs();
    $doc = json_decode(file_get_contents($this->docsFile), true);

    expect($doc['components']['responses'])->toHaveKeys(['InsufficientBalanceError', 'ValidationError', 'UnauthenticatedError'])
        ->and($doc['components']['responses']['ValidationError']['x-http-status'])->toBe(422)
        ->and($doc['components']['responses']['UnauthenticatedError']['x-http-status'])->toBe(401)
        ->and($doc['components']['schemas']['ValidationErrorResponse']['properties'])->toHaveKey('errors')
        ->and($doc['components']['schemas']['ValidationErrorResponse']['properties'])->not->toHaveKey('details');
});
