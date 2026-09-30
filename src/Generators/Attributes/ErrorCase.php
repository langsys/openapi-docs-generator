<?php

namespace Langsys\OpenApiDocsGenerator\Generators\Attributes;

use Attribute;
use BackedEnum;

/**
 * The status of one error declared as an enum case.
 *
 * The case's backing value is the error's code and its message is what the enum's
 * template() returns for the case, so this attribute carries the one thing left: the
 * STATUS an error class declares as a constant.
 *
 * @example #[ErrorCase(status: HttpCode::CONFLICT)]
 */
#[Attribute(Attribute::TARGET_CLASS_CONSTANT)]
class ErrorCase extends OpenApiAttribute
{
    public function __construct(
        /** HTTP status, as an int or an int-backed enum case. */
        public readonly int|BackedEnum $status,
    ) {
    }
}
