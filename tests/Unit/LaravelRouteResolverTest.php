<?php

use Illuminate\Routing\Router;
use Langsys\OpenApiDocsGenerator\Data\ResolvableOperation;
use Langsys\OpenApiDocsGenerator\Routing\LaravelRouteResolver;
use Langsys\OpenApiDocsGenerator\Tests\Fixtures\RoutingController;

const API_KEY_MW = 'App\\Http\\Middleware\\AuthorizeApiKey';

function registerApiRoutes(): Router
{
    /** @var Router $router */
    $router = app('router');
    $router->aliasMiddleware('auth.apikey', API_KEY_MW);

    // Api-key protected group.
    $router->group(['middleware' => ['auth.apikey']], function () use ($router) {
        $router->get('api/projects', [RoutingController::class, 'index']);
        $router->get('api/projects/{project}', [RoutingController::class, 'show']);
    });

    // Public route, no api-key middleware.
    $router->get('api/health', [RoutingController::class, 'health']);

    return $router;
}

test('resolves a route by controller action and gathers its middleware', function () {
    $resolver = new LaravelRouteResolver(registerApiRoutes());

    $resolved = $resolver->resolve(new ResolvableOperation(
        httpMethod: 'get',
        path: '/api/projects',
        action: RoutingController::class . '@index',
    ));

    expect($resolved)->not->toBeNull()
        ->and($resolved->middleware())->toContain(API_KEY_MW)
        ->and($resolved->action())->toBe(RoutingController::class . '@index')
        ->and($resolved->uri())->toBe('api/projects');
});

test('a route without the api-key middleware does not carry it', function () {
    $resolver = new LaravelRouteResolver(registerApiRoutes());

    $resolved = $resolver->resolve(new ResolvableOperation(
        httpMethod: 'get',
        path: '/api/health',
        action: RoutingController::class . '@health',
    ));

    expect($resolved)->not->toBeNull()
        ->and($resolved->middleware())->not->toContain(API_KEY_MW);
});

test('falls back to path-signature matching when the action is unknown', function () {
    $resolver = new LaravelRouteResolver(registerApiRoutes());

    $resolved = $resolver->resolve(new ResolvableOperation(
        httpMethod: 'get',
        path: '/api/projects',
        action: null,
    ));

    expect($resolved)->not->toBeNull()
        ->and($resolved->uri())->toBe('api/projects');
});

test('path-signature matching ignores parameter-name differences', function () {
    $resolver = new LaravelRouteResolver(registerApiRoutes());

    // OpenAPI documents "{id}" while the route declares "{project}".
    $resolved = $resolver->resolve(new ResolvableOperation(
        httpMethod: 'get',
        path: '/api/projects/{id}',
        action: null,
    ));

    expect($resolved)->not->toBeNull()
        ->and($resolved->uri())->toBe('api/projects/{project}');
});

test('returns null when no route matches', function () {
    $resolver = new LaravelRouteResolver(registerApiRoutes());

    $resolved = $resolver->resolve(new ResolvableOperation(
        httpMethod: 'get',
        path: '/api/unregistered',
        action: RoutingController::class . '@missing',
    ));

    expect($resolved)->toBeNull();
});

test('disambiguates one action bound to several routes by method and path', function () {
    /** @var Router $router */
    $router = app('router');
    $router->get('api/projects', [RoutingController::class, 'index']);
    $router->post('api/projects/bulk', [RoutingController::class, 'index']);

    $resolver = new LaravelRouteResolver($router);

    $resolved = $resolver->resolve(new ResolvableOperation(
        httpMethod: 'post',
        path: '/api/projects/bulk',
        action: RoutingController::class . '@index',
    ));

    expect($resolved)->not->toBeNull()
        ->and($resolved->uri())->toBe('api/projects/bulk');
});
