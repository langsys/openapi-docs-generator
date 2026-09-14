<?php

namespace Langsys\OpenApiDocsGenerator\Tests\ErrorOperationFixtures;

use Langsys\OpenApiDocsGenerator\Generators\Attributes\Throws;
use Langsys\OpenApiDocsGenerator\Tests\ErrorFixtures\InsufficientBalanceError;
use Langsys\OpenApiDocsGenerator\Tests\ErrorFixtures\UnauthenticatedError;
use Langsys\OpenApiDocsGenerator\Tests\ErrorFixtures\ValidationError;

/**
 * Plain controller (no OpenAPI annotations) reflected on directly by the
 * OperationErrorAttacher unit tests.
 */
class AttacherFixtureController
{
    #[Throws(InsufficientBalanceError::class)]
    public function single(): void
    {
    }

    #[Throws(ValidationError::class, BatchTooLargeError::class)]
    public function sharedStatus(): void
    {
    }

    #[Throws(UnauthenticatedError::class)]
    public function alsoImplied(): void
    {
    }

    #[Throws(NotAnError::class)]
    public function notAnError(): void
    {
    }

    public function store(StoreProjectRequest $request): void
    {
    }

    public function show(Project $project): void
    {
    }

    public function showSlug(string $slug): void
    {
    }

    public function bare(): void
    {
    }

    public function guarded(): void
    {
        FakeGuard::authorize('view_things');
    }

    /**
     * Stands in for a helper carrying the @OA annotation while the route's real action
     * is store(). Its own #[Throws] still counts.
     */
    #[Throws(InsufficientBalanceError::class)]
    public function annotatedHelper(): void
    {
    }
}
