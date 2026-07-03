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
 * paths), it falls back to structural segment matching, where:
 *
 *  - a route/OA "{param}" segment matches any segment on the other side (so
 *    parameter-name differences don't matter, and a route "{format?}" matches a
 *    concrete OA segment like "flat" that documents one value of that param);
 *  - a route's trailing optional "{param?}" may be absent from the OA path;
 *  - among matches, the most specific route (most exact literal-segment
 *    agreements) wins, so a literal route beats a wildcard one.
 *
 * The route index is built once, lazily, from the router's full route collection.
 */
class LaravelRouteResolver implements RouteResolver
{
    /** @var array<string, array<int, Route>> Action name => routes. */
    private array $byAction = [];

    /** @var array<int, Route> All routes, for the structural fallback. */
    private array $routes = [];

    private bool $indexed = false;

    public function __construct(
        private Router $router,
        private ?string $basePrefix = null,
    ) {}

    public function resolve(ResolvableOperation $operation): ?ResolvedRoute
    {
        $this->buildIndex();

        $route = $this->matchByAction($operation) ?? $this->matchStructural($operation);

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
     * Index every registered route by action, and keep a flat list for the
     * structural fallback.
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

            $this->routes[] = $route;
        }

        $this->indexed = true;
    }

    private function matchByAction(ResolvableOperation $operation): ?Route
    {
        if ($operation->action === null) {
            return null;
        }

        return $this->pickBest($this->byAction[$operation->action] ?? [], $operation, requireMatch: false);
    }

    private function matchStructural(ResolvableOperation $operation): ?Route
    {
        return $this->pickBest($this->routes, $operation, requireMatch: true);
    }

    /**
     * Choose the best route from a candidate list for the operation.
     *
     * Candidates are filtered by HTTP method and structural segment match, then
     * ranked by specificity (exact literal-segment agreements). When
     * $requireMatch is false (an action already selected the candidates) a
     * candidate with the right method is returned even if segments don't align.
     *
     * @param  array<int, Route>  $candidates
     */
    private function pickBest(array $candidates, ResolvableOperation $operation, bool $requireMatch): ?Route
    {
        if ($candidates === []) {
            return null;
        }

        $method = strtoupper($operation->httpMethod);
        $operationSegments = $this->segments($operation->path);

        $best = null;
        $bestScore = -1;
        $methodFallback = null;

        foreach ($candidates as $route) {
            if (! in_array($method, $route->methods(), true)) {
                continue;
            }

            $methodFallback ??= $route;

            $score = $this->matchScore($this->segments($route->uri()), $operationSegments);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $route;
            }
        }

        if ($best !== null) {
            return $best;
        }

        if ($requireMatch) {
            return null;
        }

        // Action matched but no segment alignment (e.g. divergent path docs):
        // trust the action and return a same-method candidate, then the first.
        return $methodFallback ?? $candidates[0];
    }

    /**
     * Score how well a route's segments match an operation's, or -1 for no match.
     * A higher score means more exact literal agreements (a more specific route).
     *
     * @param  array<int, string>  $routeSegments
     * @param  array<int, string>  $operationSegments
     */
    private function matchScore(array $routeSegments, array $operationSegments): int
    {
        $routeCount = count($routeSegments);
        $operationCount = count($operationSegments);

        if ($operationCount === $routeCount) {
            return $this->alignScore($routeSegments, $operationSegments);
        }

        // A trailing optional route param may be omitted by the operation path.
        if ($routeCount > 0
            && $this->isOptionalParam($routeSegments[$routeCount - 1])
            && $operationCount === $routeCount - 1) {
            return $this->alignScore(array_slice($routeSegments, 0, $routeCount - 1), $operationSegments);
        }

        return -1;
    }

    /**
     * Segment-wise alignment score for equal-length segment lists, or -1 if any
     * literal-vs-literal pair disagrees. A "{param}" on either side matches
     * anything (no specificity credit); equal literals score one point each.
     *
     * @param  array<int, string>  $routeSegments
     * @param  array<int, string>  $operationSegments
     */
    private function alignScore(array $routeSegments, array $operationSegments): int
    {
        $literalMatches = 0;

        foreach ($routeSegments as $index => $routeSegment) {
            $operationSegment = $operationSegments[$index];

            if ($this->isParam($routeSegment) || $this->isParam($operationSegment)) {
                continue;
            }

            if ($routeSegment !== $operationSegment) {
                return -1;
            }

            $literalMatches++;
        }

        return $literalMatches;
    }

    /**
     * Split a path into raw segments after reconciling the base prefix.
     *
     * @return array<int, string>
     */
    private function segments(string $path): array
    {
        $path = trim($path, '/');

        if ($this->basePrefix !== null && $this->basePrefix !== '') {
            $prefix = trim($this->basePrefix, '/');
            if ($prefix !== '' && $path !== $prefix && ! str_starts_with($path, $prefix . '/')) {
                $path = $prefix . '/' . $path;
            }
        }

        return $path === '' ? [] : explode('/', $path);
    }

    private function isParam(string $segment): bool
    {
        return $segment !== '' && $segment[0] === '{' && str_ends_with($segment, '}');
    }

    private function isOptionalParam(string $segment): bool
    {
        return $this->isParam($segment) && str_ends_with($segment, '?}');
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
