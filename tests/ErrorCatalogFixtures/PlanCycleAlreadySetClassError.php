<?php

namespace Langsys\OpenApiDocsGenerator\Tests\ErrorCatalogFixtures;

use Langsys\OpenApiDocsGenerator\Tests\ErrorFixtures\ApiError;

/** The same error as PlanErrors::PlanCycleAlreadySetEnum, declared as a class. */
class PlanCycleAlreadySetClassError extends ApiError
{
    public const CODE = 'plan_cycle_already_set_class';
    public function template(): string
    {
        return 'The plan cycle is already that one.';
    }
    public const STATUS = 409;
}
