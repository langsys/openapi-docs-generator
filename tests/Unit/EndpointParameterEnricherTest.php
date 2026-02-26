<?php

use Langsys\OpenApiDocsGenerator\Contracts\EndpointParameterResolver;
use Langsys\OpenApiDocsGenerator\Data\EndpointParameterData;
use Langsys\OpenApiDocsGenerator\Generators\EndpointParameterEnricher;
use OpenApi\Annotations as OA;
use OpenApi\Generator;

function buildOpenApiWithRefParams(string $path, string $responseRef, array $paramRefs): OA\OpenApi
{
    $parameters = array_map(fn (string $ref) => new OA\Parameter([
        'ref' => '#/components/parameters/' . $ref,
    ]), $paramRefs);

    $response = new OA\Response([
        'response' => '200',
        'description' => 'OK',
    ]);

    $mediaType = new OA\MediaType([
        'mediaType' => 'application/json',
        'schema' => new OA\Schema(['ref' => '#/components/schemas/' . $responseRef]),
    ]);
    $response->content = [$mediaType];

    $operation = new OA\Get([
        'responses' => [$response],
        'parameters' => $parameters,
    ]);

    $pathItem = new OA\PathItem([
        'path' => $path,
    ]);
    $pathItem->get = $operation;

    $openapi = new OA\OpenApi([
        'info' => new OA\Info(['title' => 'Test', 'version' => '1.0']),
    ]);
    $openapi->paths = [$pathItem];

    return $openapi;
}

function buildMockResolver(?EndpointParameterData $data): EndpointParameterResolver
{
    return new class($data) implements EndpointParameterResolver {
        public ?string $lastEndpointPath = null;
        public ?string $lastResourceName = null;

        public function __construct(private ?EndpointParameterData $data) {}

        public function resolve(string $endpointPath, string $resourceName): ?EndpointParameterData
        {
            $this->lastEndpointPath = $endpointPath;
            $this->lastResourceName = $resourceName;
            return $this->data;
        }
    };
}

test('it replaces order_by ref parameter with inline parameter', function () {
    $openapi = buildOpenApiWithRefParams('/api/projects', 'ProjectPaginatedResponse', ['order_by']);

    $data = new EndpointParameterData(
        orderableFields: ['title', 'created_at'],
        defaultOrder: [['created_at', 'desc']],
    );

    $resolver = buildMockResolver($data);
    $enricher = new EndpointParameterEnricher(resolver: $resolver);
    $enricher->enrich($openapi);

    $params = $openapi->paths[0]->get->parameters;
    expect($params)->toHaveCount(1);

    $param = $params[0];
    expect($param->name)->toBe('order_by')
        ->and($param->in)->toBe('query')
        ->and($param->required)->toBeFalse()
        ->and($param->description)->toContain('title')
        ->and($param->description)->toContain('created_at')
        ->and($param->description)->toContain('**Orderable fields:**')
        ->and($param->description)->toContain('**Default order:**')
        ->and($param->example)->toBe('created_at:desc');
});

test('it replaces filter_by ref parameter with inline parameter', function () {
    $openapi = buildOpenApiWithRefParams('/api/projects', 'ProjectListResponse', ['filter_by']);

    $data = new EndpointParameterData(
        filterableFields: ['status', 'type'],
        defaultFilters: [['status', 'active']],
    );

    $resolver = buildMockResolver($data);
    $enricher = new EndpointParameterEnricher(resolver: $resolver);
    $enricher->enrich($openapi);

    $params = $openapi->paths[0]->get->parameters;
    expect($params)->toHaveCount(1);

    $param = $params[0];
    expect($param->name)->toBe('filter_by')
        ->and($param->in)->toBe('query')
        ->and($param->description)->toContain('status')
        ->and($param->description)->toContain('type')
        ->and($param->description)->toContain('**Filterable fields:**')
        ->and($param->example)->toBe('status:active');
});

test('it replaces both order_by and filter_by ref parameters', function () {
    $openapi = buildOpenApiWithRefParams('/api/projects', 'ProjectPaginatedResponse', ['order_by', 'filter_by']);

    $data = new EndpointParameterData(
        orderableFields: ['title'],
        defaultOrder: [['title', 'asc']],
        filterableFields: ['status'],
        defaultFilters: [],
    );

    $resolver = buildMockResolver($data);
    $enricher = new EndpointParameterEnricher(resolver: $resolver);
    $enricher->enrich($openapi);

    $params = $openapi->paths[0]->get->parameters;
    expect($params)->toHaveCount(2);

    $paramNames = array_map(fn (OA\Parameter $p) => $p->name, $params);
    expect($paramNames)->toContain('order_by')
        ->and($paramNames)->toContain('filter_by');
});

test('it infers resource name from PaginatedResponse ref', function () {
    $openapi = buildOpenApiWithRefParams('/api/projects', 'ProjectPaginatedResponse', ['order_by']);

    $data = new EndpointParameterData(orderableFields: ['title']);

    $resolver = buildMockResolver($data);
    $enricher = new EndpointParameterEnricher(resolver: $resolver);
    $enricher->enrich($openapi);

    expect($resolver->lastResourceName)->toBe('ProjectResource');
});

test('it infers resource name from ListResponse ref', function () {
    $openapi = buildOpenApiWithRefParams('/api/users', 'UserListResponse', ['order_by']);

    $data = new EndpointParameterData(orderableFields: ['name']);

    $resolver = buildMockResolver($data);
    $enricher = new EndpointParameterEnricher(resolver: $resolver);
    $enricher->enrich($openapi);

    expect($resolver->lastResourceName)->toBe('UserResource');
});

test('it infers resource name from Response ref', function () {
    $openapi = buildOpenApiWithRefParams('/api/users', 'UserResponse', ['order_by']);

    $data = new EndpointParameterData(orderableFields: ['name']);

    $resolver = buildMockResolver($data);
    $enricher = new EndpointParameterEnricher(resolver: $resolver);
    $enricher->enrich($openapi);

    expect($resolver->lastResourceName)->toBe('UserResource');
});

test('it strips leading slash from endpoint path', function () {
    $openapi = buildOpenApiWithRefParams('/api/projects', 'ProjectPaginatedResponse', ['order_by']);

    $data = new EndpointParameterData(orderableFields: ['title']);

    $resolver = buildMockResolver($data);
    $enricher = new EndpointParameterEnricher(resolver: $resolver);
    $enricher->enrich($openapi);

    expect($resolver->lastEndpointPath)->toBe('api/projects');
});

test('it skips operations without parameters', function () {
    $response = new OA\Response([
        'response' => '200',
        'description' => 'OK',
    ]);

    $operation = new OA\Get(['responses' => [$response]]);

    $pathItem = new OA\PathItem(['path' => '/api/test']);
    $pathItem->get = $operation;

    $openapi = new OA\OpenApi([
        'info' => new OA\Info(['title' => 'Test', 'version' => '1.0']),
    ]);
    $openapi->paths = [$pathItem];

    $resolver = buildMockResolver(new EndpointParameterData());
    $enricher = new EndpointParameterEnricher(resolver: $resolver);

    // Should not throw
    $enricher->enrich($openapi);

    expect($resolver->lastEndpointPath)->toBeNull();
});

test('it skips when resolver returns null', function () {
    $openapi = buildOpenApiWithRefParams('/api/projects', 'ProjectPaginatedResponse', ['order_by']);

    $resolver = buildMockResolver(null);
    $enricher = new EndpointParameterEnricher(resolver: $resolver);
    $enricher->enrich($openapi);

    $params = $openapi->paths[0]->get->parameters;
    // Original ref parameter should be preserved
    expect($params)->toHaveCount(1)
        ->and($params[0]->ref)->toBe('#/components/parameters/order_by');
});

test('it preserves non-ref parameters', function () {
    $openapi = buildOpenApiWithRefParams('/api/projects', 'ProjectPaginatedResponse', ['order_by']);

    // Add a non-ref parameter
    $nonRefParam = new OA\Parameter([
        'name' => 'page',
        'in' => 'query',
        'required' => false,
    ]);
    $openapi->paths[0]->get->parameters[] = $nonRefParam;

    $data = new EndpointParameterData(orderableFields: ['title']);
    $resolver = buildMockResolver($data);
    $enricher = new EndpointParameterEnricher(resolver: $resolver);
    $enricher->enrich($openapi);

    $params = $openapi->paths[0]->get->parameters;
    expect($params)->toHaveCount(2);

    $names = array_map(fn (OA\Parameter $p) => $p->name, $params);
    expect($names)->toContain('order_by')
        ->and($names)->toContain('page');
});

test('it includes vendor extensions when enabled', function () {
    $openapi = buildOpenApiWithRefParams('/api/projects', 'ProjectPaginatedResponse', ['order_by']);

    $data = new EndpointParameterData(
        orderableFields: ['title', 'created_at'],
        defaultOrder: [['created_at', 'desc']],
    );

    $resolver = buildMockResolver($data);
    $enricher = new EndpointParameterEnricher(
        resolver: $resolver,
        includeExtensions: true,
    );
    $enricher->enrich($openapi);

    $param = $openapi->paths[0]->get->parameters[0];
    expect($param->x)->not->toBe(Generator::UNDEFINED)
        ->and($param->x)->toHaveKey('orderable-fields')
        ->and($param->x)->toHaveKey('default-order');
});

test('it merges global orderable fields with endpoint fields', function () {
    $openapi = buildOpenApiWithRefParams('/api/projects', 'ProjectPaginatedResponse', ['order_by']);

    $data = new EndpointParameterData(
        orderableFields: ['title'],
        defaultOrder: [],
    );

    $resolver = buildMockResolver($data);
    $enricher = new EndpointParameterEnricher(
        resolver: $resolver,
        globalOrderableFields: ['created_at', 'updated_at'],
        includeExtensions: true,
    );
    $enricher->enrich($openapi);

    $param = $openapi->paths[0]->get->parameters[0];
    expect($param->description)->toContain('title')
        ->and($param->description)->toContain('created_at')
        ->and($param->description)->toContain('updated_at');
});

test('it does nothing when openapi has no paths', function () {
    $openapi = new OA\OpenApi([
        'info' => new OA\Info(['title' => 'Test', 'version' => '1.0']),
    ]);

    $resolver = buildMockResolver(new EndpointParameterData());
    $enricher = new EndpointParameterEnricher(resolver: $resolver);

    // Should not throw
    $enricher->enrich($openapi);
    expect($openapi->paths)->toBe(Generator::UNDEFINED);
});
