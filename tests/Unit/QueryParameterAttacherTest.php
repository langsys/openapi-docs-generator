<?php

use Illuminate\Routing\Route;
use Langsys\OpenApiDocsGenerator\Contracts\RouteResolver;
use Langsys\OpenApiDocsGenerator\Data\ResolvableOperation;
use Langsys\OpenApiDocsGenerator\Data\ResolvedRoute;
use Langsys\OpenApiDocsGenerator\Exceptions\OpenApiDocsException;
use Langsys\OpenApiDocsGenerator\Generators\Attributes\QueryParameters;
use Langsys\OpenApiDocsGenerator\Generators\DtoSchemaBuilder;
use Langsys\OpenApiDocsGenerator\Generators\ExampleGenerator;
use Langsys\OpenApiDocsGenerator\Generators\QueryParameterAttacher;
use Langsys\OpenApiDocsGenerator\Tests\QueryParameterFixtures\ListProjectsRequest;
use Langsys\OpenApiDocsGenerator\Tests\QueryParameterFixtures\ListRequest;
use Langsys\OpenApiDocsGenerator\Tests\QueryParameterFixtures\PlainFilterRequest;
use Langsys\OpenApiDocsGenerator\Tests\QueryParameterFixtures\ProjectFiltersRequest;
use Langsys\OpenApiDocsGenerator\Tests\QueryParameterFixtures\QueryParametersController;
use OpenApi\Annotations as OA;
use OpenApi\Context;
use OpenApi\Generator;

function qpAttacher(?RouteResolver $resolver = null): QueryParameterAttacher
{
    return new QueryParameterAttacher(
        new DtoSchemaBuilder(dirname(__DIR__) . '/QueryParameterFixtures', new ExampleGenerator([], []), []),
        $resolver,
    );
}

/** A GET operation whose parse context points at a QueryParametersController action. */
function qpOperation(string $method, array $parameters = []): OA\Get
{
    $operation = new OA\Get(array_filter([
        'responses' => [new OA\Response(['response' => '200', 'description' => 'OK'])],
        'parameters' => $parameters,
    ]));
    $operation->_context = new Context([
        'namespace' => 'Langsys\\OpenApiDocsGenerator\\Tests\\QueryParameterFixtures',
        'class' => 'QueryParametersController',
        'method' => $method,
    ]);

    return $operation;
}

/** @param  array<int, OA\Parameter>  $components */
function qpDocument(OA\Get $operation, array $components = [], array $pathParameters = []): OA\OpenApi
{
    $pathItem = new OA\PathItem(array_filter(['path' => '/api/things', 'parameters' => $pathParameters]));
    $pathItem->get = $operation;

    $openapi = new OA\OpenApi(['info' => new OA\Info(['title' => 'T', 'version' => '1.0'])]);
    $openapi->paths = [$pathItem];

    if ($components !== []) {
        $openapi->components = new OA\Components(['parameters' => $components]);
    }

    return $openapi;
}

function qpComponent(string $name, string $in = 'query', bool $required = false, ?string $wireName = null): OA\Parameter
{
    return new OA\Parameter(array_filter([
        'parameter' => $name,
        'name' => $wireName ?? $name,
        'in' => $in,
        'required' => $required,
        'schema' => new OA\Schema(['type' => 'string']),
    ]));
}

/** The shared list components, as an app defines them once. */
function qpListComponents(): array
{
    return array_map(qpComponent(...), ['page', 'records_per_page', 'order_by', 'filter_by']);
}

/** @return array<string, array> the operation's serialized parameters, by name (or `$ref`) */
function qpParameters(OA\Get $operation): array
{
    $out = [];
    foreach (json_decode(json_encode($operation), true)['parameters'] ?? [] as $parameter) {
        $out[$parameter['name'] ?? $parameter['$ref']] = $parameter;
    }

    return $out;
}

/** Points every operation at one controller action, as a route whose action differs from the annotated method would. */
function qpRouteTo(string $action): RouteResolver
{
    return new class($action) implements RouteResolver {
        public function __construct(private string $action) {}

        public function resolve(ResolvableOperation $operation): ?ResolvedRoute
        {
            return new ResolvedRoute(new Route(['GET'], 'api/things', fn () => null), [], $this->action);
        }
    };
}

describe('the attribute', function () {
    test('counts on the class or any parent', function () {
        expect(QueryParameters::appliesTo(ListRequest::class))->toBeTrue()
            ->and(QueryParameters::appliesTo(ListProjectsRequest::class))->toBeTrue()
            ->and(QueryParameters::appliesTo(PlainFilterRequest::class))->toBeFalse();
    });
});

describe('parameters from a marked DTO', function () {
    test('one per public property, the parent\'s plain properties before the child\'s promoted ones, #[Omit] left out', function () {
        $operation = qpOperation('index');
        $added = qpAttacher()->attach(qpDocument($operation));

        expect(array_keys(qpParameters($operation)))->toBe([
            'page', 'records_per_page', 'order_by', 'filter_by',
            'status', 'cursor', 'search', 'ids[]', 'include_archived', 'owner', 'meta',
        ])
            ->and($added)->toBe(11);

        foreach (qpParameters($operation) as $parameter) {
            expect($parameter['in'])->toBe('query');
        }
    });

    test('required only for a non-nullable property with no default that is not Optional', function () {
        $operation = qpOperation('index');
        qpAttacher()->attach(qpDocument($operation));

        $required = array_keys(array_filter(qpParameters($operation), fn (array $p): bool => $p['required'] ?? false));

        expect($required)->toBe(['status']);
    });

    test('schemas follow the DTO type mapping and carry no generated example', function () {
        $operation = qpOperation('index');
        qpAttacher()->attach(qpDocument($operation));
        $parameters = qpParameters($operation);

        expect($parameters['page']['schema'])->toBe(['type' => 'integer', 'default' => 1])
            ->and($parameters['status']['schema'])->toBe(['type' => 'string', 'enum' => ['active', 'archived']])
            ->and($parameters['include_archived']['schema'])->toBe(['type' => 'boolean', 'default' => false])
            ->and($parameters['ids[]']['schema'])->toBe(['type' => 'array', 'items' => ['type' => 'integer']])
            ->and($parameters['cursor']['schema'])->toBe(['type' => 'string']);
    });

    test('a union is a oneOf of its members under the plain name, with the default and nullability on the union', function () {
        $operation = qpOperation('index');
        qpAttacher()->attach(qpDocument($operation));
        $parameters = qpParameters($operation);

        expect($parameters['order_by']['schema'])->toEqual([
            'oneOf' => [
                ['type' => 'string'],
                ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'nullable' => true,
        ])
            ->and($parameters['owner']['schema'])->toEqual([
                'oneOf' => [['type' => 'string'], ['type' => 'integer']],
                'default' => 'me',
            ]);
    });

    test('an array is sent as name[] and a string map as a deepObject', function () {
        $operation = qpOperation('index');
        qpAttacher()->attach(qpDocument($operation));
        $parameters = qpParameters($operation);

        expect($parameters)->toHaveKey('ids[]')
            ->and($parameters)->not->toHaveKey('ids')
            ->and($parameters['meta'])->toMatchArray([
                'style' => 'deepObject',
                'explode' => true,
                'schema' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
            ]);
    });

    test('description and example come from #[Description] and #[Example]', function () {
        $operation = qpOperation('index');
        qpAttacher()->attach(qpDocument($operation));
        $parameters = qpParameters($operation);

        expect($parameters['search'])->toMatchArray(['description' => 'Matches the project name', 'example' => 'acme'])
            ->and($parameters['status']['description'])->toBe('Only projects in this status')
            ->and($parameters['status'])->not->toHaveKey('example')
            ->and($parameters['ids[]']['example'])->toBe([42])
            ->and($parameters['page'])->not->toHaveKeys(['description', 'example']);
    });

    test('the name is the input name a MapInputName attribute gives', function () {
        $operation = qpOperation('index');
        qpAttacher()->attach(qpDocument($operation));

        expect(qpParameters($operation))->toHaveKey('include_archived')
            ->and(qpParameters($operation))->not->toHaveKey('includeArchived');
    });

    test('a property named like a query component is a $ref to it', function () {
        $operation = qpOperation('index');
        qpAttacher()->attach(qpDocument($operation, qpListComponents()));
        $parameters = qpParameters($operation);

        foreach (['page', 'records_per_page', 'order_by', 'filter_by'] as $name) {
            expect($parameters)->toHaveKey('#/components/parameters/' . $name)
                ->and($parameters['#/components/parameters/' . $name])->toBe(['$ref' => '#/components/parameters/' . $name]);
        }

        expect($parameters['search']['in'])->toBe('query');
    });

    test('a component of the same name in another location is not reused', function () {
        $operation = qpOperation('index');
        qpAttacher()->attach(qpDocument($operation, [qpComponent('status', 'path')]));

        expect(qpParameters($operation)['status']['in'])->toBe('query');
    });

    test('they follow the hand-written parameters', function () {
        $operation = qpOperation('index', [new OA\Parameter(['name' => 'projectId', 'in' => 'path', 'required' => true])]);
        qpAttacher()->attach(qpDocument($operation));

        expect(array_key_first(qpParameters($operation)))->toBe('projectId');
    });

    test('a nested Data class is sent as a deepObject', function () {
        $operation = qpOperation('filtered');
        qpAttacher()->attach(qpDocument($operation));

        expect(qpParameters($operation)['filter'])->toMatchArray([
            'style' => 'deepObject',
            'explode' => true,
            'schema' => ['allOf' => [['$ref' => '#/components/schemas/ProjectFilter']]],
        ]);
    });

    test('a dotted input name is written in brackets', function () {
        $operation = qpOperation('filtered');
        qpAttacher()->attach(qpDocument($operation));

        expect(qpParameters($operation))->toHaveKey('range[from]')
            ->and(qpParameters($operation))->not->toHaveKey('range.from');
    });

    test('a property with a subclass of #[Computed] is left out too', function () {
        $operation = qpOperation('filtered');
        qpAttacher()->attach(qpDocument($operation));

        expect(qpParameters($operation))->not->toHaveKey('summary');
    });

    test('computed and injected properties are left out, one that yields to the request is optional', function () {
        $operation = qpOperation('filtered');
        qpAttacher()->attach(qpDocument($operation));
        $parameters = qpParameters($operation);

        expect(array_keys($parameters))->toBe(['workspace', 'filter', 'range[from]', 'search', 'owner_id', 'owners', 'project_ids[]', 'since'])
            ->and($parameters['workspace'])->not->toHaveKey('required');
    });

    test('a validation attribute\'s format lands on the string schema, not on an array', function () {
        $operation = qpOperation('filtered');
        qpAttacher()->attach(qpDocument($operation));
        $parameters = qpParameters($operation);

        expect($parameters['owner_id']['schema'])->toBe(['type' => 'string', 'format' => 'uuid'])
            ->and($parameters['owners']['schema']['oneOf'][0])->toBe(['type' => 'string', 'format' => 'uuid'])
            ->and(json_encode($parameters['owners']['schema']['oneOf'][1]))->not->toContain('format')
            ->and(json_encode($parameters['project_ids[]']))->not->toContain('format')
            ->and($parameters['since']['schema'])->toBe(['type' => 'string', 'format' => 'date']);
    });

    test('the matched route\'s action is inspected when the annotation sits elsewhere', function () {
        $operation = qpOperation('bare');
        qpAttacher(qpRouteTo(QueryParametersController::class . '@index'))->attach(qpDocument($operation));

        expect(qpParameters($operation))->toHaveKey('status');
    });
});

describe('nothing changes', function () {
    test('for a DTO without the attribute', function () {
        $operation = qpOperation('plain');

        expect(qpAttacher()->attach(qpDocument($operation)))->toBe(0)
            ->and($operation->parameters)->toBe(Generator::UNDEFINED);
    });

    test('for an action that takes no Data class', function () {
        $operation = qpOperation('bare');

        expect(qpAttacher()->attach(qpDocument($operation)))->toBe(0)
            ->and($operation->parameters)->toBe(Generator::UNDEFINED);
    });
});

describe('a parameter documented by hand as well', function () {
    test('fails generation, naming the action and the parameter', function () {
        $operation = qpOperation('duplicated', [new OA\Parameter(['name' => 'search', 'in' => 'query'])]);

        expect(fn () => qpAttacher()->attach(qpDocument($operation)))->toThrow(
            OpenApiDocsException::class,
            "Query parameter 'search' of " . QueryParametersController::class . '@duplicated (GET /api/things) is documented by hand and by '
            . ListProjectsRequest::class . '; remove the hand-written one.',
        );
    });

    test('fails when the hand-written one is a $ref to a component', function () {
        $operation = qpOperation('duplicated', [new OA\Parameter(['ref' => '#/components/parameters/page'])]);

        expect(fn () => qpAttacher()->attach(qpDocument($operation, qpListComponents())))
            ->toThrow(OpenApiDocsException::class, "Query parameter 'page'");
    });

    test('fails when the hand-written one carries the [] suffix', function () {
        $operation = qpOperation('duplicated', [new OA\Parameter(['name' => 'ids[]', 'in' => 'query'])]);

        expect(fn () => qpAttacher()->attach(qpDocument($operation)))
            ->toThrow(OpenApiDocsException::class, "Query parameter 'ids'");
    });

    test('fails when the hand-written one is on the path item', function () {
        $operation = qpOperation('duplicated');
        $document = qpDocument($operation, [], [new OA\Parameter(['name' => 'search', 'in' => 'query'])]);

        expect(fn () => qpAttacher()->attach($document))
            ->toThrow(OpenApiDocsException::class, "Query parameter 'search'");
    });

    test('fails when two marked DTOs both read it, naming both', function () {
        $operation = qpOperation('twice');

        expect(fn () => qpAttacher()->attach(qpDocument($operation)))->toThrow(
            OpenApiDocsException::class,
            "Query parameter 'search' of " . QueryParametersController::class . '@twice (GET /api/things) is read by both '
            . ListProjectsRequest::class . ' and ' . ProjectFiltersRequest::class . '; map one of them to another input name.',
        );
    });

    test('is fine in another location', function () {
        $operation = qpOperation('duplicated', [new OA\Parameter(['name' => 'status', 'in' => 'path', 'required' => true])]);
        qpAttacher()->attach(qpDocument($operation));

        $status = array_filter(
            json_decode(json_encode($operation), true)['parameters'],
            fn (array $parameter): bool => ($parameter['name'] ?? null) === 'status',
        );

        expect(array_column($status, 'in'))->toBe(['path', 'query']);
    });
});

describe('a $ref to a component that disagrees', function () {
    test('on the name fails, naming both', function () {
        $operation = qpOperation('duplicated');

        expect(fn () => qpAttacher()->attach(qpDocument($operation, [qpComponent('search', wireName: 'q')])))->toThrow(
            OpenApiDocsException::class,
            "Query parameter 'search' of " . QueryParametersController::class . '@duplicated (GET /api/things) is read by '
            . ListProjectsRequest::class . " as 'search', but #/components/parameters/search is named 'q'; make them agree.",
        );
    });

    test('on the [] suffix fails, which decides whether PHP reads an array', function () {
        $operation = qpOperation('duplicated');

        expect(fn () => qpAttacher()->attach(qpDocument($operation, [qpComponent('ids')])))
            ->toThrow(OpenApiDocsException::class, "as 'ids[]', but #/components/parameters/ids is named 'ids';")
            ->and(fn () => qpAttacher()->attach(qpDocument(qpOperation('duplicated'), [qpComponent('search', wireName: 'search[]')])))
            ->toThrow(OpenApiDocsException::class, "as 'search', but #/components/parameters/search is named 'search[]';");
    });

    test('by having no name fails', function () {
        $operation = qpOperation('duplicated');
        $component = new OA\Parameter(['parameter' => 'search', 'in' => 'query', 'schema' => new OA\Schema(['type' => 'string'])]);

        expect(fn () => qpAttacher()->attach(qpDocument($operation, [$component])))
            ->toThrow(OpenApiDocsException::class, "as 'search', but #/components/parameters/search has no name; make them agree.");
    });

    test('is fine when an array\'s component is named with the [] suffix', function () {
        $operation = qpOperation('duplicated');
        qpAttacher()->attach(qpDocument($operation, [qpComponent('ids', wireName: 'ids[]')]));

        expect(qpParameters($operation))->toHaveKey('#/components/parameters/ids');
    });
});

describe('a $ref to a component that disagrees on required', function () {
    test('fails when the property is required and the component is not, naming both', function () {
        $operation = qpOperation('duplicated');

        expect(fn () => qpAttacher()->attach(qpDocument($operation, [qpComponent('status')])))->toThrow(
            OpenApiDocsException::class,
            "Query parameter 'status' of " . QueryParametersController::class . '@duplicated (GET /api/things) is required in '
            . ListProjectsRequest::class . ' but optional in #/components/parameters/status; make them agree.',
        );
    });

    test('fails when the component is required and the property is not', function () {
        $operation = qpOperation('duplicated');

        expect(fn () => qpAttacher()->attach(qpDocument($operation, [qpComponent('search', required: true)])))->toThrow(
            OpenApiDocsException::class,
            "Query parameter 'search' of " . QueryParametersController::class . '@duplicated (GET /api/things) is optional in '
            . ListProjectsRequest::class . ' but required in #/components/parameters/search; make them agree.',
        );
    });

    test('is fine when both say required', function () {
        $operation = qpOperation('duplicated');
        qpAttacher()->attach(qpDocument($operation, [qpComponent('status', required: true)]));

        expect(qpParameters($operation))->toHaveKey('#/components/parameters/status');
    });
});
