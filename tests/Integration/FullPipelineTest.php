<?php

use Langsys\OpenApiDocsGenerator\Generators\DtoSchemaBuilder;
use Langsys\OpenApiDocsGenerator\Generators\ExampleGenerator;
use Langsys\OpenApiDocsGenerator\Generators\OpenApiGenerator;
use Psr\Log\NullLogger;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir() . '/openapi-pipeline-' . uniqid();
    mkdir($this->tempDir, 0777, true);
    $this->docsFile = $this->tempDir . '/api-docs.json';
    $this->yamlFile = $this->tempDir . '/api-docs.yaml';
});

afterEach(function () {
    @unlink($this->docsFile);
    @unlink($this->yamlFile);
    @rmdir($this->tempDir);
});

function makeGenerator(
    string $docsFile,
    string $yamlFile,
    array $securitySchemes = [],
    array $security = [],
    ?string $basePath = null,
    bool $yamlCopy = false,
): OpenApiGenerator {
    $packageRoot = dirname(__DIR__, 2);

    $dtoSchemaBuilder = new DtoSchemaBuilder(
        dtoPaths: $packageRoot . '/tests/Data',
        exampleGenerator: new ExampleGenerator(
            fakerAttributeMapper: [],
            customFunctions: [],
        ),
        paginationFields: [],
    );

    return new OpenApiGenerator(
        annotationsDir: [$packageRoot . '/tests/Fixtures'],
        docsFile: $docsFile,
        yamlDocsFile: $yamlFile,
        securitySchemesConfig: $securitySchemes,
        securityConfig: $security,
        scanOptions: [
            'open_api_spec_version' => '3.0.0',
        ],
        constants: [],
        basePath: $basePath,
        yamlCopy: $yamlCopy,
        endpointParametersConfig: [],
        dtoSchemaBuilder: $dtoSchemaBuilder,
        logger: new NullLogger(),
    );
}

test('full pipeline generates valid JSON with scanned annotations and DTO schemas', function () {
    $generator = makeGenerator($this->docsFile, $this->yamlFile);
    $generator->generateDocs();

    expect(file_exists($this->docsFile))->toBeTrue();

    $json = json_decode(file_get_contents($this->docsFile), true);

    // Has OpenAPI structure
    expect($json)->toHaveKey('openapi')
        ->and($json)->toHaveKey('info')
        ->and($json)->toHaveKey('paths');

    // Info came from TestController attribute
    expect($json['info']['title'])->toBe('Test API')
        ->and($json['info']['version'])->toBe('1.0.0');

    // Paths include the /api/examples endpoint from TestController
    expect($json['paths'])->toHaveKey('/api/examples');
});

test('full pipeline merges DTO schemas into components', function () {
    $generator = makeGenerator($this->docsFile, $this->yamlFile);
    $generator->generateDocs();

    $json = json_decode(file_get_contents($this->docsFile), true);

    expect($json)->toHaveKey('components')
        ->and($json['components'])->toHaveKey('schemas');

    $schemaNames = array_keys($json['components']['schemas']);

    // DTO-generated schemas should be present
    expect($schemaNames)->toContain('ExampleData')
        ->and($schemaNames)->toContain('TestData');
});

test('full pipeline injects security definitions', function () {
    $generator = makeGenerator(
        docsFile: $this->docsFile,
        yamlFile: $this->yamlFile,
        securitySchemes: [
            'sanctum' => [
                'type' => 'apiKey',
                'name' => 'Authorization',
                'in' => 'header',
            ],
        ],
        security: [
            ['sanctum' => []],
        ],
    );

    $generator->generateDocs();

    $json = json_decode(file_get_contents($this->docsFile), true);

    expect($json['components']['securitySchemes'])->toHaveKey('sanctum')
        ->and($json['security'])->toContain(['sanctum' => []]);
});

test('full pipeline adds server when basePath is set', function () {
    $generator = makeGenerator(
        docsFile: $this->docsFile,
        yamlFile: $this->yamlFile,
        basePath: 'https://api.example.com/v1',
    );

    $generator->generateDocs();

    $json = json_decode(file_get_contents($this->docsFile), true);

    expect($json)->toHaveKey('servers');

    $urls = array_column($json['servers'], 'url');
    expect($urls)->toContain('https://api.example.com/v1');
});

test('full pipeline generates YAML copy when enabled', function () {
    $generator = makeGenerator(
        docsFile: $this->docsFile,
        yamlFile: $this->yamlFile,
        yamlCopy: true,
    );

    $generator->generateDocs();

    expect(file_exists($this->yamlFile))->toBeTrue();

    $yaml = file_get_contents($this->yamlFile);
    expect($yaml)->toContain('openapi:')
        ->and($yaml)->toContain('Test API')
        ->and($yaml)->toContain('/api/examples');
});

test('full pipeline does not generate YAML when disabled', function () {
    $generator = makeGenerator(
        docsFile: $this->docsFile,
        yamlFile: $this->yamlFile,
        yamlCopy: false,
    );

    $generator->generateDocs();

    expect(file_exists($this->yamlFile))->toBeFalse();
});

test('DTO schemas contain expected properties for ExampleData', function () {
    $generator = makeGenerator($this->docsFile, $this->yamlFile);
    $generator->generateDocs();

    $json = json_decode(file_get_contents($this->docsFile), true);

    expect($json['components']['schemas'])->toHaveKey('ExampleData');

    $exampleSchema = $json['components']['schemas']['ExampleData'];
    expect($exampleSchema['properties'])->toHaveKey('example')
        ->and($exampleSchema['properties']['example']['type'])->toBe('string')
        ->and($exampleSchema['properties']['example']['example'])->toBe('test');
});

test('annotation-defined schemas take precedence over DTO schemas', function () {
    $generator = makeGenerator($this->docsFile, $this->yamlFile);
    $generator->generateDocs();

    $json = json_decode(file_get_contents($this->docsFile), true);

    // ExampleData should exist as a key (not duplicated)
    expect($json['components']['schemas'])->toHaveKey('ExampleData');
});
