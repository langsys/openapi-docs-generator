<?php

namespace Langsys\OpenApiDocsGenerator\Generators\Attributes;

use Attribute;
use BackedEnum;

/**
 * Declares which error DTOs a controller action can respond with. Placed on a
 * controller class instead, it applies to every action of that controller.
 *
 * Each entry is an error: a concrete subclass of errors.base_class, or a case of an
 * ErrorCatalog enum. The two mix freely; naming anything else fails generation.
 *
 * @example #[Throws(InsufficientBalanceError::class, ApiErrors::SamePlanCycle)]
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class Throws extends OpenApiAttribute
{
    /** @var list<class-string|BackedEnum> */
    public readonly array $errorClasses;

    public function __construct(string|BackedEnum ...$errorClasses)
    {
        $this->errorClasses = array_values($errorClasses);
    }
}
