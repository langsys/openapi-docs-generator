<?php

use Langsys\OpenApiDocsGenerator\Generators\DtoSchemaBuilder;
use Langsys\OpenApiDocsGenerator\Generators\ExampleGenerator;
use Langsys\OpenApiDocsGenerator\Generators\OpenApiGenerator;
use Psr\Log\NullLogger;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir() . '/openapi-info-' . uniqid();
    mkdir($this->tempDir, 0777, true);
    $this->docsFile = $this->tempDir . '/api-docs.json';
    $this->yamlFile = $this->tempDir . '/api-docs.yaml';
});

afterEach(function () {
    @unlink($this->docsFile);
    @unlink($this->yamlFile);
    @rmdir($this->tempDir);
});

// The DanglingFixtures controller carries @OA\Info(title: "Dangling API", version: "1.0.0").
function makeInfoGenerator(string $docsFile, string $yamlFile, ?array $infoOverride): OpenApiGenerator
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
        infoOverride: $infoOverride,
    );
}

function generatedInfo(string $docsFile): array
{
    return json_decode(file_get_contents($docsFile), true)['info'] ?? [];
}

test('info override replaces title and description while unspecified fields fall back to the annotation', function () {
    makeInfoGenerator($this->docsFile, $this->yamlFile, [
        'title' => 'Langsys Integration API',
        'description' => 'The API-key-authenticated subset of the Langsys API.',
    ])->generateDocs();

    $info = generatedInfo($this->docsFile);

    expect($info['title'])->toBe('Langsys Integration API')
        ->and($info['description'])->toBe('The API-key-authenticated subset of the Langsys API.')
        ->and($info['version'])->toBe('1.0.0'); // fell back to the scanned @OA\Info
});

test('no info override leaves the scanned annotation info intact', function () {
    makeInfoGenerator($this->docsFile, $this->yamlFile, null)->generateDocs();

    $info = generatedInfo($this->docsFile);

    expect($info['title'])->toBe('Dangling API')
        ->and($info['version'])->toBe('1.0.0');
});

test('info override deep-merges a nested contact and keeps the annotation version', function () {
    makeInfoGenerator($this->docsFile, $this->yamlFile, [
        'title' => 'Langsys Integration API',
        'contact' => ['name' => 'Integrations', 'email' => 'integrations@example.com'],
    ])->generateDocs();

    $info = generatedInfo($this->docsFile);

    expect($info['title'])->toBe('Langsys Integration API')
        ->and($info['version'])->toBe('1.0.0')
        ->and($info['contact']['name'])->toBe('Integrations')
        ->and($info['contact']['email'])->toBe('integrations@example.com');
});
