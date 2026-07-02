<?php

namespace Langsys\OpenApiDocsGenerator\Data;

use Illuminate\Routing\Route;

/**
 * A documented operation successfully matched to a Laravel route.
 *
 * The middleware list is fully resolved (route groups expanded, aliases resolved
 * to their class names, ":params" preserved) so filters can match against ground
 * truth regardless of how middleware was attached.
 */
class ResolvedRoute
{
    /**
     * @param  Route  $route  The matched Laravel route.
     * @param  array<int, string>  $middleware  Fully-resolved middleware (FQCNs, with ":params" preserved).
     * @param  string|null  $action  The route's controller action, when it has one.
     */
    public function __construct(
        private readonly Route $route,
        private readonly array $middleware,
        private readonly ?string $action = null,
    ) {}

    public function route(): Route
    {
        return $this->route;
    }

    /**
     * @return array<int, string>
     */
    public function middleware(): array
    {
        return $this->middleware;
    }

    public function action(): ?string
    {
        return $this->action;
    }

    public function uri(): string
    {
        return $this->route->uri();
    }
}
