<?php

use Langsys\OpenApiDocsGenerator\Exceptions\OpenApiDocsException;
use Langsys\OpenApiDocsGenerator\Generators\DtoSchemaBuilder;
use Langsys\OpenApiDocsGenerator\Generators\ExampleGenerator;
use Langsys\OpenApiDocsGenerator\Generators\OpenApiGenerator;
use Psr\Log\NullLogger;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir() . '/openapi-refval-' . uniqid();
    mkdir($this->tempDir, 0777, true);
    $this->docsFile = $this->tempDir . '/api-docs.json';
    $this->yamlFile = $this->tempDir . '/api-docs.yaml';
});

afterEach(function () {
    @unlink($this->docsFile);
    @unlink($this->yamlFile);
    @rmdir($this->tempDir);
});

function makeDanglingGenerator(string $docsFile, string $yamlFile, string $validateRefs): OpenApiGenerator
{
    $dir = dirname(__DIR__, 2) . '/tests/DanglingFixtures';

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
        validateRefs: $validateRefs,
    );
}

test('validate_refs off does not detect the dangling ref', function () {
    $generator = makeDanglingGenerator($this->docsFile, $this->yamlFile, 'off');
    $generator->generateDocs();

    expect($generator->getUnresolvedReferences())->toBe([])
        ->and(file_exists($this->docsFile))->toBeTrue();
});

test('validate_refs warn reports the dangling ref but still writes the file', function () {
    $generator = makeDanglingGenerator($this->docsFile, $this->yamlFile, 'warn');
    $generator->generateDocs();

    $unresolved = $generator->getUnresolvedReferences();

    expect(array_column($unresolved, 'ref'))->toContain('#/components/parameters/missingParam')
        ->and($unresolved[0]['location'])->toBe('GET /api/thing')
        ->and(file_exists($this->docsFile))->toBeTrue();
});

test('validate_refs strict aborts generation and does not write the file', function () {
    $generator = makeDanglingGenerator($this->docsFile, $this->yamlFile, 'strict');

    expect(fn () => $generator->generateDocs())->toThrow(OpenApiDocsException::class);
    expect(file_exists($this->docsFile))->toBeFalse();
});
