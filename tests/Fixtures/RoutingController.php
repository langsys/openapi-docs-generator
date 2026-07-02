<?php

namespace Langsys\OpenApiDocsGenerator\Tests\Fixtures;

/**
 * Plain controller used to give routes real controller actions in route-resolver
 * and selection tests. Methods have no bodies — they are never invoked; only their
 * action names ("...\RoutingController@index") are matched.
 */
class RoutingController
{
    public function index(): void {}

    public function store(): void {}

    public function show(): void {}

    public function health(): void {}
}
