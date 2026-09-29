<?php

namespace Langsys\OpenApiDocsGenerator\Tests\ErrorCatalogFixtures;

use Langsys\OpenApiDocsGenerator\Contracts\ErrorCatalog;
use Langsys\OpenApiDocsGenerator\Generators\Attributes\ErrorCase;
use Langsys\OpenApiDocsGenerator\Tests\ErrorFixtures\HttpCode;

/** Plain errors: a code, a message and a status, with no typed details. */
enum PlanErrors: string implements ErrorCatalog
{
    #[ErrorCase(status: 409, message: 'The plan cycle is already that one.')]
    case SamePlanCycle = 'same_plan_cycle';

    /** Status as an int-backed enum case, exactly as a class STATUS may be. */
    #[ErrorCase(status: HttpCode::NOT_FOUND, message: 'No plan with that id.')]
    case PlanNotFound = 'plan_not_found';

    /** The same error as PlanCycleAlreadySetClassError, declared as a case. */
    #[ErrorCase(status: 409, message: 'The plan cycle is already that one.')]
    case PlanCycleAlreadySetEnum = 'plan_cycle_already_set_enum';
}
