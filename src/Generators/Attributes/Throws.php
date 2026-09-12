<?php

namespace Langsys\OpenApiDocsGenerator\Generators\Attributes;

use Attribute;

/**
 * Declares which error DTOs a controller action can respond with.
 *
 * Each class must carry #[ErrorCode]; naming a class without it fails generation.
 *
 * @example #[Throws(InsufficientBalanceError::class, ProjectNotFoundError::class)]
 */
#[Attribute(Attribute::TARGET_METHOD)]
class Throws extends OpenApiAttribute
{
    /** @var list<class-string> */
    public readonly array $errorClasses;

    public function __construct(string ...$errorClasses)
    {
        $this->errorClasses = array_values($errorClasses);
    }
}
