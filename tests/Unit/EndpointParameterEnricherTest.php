<?php

use Langsys\OpenApiDocsGenerator\Contracts\EndpointParameterResolver;
use Langsys\OpenApiDocsGenerator\Data\EndpointParameterData;
use Langsys\OpenApiDocsGenerator\Exceptions\OpenApiDocsException;
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

test('order_by description omits Default order line when there is no default', function () {
    $openapi = buildOpenApiWithRefParams('/api/projects', 'ProjectListResponse', ['order_by']);

    $data = new EndpointParameterData(
        orderableFields: ['title'],
        defaultOrder: [],
    );

    $resolver = buildMockResolver($data);
    $enricher = new EndpointParameterEnricher(resolver: $resolver);
    $enricher->enrich($openapi);

    $description = $openapi->paths[0]->get->parameters[0]->description;

    expect($description)->not->toContain('**Default order:**')
        ->and($description)->not->toContain('none');
});

test('filter_by description omits Default filters line when there is no default', function () {
    $openapi = buildOpenApiWithRefParams('/api/projects', 'ProjectListResponse', ['filter_by']);

    $data = new EndpointParameterData(
        filterableFields: ['status'],
        defaultFilters: [],
    );

    $resolver = buildMockResolver($data);
    $enricher = new EndpointParameterEnricher(resolver: $resolver);
    $enricher->enrich($openapi);

    $description = $openapi->paths[0]->get->parameters[0]->description;

    expect($description)->not->toContain('**Default filters:**')
        ->and($description)->not->toContain('none');
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

test('filter_by description lists all supported operators', function () {
    $openapi = buildOpenApiWithRefParams('/api/projects', 'ProjectPaginatedResponse', ['filter_by']);

    $data = new EndpointParameterData(filterableFields: ['status']);

    $resolver = buildMockResolver($data);
    $enricher = new EndpointParameterEnricher(resolver: $resolver);
    $enricher->enrich($openapi);

    $description = $openapi->paths[0]->get->parameters[0]->description;

    expect($description)->toContain('Operators:')
        ->and($description)->toContain('field:value')
        ->and($description)->toContain('field:null')
        ->and($description)->toContain('field:!null')
        ->and($description)->toContain('comparison')
        ->and($description)->toContain('>=')
        ->and($description)->toContain('<=')
        ->and($description)->toContain('numeric fields')
        ->and($description)->toContain('date comparison')
        ->and($description)->toContain('`YYYY-MM-DD`')
        ->and($description)->toContain('Multiple filters:');
});

test('filter_by description adds per-field operator hints when fieldTypes are populated', function () {
    $openapi = buildOpenApiWithRefParams('/api/projects', 'ProjectPaginatedResponse', ['filter_by']);

    $data = new EndpointParameterData(
        filterableFields: ['description', 'amount', 'last_used_at', 'status'],
        fieldTypes: [
            'description' => ['type' => 'string', 'nullable' => true],
            'amount' => ['type' => 'int', 'nullable' => false],
            'last_used_at' => ['type' => 'string', 'nullable' => true],
            'status' => ['type' => 'string', 'nullable' => false],
        ],
    );

    $resolver = buildMockResolver($data);
    $enricher = new EndpointParameterEnricher(resolver: $resolver);
    $enricher->enrich($openapi);

    $description = $openapi->paths[0]->get->parameters[0]->description;

    expect($description)->toContain('**Supports null check')
        ->and($description)->toContain('`description`, `last_used_at`')
        ->and($description)->toContain('**Supports comparison')
        ->and($description)->toContain('`amount`');
});

test('filter_by description omits comparison line when no numeric fields', function () {
    $openapi = buildOpenApiWithRefParams('/api/projects', 'ProjectPaginatedResponse', ['filter_by']);

    $data = new EndpointParameterData(
        filterableFields: ['status', 'description'],
        fieldTypes: [
            'status' => ['type' => 'string', 'nullable' => false],
            'description' => ['type' => 'string', 'nullable' => true],
        ],
    );

    $resolver = buildMockResolver($data);
    $enricher = new EndpointParameterEnricher(resolver: $resolver);
    $enricher->enrich($openapi);

    $description = $openapi->paths[0]->get->parameters[0]->description;

    expect($description)->toContain('**Supports null check')
        ->and($description)->not->toContain('**Supports comparison');
});

test('filter_by description omits both per-field lines when fieldTypes is empty', function () {
    $openapi = buildOpenApiWithRefParams('/api/projects', 'ProjectPaginatedResponse', ['filter_by']);

    $data = new EndpointParameterData(
        filterableFields: ['status'],
        fieldTypes: [],
    );

    $resolver = buildMockResolver($data);
    $enricher = new EndpointParameterEnricher(resolver: $resolver);
    $enricher->enrich($openapi);

    $description = $openapi->paths[0]->get->parameters[0]->description;

    expect($description)->not->toContain('**Supports null check')
        ->and($description)->not->toContain('**Supports comparison')
        ->and($description)->toContain('**Filterable fields:**');
});

test('filter_by description excludes fields that are filterable but missing from fieldTypes', function () {
    $openapi = buildOpenApiWithRefParams('/api/projects', 'ProjectPaginatedResponse', ['filter_by']);

    $data = new EndpointParameterData(
        filterableFields: ['amount', 'orphan_field'],
        fieldTypes: [
            'amount' => ['type' => 'int', 'nullable' => true],
            // orphan_field intentionally omitted — misconfigured filterable row
        ],
    );

    $resolver = buildMockResolver($data);
    $enricher = new EndpointParameterEnricher(resolver: $resolver);
    $enricher->enrich($openapi);

    $description = $openapi->paths[0]->get->parameters[0]->description;

    // Extract just the per-field operator lines so we don't confuse them with the Filterable fields line.
    expect($description)->toContain('**Filterable fields:** `amount`, `orphan_field`');

    preg_match('/\*\*Supports null check[^\n]*/', $description, $nullLine);
    preg_match('/\*\*Supports comparison[^\n]*/', $description, $compLine);

    expect($nullLine[0] ?? '')->toContain('`amount`')
        ->and($nullLine[0] ?? '')->not->toContain('orphan_field')
        ->and($compLine[0] ?? '')->toContain('`amount`')
        ->and($compLine[0] ?? '')->not->toContain('orphan_field');
});

/** Puts $schemas in `components.schemas`, where the enricher finds a list's resource. */
function withResourceSchemas(OA\OpenApi $openapi, OA\Schema ...$schemas): OA\OpenApi
{
    $openapi->components = new OA\Components(['schemas' => $schemas]);

    return $openapi;
}

/** The `Project` resource schema `Project*Response` refs resolve to. */
function projectResourceSchema(OA\Property ...$properties): OA\Schema
{
    return new OA\Schema(['schema' => 'Project', 'type' => 'object', 'properties' => $properties]);
}

test('it leaves filter_by out of a list with no filterable fields, keeping order_by', function () {
    $openapi = buildOpenApiWithRefParams('/api/projects', 'ProjectPaginatedResponse', ['order_by', 'filter_by']);

    $data = new EndpointParameterData(
        orderableFields: ['title'],
        defaultFilters: [['status', 'active']],
    );

    (new EndpointParameterEnricher(resolver: buildMockResolver($data)))->enrich($openapi);

    $params = $openapi->paths[0]->get->parameters;
    expect($params)->toHaveCount(1)
        ->and($params[0]->name)->toBe('order_by');
});

test('filter_by example comes from the first filterable field the resource schema shows an example for', function () {
    $openapi = withResourceSchemas(
        buildOpenApiWithRefParams('/api/projects', 'ProjectPaginatedResponse', ['filter_by']),
        projectResourceSchema(
            new OA\Property(['property' => 'title', 'type' => 'string']),
            new OA\Property(['property' => 'tags', 'type' => 'array', 'example' => ['a', 'b']]),
            new OA\Property(['property' => 'status', 'type' => 'string', 'example' => 'paid']),
            new OA\Property(['property' => 'type', 'type' => 'string', 'example' => 'business']),
        ),
    );

    $data = new EndpointParameterData(filterableFields: ['title', 'tags', 'status', 'type']);

    (new EndpointParameterEnricher(resolver: buildMockResolver($data)))->enrich($openapi);

    expect($openapi->paths[0]->get->parameters[0]->example)->toBe('status:paid');
});

test('filter_by example writes a boolean as true or false', function (bool $value, string $expected) {
    $openapi = withResourceSchemas(
        buildOpenApiWithRefParams('/api/projects', 'ProjectPaginatedResponse', ['filter_by']),
        projectResourceSchema(new OA\Property(['property' => 'admin', 'type' => 'boolean', 'example' => $value])),
    );

    $data = new EndpointParameterData(filterableFields: ['admin']);

    (new EndpointParameterEnricher(resolver: buildMockResolver($data)))->enrich($openapi);

    expect($openapi->paths[0]->get->parameters[0]->example)->toBe($expected);
})->with([
    'false' => [false, 'admin:false'],
    'true' => [true, 'admin:true'],
]);

test('filter_by example takes the first case of the enum a property refers to', function (array $reference) {
    $openapi = withResourceSchemas(
        buildOpenApiWithRefParams('/api/projects', 'ProjectPaginatedResponse', ['filter_by']),
        projectResourceSchema(new OA\Property(['property' => 'platform', ...$reference])),
        new OA\Schema(['schema' => 'Platform', 'type' => 'string', 'enum' => ['android', 'ios']]),
    );

    $data = new EndpointParameterData(filterableFields: ['platform']);

    (new EndpointParameterEnricher(resolver: buildMockResolver($data)))->enrich($openapi);

    expect($openapi->paths[0]->get->parameters[0]->example)->toBe('platform:android');
})->with([
    '$ref' => [['ref' => '#/components/schemas/Platform']],
    'nullable allOf' => [['allOf' => [new OA\Schema(['ref' => '#/components/schemas/Platform'])], 'nullable' => true]],
]);

test('a default filter wins over the resource schema example', function () {
    $openapi = withResourceSchemas(
        buildOpenApiWithRefParams('/api/keys', 'ProjectPaginatedResponse', ['filter_by']),
        projectResourceSchema(
            new OA\Property(['property' => 'name', 'type' => 'string', 'example' => 'Deploy key']),
            new OA\Property(['property' => 'active', 'type' => 'boolean', 'example' => false]),
        ),
    );

    $data = new EndpointParameterData(
        filterableFields: ['name', 'active'],
        defaultFilters: [['active', 'true']],
    );

    (new EndpointParameterEnricher(resolver: buildMockResolver($data)))->enrich($openapi);

    expect($openapi->paths[0]->get->parameters[0]->example)->toBe('active:true');
});

test('filter_by example falls back to the first field when the resource shows no value for any', function (array $schemas) {
    $openapi = withResourceSchemas(
        buildOpenApiWithRefParams('/api/projects', 'ProjectPaginatedResponse', ['filter_by']),
        ...$schemas,
    );

    $data = new EndpointParameterData(filterableFields: ['title', 'owner']);

    (new EndpointParameterEnricher(resolver: buildMockResolver($data)))->enrich($openapi);

    expect($openapi->paths[0]->get->parameters[0]->example)->toBe('title:value');
})->with([
    'no example, and a $ref to an object' => [[
        projectResourceSchema(
            new OA\Property(['property' => 'title', 'type' => 'string']),
            new OA\Property(['property' => 'owner', 'ref' => '#/components/schemas/User']),
        ),
        new OA\Schema(['schema' => 'User', 'type' => 'object']),
    ]],
    'no resource schema in components' => [[
        new OA\Schema(['schema' => 'User', 'type' => 'object']),
    ]],
]);

test('filter_by example skips a value a filter can\'t write', function (OA\Property $first, string $expected) {
    $openapi = withResourceSchemas(
        buildOpenApiWithRefParams('/api/projects', 'ProjectPaginatedResponse', ['filter_by']),
        projectResourceSchema(
            $first,
            new OA\Property(['property' => 'name', 'type' => 'string', 'example' => 'Acme']),
        ),
    );

    $data = new EndpointParameterData(filterableFields: [(string) $first->property, 'name']);

    (new EndpointParameterEnricher(resolver: buildMockResolver($data)))->enrich($openapi);

    expect($openapi->paths[0]->get->parameters[0]->example)->toBe($expected);
})->with([
    // `field:<op>:value`: the colons would read as an operator.
    'a date-time' => [new OA\Property(['property' => 'created_at', 'type' => 'string', 'format' => 'date-time', 'example' => '2024-01-15T10:30:00+00:00']), 'name:Acme'],
    'a URL' => [new OA\Property(['property' => 'website_url', 'type' => 'string', 'example' => 'https://example.com']), 'name:Acme'],
    'an empty string' => [new OA\Property(['property' => 'notes', 'type' => 'string', 'example' => '']), 'name:Acme'],
    'a nullable allOf to an object' => [new OA\Property([
        'property' => 'owner',
        'allOf' => [new OA\Schema(['ref' => '#/components/schemas/User'])],
        'nullable' => true,
    ]), 'name:Acme'],
    'zero, kept' => [new OA\Property(['property' => 'retries', 'type' => 'integer', 'example' => 0]), 'retries:0'],
    'the string "0", kept' => [new OA\Property(['property' => 'code', 'type' => 'string', 'example' => '0']), 'code:0'],
]);

test('filter_by example falls back when every value has a colon', function () {
    $openapi = withResourceSchemas(
        buildOpenApiWithRefParams('/api/projects', 'ProjectPaginatedResponse', ['filter_by']),
        projectResourceSchema(new OA\Property(['property' => 'created_at', 'type' => 'string', 'example' => '2024-01-15T10:30:00+00:00'])),
    );

    $data = new EndpointParameterData(filterableFields: ['created_at']);

    (new EndpointParameterEnricher(resolver: buildMockResolver($data)))->enrich($openapi);

    expect($openapi->paths[0]->get->parameters[0]->example)->toBe('created_at:value');
});

test('filter_by example takes an enum\'s first case a filter can write', function (array $property, string $expected) {
    $openapi = withResourceSchemas(
        buildOpenApiWithRefParams('/api/projects', 'ProjectPaginatedResponse', ['filter_by']),
        projectResourceSchema(new OA\Property(['property' => 'platform', ...$property])),
        new OA\Schema(['schema' => 'Platform', 'type' => 'string', 'enum' => [null, 'android', 'ios'], 'nullable' => true]),
    );

    $data = new EndpointParameterData(filterableFields: ['platform']);

    (new EndpointParameterEnricher(resolver: buildMockResolver($data)))->enrich($openapi);

    expect($openapi->paths[0]->get->parameters[0]->example)->toBe($expected);
})->with([
    'inline, with no example' => [['type' => 'string', 'enum' => ['web', 'android']], 'platform:web'],
    'a $ref whose first case is null' => [['ref' => '#/components/schemas/Platform'], 'platform:android'],
    'its own example over its $ref\'s cases' => [[
        'allOf' => [new OA\Schema(['ref' => '#/components/schemas/Platform'])],
        'nullable' => true,
        'example' => 'ios',
    ], 'platform:ios'],
]);

test('filter_by is kept when it is not among the parameters to enrich', function () {
    $openapi = buildOpenApiWithRefParams('/api/projects', 'ProjectPaginatedResponse', ['order_by', 'filter_by']);

    $data = new EndpointParameterData(orderableFields: ['title']);

    (new EndpointParameterEnricher(resolver: buildMockResolver($data), parameterNames: ['order_by']))->enrich($openapi);

    $params = $openapi->paths[0]->get->parameters;
    expect($params)->toHaveCount(2)
        ->and($params[0]->name)->toBe('order_by')
        ->and($params[1]->ref)->toBe('#/components/parameters/filter_by');
});

test('it fails naming the path, the resource and the fields the resource lacks', function () {
    $openapi = withResourceSchemas(
        buildOpenApiWithRefParams('/api/projects', 'ProjectPaginatedResponse', ['order_by', 'filter_by']),
        projectResourceSchema(
            new OA\Property(['property' => 'name', 'type' => 'string']),
            new OA\Property(['property' => 'status', 'type' => 'string']),
        ),
    );

    $data = new EndpointParameterData(
        orderableFields: ['name', 'created_at'],
        filterableFields: ['status', 'action', 'expires_at'],
    );

    expect(fn () => (new EndpointParameterEnricher(resolver: buildMockResolver($data)))->enrich($openapi))
        ->toThrow(
            OpenApiDocsException::class,
            '- GET /api/projects, ProjectResource: orderable `created_at`; filterable `action`, `expires_at`',
        );
});

test('it lists every operation whose fields its resource lacks in one failure', function () {
    $openapi = buildOpenApiWithRefParams('/api/projects', 'ProjectPaginatedResponse', ['order_by']);
    $openapi->paths[] = buildOpenApiWithRefParams('/api/projects/archived', 'ProjectListResponse', ['filter_by'])->paths[0];
    withResourceSchemas($openapi, projectResourceSchema(new OA\Property(['property' => 'name', 'type' => 'string'])));

    $data = new EndpointParameterData(orderableFields: ['name'], filterableFields: ['action']);

    expect(fn () => (new EndpointParameterEnricher(resolver: buildMockResolver($data)))->enrich($openapi))
        ->toThrow(
            OpenApiDocsException::class,
            "- GET /api/projects, ProjectResource: filterable `action`\n- GET /api/projects/archived, ProjectResource: filterable `action`",
        );
});

test('a list whose filter_by is left out is still checked', function () {
    $openapi = withResourceSchemas(
        buildOpenApiWithRefParams('/api/projects', 'ProjectPaginatedResponse', ['filter_by']),
        projectResourceSchema(new OA\Property(['property' => 'name', 'type' => 'string'])),
    );

    $data = new EndpointParameterData(orderableFields: ['created_at']);

    expect(fn () => (new EndpointParameterEnricher(resolver: buildMockResolver($data)))->enrich($openapi))
        ->toThrow(OpenApiDocsException::class, 'GET /api/projects, ProjectResource: orderable `created_at`');
});

test('the field check passes when the resource has every field', function () {
    $openapi = withResourceSchemas(
        buildOpenApiWithRefParams('/api/projects', 'ProjectPaginatedResponse', ['order_by', 'filter_by']),
        projectResourceSchema(
            new OA\Property(['property' => 'name', 'type' => 'string']),
            new OA\Property(['property' => 'status', 'type' => 'string']),
        ),
    );

    $data = new EndpointParameterData(orderableFields: ['name'], filterableFields: ['status', 'name']);

    (new EndpointParameterEnricher(resolver: buildMockResolver($data)))->enrich($openapi);

    expect($openapi->paths[0]->get->parameters)->toHaveCount(2);
});

test('the field check is skipped when the resource schema is not in components or has no properties', function (array $schemas) {
    $openapi = withResourceSchemas(
        buildOpenApiWithRefParams('/api/projects', 'ProjectPaginatedResponse', ['order_by', 'filter_by']),
        ...$schemas,
    );

    $data = new EndpointParameterData(orderableFields: ['created_at'], filterableFields: ['action']);

    (new EndpointParameterEnricher(resolver: buildMockResolver($data)))->enrich($openapi);

    expect($openapi->paths[0]->get->parameters)->toHaveCount(2);
})->with([
    'not in components' => [[new OA\Schema(['schema' => 'User', 'type' => 'object'])]],
    'an allOf composition' => [[new OA\Schema([
        'schema' => 'Project',
        'allOf' => [new OA\Schema(['ref' => '#/components/schemas/User'])],
    ])]],
]);

test('an operation that documents neither order_by nor filter_by is not checked', function () {
    $openapi = withResourceSchemas(
        buildOpenApiWithRefParams('/api/projects', 'ProjectPaginatedResponse', ['page']),
        projectResourceSchema(new OA\Property(['property' => 'name', 'type' => 'string'])),
    );

    $data = new EndpointParameterData(orderableFields: ['created_at'], filterableFields: ['action']);

    (new EndpointParameterEnricher(resolver: buildMockResolver($data)))->enrich($openapi);

    expect($openapi->paths[0]->get->parameters[0]->ref)->toBe('#/components/parameters/page');
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
