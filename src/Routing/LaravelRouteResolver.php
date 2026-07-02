<?php

namespace Langsys\OpenApiDocsGenerator\Routing;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Langsys\OpenApiDocsGenerator\Contracts\RouteResolver;
use Langsys\OpenApiDocsGenerator\Data\ResolvableOperation;
use Langsys\OpenApiDocsGenerator\Data\ResolvedRoute;

/**
 * Resolves documented operations to Laravel routes.
 *
 * Resolution is action-first: an operation's controller action maps exactly to a
 * route's action name, which sidesteps every path-normalization pitfall (base
 * prefix, "{id}" vs "{project}" naming, optional params, regex constraints).
 * When an operation has no resolvable action (closure routes, annotation-only
 * paths), it falls back to structural path-signature matching, where every
 * "{param}" collapses to a wildcard so param-name differences don't defeat it.
 *
 * The route index is built once, lazily, from the router's full route collection.
 */
class LaravelRouteResolver implements RouteResolver
{
    /** @var array<string, array<int, Route>> Action name => routes. */
    private array $byAction = [];

    /** @var array<string, array<int, Route>> "METHOD signature" => routes. */
    private array $bySignature = [];

    private bool $indexed = false;

    public function __construct(
        private Router $router,
        private ?string $basePrefix = null,
    ) {}

    public function resolve(ResolvableOperation $operation): ?ResolvedRoute
    {
        $this->buildIndex();

        $route = $this->matchByAction($operation) ?? $this->matchBySignature($operation);

        if ($route === null) {
            return null;
        }

        return new ResolvedRoute(
            route: $route,
            middleware: $this->router->gatherRouteMiddleware($route),
            action: $this->routeAction($route),
        );
    }

    /**
     * Build the action and path-signature indexes from every registered route.
     */
    private function buildIndex(): void
    {
        if ($this->indexed) {
            return;
        }

        foreach ($this->router->getRoutes() as $route) {
            $action = $this->routeAction($route);
            if ($action !== null) {
                $this->byAction[$action][] = $route;
            }

            foreach ($route->methods() as $method) {
                $this->bySignature[$this->signatureKey($method, $route->uri())][] = $route;
            }
        }

        $this->indexed = true;
    }

    private function matchByAction(ResolvableOperation $operation): ?Route
    {
        if ($operation->action === null) {
            return null;
        }

        return $this->disambiguate($this->byAction[$operation->action] ?? [], $operation);
    }

    private function matchBySignature(ResolvableOperation $operation): ?Route
    {
        $candidates = $this->bySignature[$this->signatureKey($operation->httpMethod, $operation->path)] ?? [];

        return $candidates[0] ?? null;
    }

    /**
     * When an action backs more than one route, disambiguate by HTTP method and
     * then path signature; otherwise return the sole candidate.
     *
     * @param  array<int, Route>  $candidates
     */
    private function disambiguate(array $candidates, ResolvableOperation $operation): ?Route
    {
        if (count($candidates) <= 1) {
            return $candidates[0] ?? null;
        }

        $method = strtoupper($operation->httpMethod);
        $signature = $this->normalizeSignature($operation->path);

        foreach ($candidates as $route) {
            if (in_array($method, $route->methods(), true)
                && $this->normalizeSignature($route->uri()) === $signature) {
                return $route;
            }
        }

        foreach ($candidates as $route) {
            if (in_array($method, $route->methods(), true)) {
                return $route;
            }
        }

        return $candidates[0];
    }

    private function signatureKey(string $method, string $path): string
    {
        return strtoupper($method) . ' ' . $this->normalizeSignature($path);
    }

    /**
     * Reduce a path to a comparable signature: normalize the base prefix and
     * collapse every "{param}" (including optional "{param?}") to "{}" so
     * parameter-name and optional differences don't defeat matching.
     */
    private function normalizeSignature(string $path): string
    {
        $path = trim($path, '/');

        if ($this->basePrefix !== null && $this->basePrefix !== '') {
            $prefix = trim($this->basePrefix, '/');
            if ($prefix !== '' && $path !== $prefix && ! str_starts_with($path, $prefix . '/')) {
                $path = $prefix . '/' . $path;
            }
        }

        if ($path === '') {
            return '';
        }

        $segments = array_map(
            static fn (string $segment): string => preg_match('/^\{.*\}$/', $segment) === 1 ? '{}' : $segment,
            explode('/', $path),
        );

        return implode('/', $segments);
    }

    /**
     * The route's controller action name, or null for closures / unroutable actions.
     */
    private function routeAction(Route $route): ?string
    {
        $action = $route->getActionName();

        if ($action === '' || $action === 'Closure' || str_ends_with($action, '\\Closure')) {
            return null;
        }

        return ltrim($action, '\\');
    }
}
