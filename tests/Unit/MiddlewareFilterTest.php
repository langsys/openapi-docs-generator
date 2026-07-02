<?php

use Illuminate\Routing\Route;
use Langsys\OpenApiDocsGenerator\Data\OperationContext;
use Langsys\OpenApiDocsGenerator\Data\ResolvedRoute;
use Langsys\OpenApiDocsGenerator\Filters\MiddlewareFilter;
use OpenApi\Annotations as OA;

const APIKEY_CLASS = 'App\\Http\\Middleware\\AuthorizeApiKey';
const SANCTUM_CLASS = 'Laravel\\Sanctum\\Http\\Middleware\\EnsureFrontendRequestsAreStateful';
const ALIAS_MAP = ['auth.apikey' => APIKEY_CLASS];

function contextWithMiddleware(array $middleware): OperationContext
{
    return new OperationContext(
        operation: new OA\Get([]),
        pathItem: new OA\PathItem(['path' => '/api/projects']),
        httpMethod: 'get',
        path: '/api/projects',
        route: new ResolvedRoute(new Route(['GET'], 'api/projects', fn () => null), $middleware, 'X@index'),
    );
}

function contextWithoutRoute(): OperationContext
{
    return new OperationContext(
        operation: new OA\Get([]),
        pathItem: new OA\PathItem(['path' => '/api/projects']),
        httpMethod: 'get',
        path: '/api/projects',
        route: null,
    );
}

test('matches when an aliased middleware resolves to a class on the route', function () {
    $filter = new MiddlewareFilter('auth.apikey', ALIAS_MAP);

    expect($filter->matches(contextWithMiddleware([APIKEY_CLASS])))->toBeTrue();
});

test('matches a fully-qualified class name directly without an alias map', function () {
    $filter = new MiddlewareFilter(APIKEY_CLASS);

    expect($filter->matches(contextWithMiddleware([APIKEY_CLASS])))->toBeTrue();
});

test('matches a dual-auth route that also carries other middleware', function () {
    $filter = new MiddlewareFilter('auth.apikey', ALIAS_MAP);

    expect($filter->matches(contextWithMiddleware([SANCTUM_CLASS, APIKEY_CLASS])))->toBeTrue();
});

test('matches middleware that has parameters', function () {
    $filter = new MiddlewareFilter('auth.apikey', ALIAS_MAP);

    expect($filter->matches(contextWithMiddleware([APIKEY_CLASS . ':admin'])))->toBeTrue();
});

test('does not match when the route lacks the middleware', function () {
    $filter = new MiddlewareFilter('auth.apikey', ALIAS_MAP);

    expect($filter->matches(contextWithMiddleware([SANCTUM_CLASS])))->toBeFalse();
});

test('never matches an operation with no resolved route', function () {
    $filter = new MiddlewareFilter('auth.apikey', ALIAS_MAP);

    expect($filter->matches(contextWithoutRoute()))->toBeFalse();
});

test('match=all requires every target middleware to be present', function () {
    $filter = new MiddlewareFilter(['auth.apikey', SANCTUM_CLASS], ALIAS_MAP, 'all');

    expect($filter->matches(contextWithMiddleware([APIKEY_CLASS, SANCTUM_CLASS])))->toBeTrue()
        ->and($filter->matches(contextWithMiddleware([APIKEY_CLASS])))->toBeFalse();
});
