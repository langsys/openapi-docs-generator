<?php

use Langsys\OpenApiDocsGenerator\Exceptions\OpenApiDocsException;
use Langsys\OpenApiDocsGenerator\Generators\DtoSchemaBuilder;
use Langsys\OpenApiDocsGenerator\Generators\ExampleGenerator;
use Langsys\OpenApiDocsGenerator\Generators\GeneratorFactory;
use Langsys\OpenApiDocsGenerator\Generators\OpenApiGenerator;
use Langsys\OpenApiDocsGenerator\Generators\QueryParameterAttacher;
use Langsys\OpenApiDocsGenerator\Tests\QueryParameterFixtures\FixedEndpointParameterResolver;
use Langsys\OpenApiDocsGenerator\Tests\QueryParameterFixtures\UnknownFieldEndpointParameterResolver;
use Psr\Log\NullLogger;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir() . '/openapi-query-' . uniqid();
    mkdir($this->tempDir, 0777, true);
    $this->docsFile = $this->tempDir . '/api-docs.json';
});

afterEach(function () {
    @unlink($this->docsFile);
    @rmdir($this->tempDir);
});

function generateWithQueryParameters(string $docsFile, array $endpointParameters = []): array
{
    $fixturesDir = dirname(__DIR__) . '/QueryParameterFixtures';
    $builder = new DtoSchemaBuilder($fixturesDir, new ExampleGenerator([], []), []);

    (new OpenApiGenerator(
        annotationsDir: [$fixturesDir],
        docsFile: $docsFile,
        yamlDocsFile: dirname($docsFile) . '/api-docs.yaml',
        securitySchemesConfig: [],
        securityConfig: [],
        scanOptions: ['open_api_spec_version' => '3.0.0'],
        constants: [],
        basePath: null,
        yamlCopy: false,
        endpointParametersConfig: $endpointParameters,
        dtoSchemaBuilder: $builder,
        logger: new NullLogger(),
        validateRefs: 'strict',
        queryParameterAttacher: new QueryParameterAttacher($builder),
    ))->generateDocs();

    return json_decode(file_get_contents($docsFile), true);
}

/** @return array<int, string> each parameter's name, or its `$ref` */
function queryParameterNames(array $operation): array
{
    return array_map(
        static fn (array $parameter): string => $parameter['name'] ?? $parameter['$ref'],
        $operation['parameters'] ?? [],
    );
}

test('an action taking a marked DTO documents its properties, sharing the list components by $ref', function () {
    $doc = generateWithQueryParameters($this->docsFile); // validate_refs strict: every $ref must resolve

    expect(queryParameterNames($doc['paths']['/api/projects']['get']))->toBe([
        '#/components/parameters/page',
        '#/components/parameters/records_per_page',
        '#/components/parameters/order_by',
        '#/components/parameters/filter_by',
        'status', 'cursor', 'search', 'ids[]', 'include_archived', 'owner', 'meta',
    ])
        ->and($doc['components']['parameters'])->toHaveKeys(['page', 'records_per_page', 'order_by', 'filter_by']);
});

test('they follow the hand-written path parameter', function () {
    $doc = generateWithQueryParameters($this->docsFile);

    expect(queryParameterNames($doc['paths']['/api/projects/{projectId}/children']['get'])[0])
        ->toBe('#/components/parameters/projectId');
});

test('an action taking an unmarked DTO is documented as before', function () {
    $doc = generateWithQueryParameters($this->docsFile);

    expect($doc['paths']['/api/plain']['get'])->not->toHaveKey('parameters');
});

test('a documentation set built from config documents them', function () {
    config()->set('openapi-docs.documentations', ['query' => [
        'paths' => [
            'annotations' => [dirname(__DIR__) . '/QueryParameterFixtures'],
            'docs' => $this->tempDir,
            'docs_json' => 'api-docs.json',
        ],
        'scan_options' => ['open_api_spec_version' => '3.0.0'],
        'security_definitions' => ['security_schemes' => [], 'security' => []],
    ]]);

    GeneratorFactory::make('query', new NullLogger())->generateDocs();
    $doc = json_decode(file_get_contents($this->docsFile), true);

    expect(queryParameterNames($doc['paths']['/api/projects']['get']))->toContain('status', 'ids[]');
});

test('endpoint enrichment still replaces the order_by and filter_by refs it adds', function () {
    $doc = generateWithQueryParameters($this->docsFile, [
        'enabled' => true,
        'resolver' => FixedEndpointParameterResolver::class,
    ]);

    $parameters = $doc['paths']['/api/projects']['get']['parameters'];

    expect(queryParameterNames($doc['paths']['/api/projects']['get'])[0])->toBe('#/components/parameters/page')
        ->and($parameters[2]['name'])->toBe('order_by')
        ->and($parameters[2]['description'])->toContain('**Orderable fields:** `name`')
        ->and($parameters[3]['name'])->toBe('filter_by')
        // From ProjectResource's #[Example('Acme')], read off the Project schema built from it.
        ->and($parameters[3]['example'])->toBe('name:Acme');
});

test('endpoint enrichment fails generation when a list names a field its resource lacks', function () {
    expect(fn () => generateWithQueryParameters($this->docsFile, [
        'enabled' => true,
        'resolver' => UnknownFieldEndpointParameterResolver::class,
    ]))->toThrow(OpenApiDocsException::class, '- GET /api/projects, ProjectResource: orderable `created_at`')
        ->and(file_exists($this->docsFile))->toBeFalse();
});
