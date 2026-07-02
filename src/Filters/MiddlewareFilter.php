<?php

namespace Langsys\OpenApiDocsGenerator\Filters;

use Langsys\OpenApiDocsGenerator\Contracts\OperationFilter;
use Langsys\OpenApiDocsGenerator\Data\OperationContext;

/**
 * Matches operations by the middleware on their backing route — the ground-truth
 * discriminator for filtered documentation sets.
 *
 * Config values may be middleware aliases (e.g. "auth.apikey") or fully-qualified
 * class names. Aliases are resolved to their class via the router's alias map, so
 * matching works regardless of how the middleware was attached to the route
 * (directly, via a group, by alias, or by class). Matching is done against the
 * route's fully-resolved middleware (see {@see ResolvedRoute::middleware()}),
 * with exact and ":params" prefix matching.
 *
 * Operations with no resolved route never match — an operation we cannot tie to a
 * route cannot be proven to carry the required middleware.
 */
class MiddlewareFilter implements OperationFilter
{
    /** @var array<int, string> Target middleware classes/names to match against. */
    private array $targets;

    /**
     * @param  string|array<int, string>  $middleware  Alias or FQCN (or a list of them).
     * @param  array<string, string>  $aliasMap  Router middleware alias map (alias => class),
     *                                           e.g. from app('router')->getMiddleware().
     * @param  string  $match  'any' (default) — match if the route has any target;
     *                         'all' — match only if the route has every target.
     */
    public function __construct(
        array|string $middleware,
        array $aliasMap = [],
        private string $match = 'any',
    ) {
        $middleware = is_array($middleware) ? $middleware : [$middleware];

        $this->targets = array_values(array_map(
            fn (string $name): string => $this->resolveToClass($name, $aliasMap),
            $middleware,
        ));
    }

    public function matches(OperationContext $context): bool
    {
        if ($context->route === null) {
            return false;
        }

        $resolved = $context->route->middleware();

        if ($this->match === 'all') {
            foreach ($this->targets as $target) {
                if (! $this->routeHasMiddleware($resolved, $target)) {
                    return false;
                }
            }

            return $this->targets !== [];
        }

        foreach ($this->targets as $target) {
            if ($this->routeHasMiddleware($resolved, $target)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve an alias to its class via the alias map; leave FQCNs / unknown names
     * untouched. Any ":params" suffix is stripped before the alias lookup.
     */
    private function resolveToClass(string $middleware, array $aliasMap): string
    {
        $name = $middleware;
        if (str_contains($name, ':')) {
            $name = explode(':', $name, 2)[0];
        }

        return $aliasMap[$name] ?? $name;
    }

    /**
     * @param  array<int, string>  $resolved  The route's fully-resolved middleware.
     */
    private function routeHasMiddleware(array $resolved, string $target): bool
    {
        foreach ($resolved as $middleware) {
            if ($middleware === $target || str_starts_with($middleware, $target . ':')) {
                return true;
            }
        }

        return false;
    }
}
