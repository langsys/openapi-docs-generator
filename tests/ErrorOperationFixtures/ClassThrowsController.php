<?php

namespace Langsys\OpenApiDocsGenerator\Tests\ErrorOperationFixtures;

use Langsys\OpenApiDocsGenerator\Generators\Attributes\Throws;
use Langsys\OpenApiDocsGenerator\Tests\ErrorFixtures\UnauthenticatedError;

/** A class-level #[Throws] applies to every action of the controller. */
#[Throws(UnauthenticatedError::class)]
class ClassThrowsController
{
    public function index(): void
    {
    }
}
