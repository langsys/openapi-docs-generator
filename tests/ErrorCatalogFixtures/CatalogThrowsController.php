<?php

namespace Langsys\OpenApiDocsGenerator\Tests\ErrorCatalogFixtures;

use Langsys\OpenApiDocsGenerator\Generators\Attributes\Throws;

/** A class error and an enum case, declared together on one action. */
class CatalogThrowsController
{
    #[Throws(PlanCycleAlreadySetClassError::class, PlanErrors::SamePlanCycle)]
    public function mixedErrors(): void
    {
    }
}
