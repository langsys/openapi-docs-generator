<?php

use Illuminate\Routing\Route;
use Langsys\OpenApiDocsGenerator\Contracts\RouteResolver;
use Langsys\OpenApiDocsGenerator\Data\ResolvableOperation;
use Langsys\OpenApiDocsGenerator\Data\ResolvedRoute;
use Langsys\OpenApiDocsGenerator\Filters\MiddlewareFilter;
use Langsys\OpenApiDocsGenerator\Generators\OperationSelector;
use OpenApi\Annotations as OA;

// A route-less path in the fake resolver map resolves to null (unmatched).
function fakeResolver(array $routesByPath): RouteResolver
{
    return new class($routesByPath) implements RouteResolver {
        public function __construct(private array $routes) {}

        public function resolve(ResolvableOperation $operation): ?ResolvedRoute
        {
            return $this->routes[$operation->path] ?? null;
        }
    };
}

function resolvedWith(array $middleware): ResolvedRoute
{
    return new ResolvedRoute(new Route(['GET'], 'x', fn () => null), $middleware, null);
}

function pathItemGet(string $path, array $tags = []): OA\PathItem
{
    $operation = new OA\Get([
        'responses' => [new OA\Response(['response' => '200', 'description' => 'OK'])],
    ]);

    if ($tags !== []) {
        $operation->tags = $tags;
    }

    $pathItem = new OA\PathItem(['path' => $path]);
    $pathItem->get = $operation;

    return $pathItem;
}

function openApiWithPaths(array $pathItems): OA\OpenApi
{
    $openapi = new OA\OpenApi(['info' => new OA\Info(['title' => 'T', 'version' => '1.0'])]);
    $openapi->paths = $pathItems;

    return $openapi;
}

const KEEP_MW = 'App\\Middleware\\ApiKey';
const OTHER_MW = 'App\\Middleware\\Other';

function threePathModel(): OA\OpenApi
{
    return openApiWithPaths([
        pathItemGet('/api/keep'),
        pathItemGet('/api/drop'),
        pathItemGet('/api/unmatched'),
    ]);
}

function threePathResolver(): RouteResolver
{
    return fakeResolver([
        '/api/keep' => resolvedWith([KEEP_MW]),
        '/api/drop' => resolvedWith([OTHER_MW]),
        // '/api/unmatched' intentionally absent -> null
    ]);
}

test('keeps operations matching an include filter and drops the rest', function () {
    $openapi = threePathModel();

    $selector = new OperationSelector(
        routeResolver: threePathResolver(),
        include: [new MiddlewareFilter(KEEP_MW)],
        unmatched: 'exclude',
    );

    $report = $selector->select($openapi);

    $paths = array_map(fn (OA\PathItem $p) => $p->path, $openapi->paths);
    expect($paths)->toBe(['/api/keep'])
        ->and($report->counts())->toBe(['kept' => 1, 'dropped' => 2, 'unmatched' => 1]);
});

test('unmatched=include keeps route-less operations', function () {
    $openapi = threePathModel();

    $selector = new OperationSelector(
        routeResolver: threePathResolver(),
        include: [new MiddlewareFilter(KEEP_MW)],
        unmatched: 'include',
    );

    $selector->select($openapi);

    $paths = array_map(fn (OA\PathItem $p) => $p->path, $openapi->paths);
    expect($paths)->toContain('/api/keep')
        ->and($paths)->toContain('/api/unmatched')
        ->and($paths)->not->toContain('/api/drop');
});

test('an empty include list keeps every matched operation but still drops unmatched', function () {
    $openapi = threePathModel();

    $selector = new OperationSelector(
        routeResolver: threePathResolver(),
        include: [],
        unmatched: 'exclude',
    );

    $report = $selector->select($openapi);

    $paths = array_map(fn (OA\PathItem $p) => $p->path, $openapi->paths);
    expect($paths)->toBe(['/api/keep', '/api/drop'])
        ->and($report->counts()['kept'])->toBe(2);
});

test('exclude filters subtract from the included set', function () {
    $openapi = threePathModel();

    $selector = new OperationSelector(
        routeResolver: threePathResolver(),
        include: [],
        exclude: [new MiddlewareFilter(OTHER_MW)],
        unmatched: 'exclude',
    );

    $selector->select($openapi);

    $paths = array_map(fn (OA\PathItem $p) => $p->path, $openapi->paths);
    expect($paths)->toBe(['/api/keep']);
});

test('drops the whole path item when all its operations are removed', function () {
    $openapi = openApiWithPaths([pathItemGet('/api/drop')]);

    $selector = new OperationSelector(
        routeResolver: fakeResolver(['/api/drop' => resolvedWith([OTHER_MW])]),
        include: [new MiddlewareFilter(KEEP_MW)],
    );

    $selector->select($openapi);

    expect($openapi->paths)->toBe([]);
});

test('the report records the disposition of every operation', function () {
    $openapi = threePathModel();

    $report = (new OperationSelector(
        routeResolver: threePathResolver(),
        include: [new MiddlewareFilter(KEEP_MW)],
    ))->select($openapi);

    expect(array_column($report->kept, 'path'))->toBe(['/api/keep'])
        ->and(array_column($report->dropped, 'path'))->toContain('/api/drop')
        ->and(array_column($report->unmatched, 'path'))->toBe(['/api/unmatched'])
        ->and($report->summaryLine())->toBe('kept 1, dropped 2, unmatched 1');
});
