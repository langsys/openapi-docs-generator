<?php

namespace Langsys\OpenApiDocsGenerator\Generators\Attributes;

use Attribute;
use BackedEnum;

/**
 * The status and message of one error declared as an enum case.
 *
 * The case's backing value is the error's code, so this attribute carries what the
 * CODE constant's siblings carry on an error class: MESSAGE and STATUS.
 *
 * @example #[ErrorCase(status: HttpCode::CONFLICT, message: 'The plan cycle is already that one.')]
 */
#[Attribute(Attribute::TARGET_CLASS_CONSTANT)]
class ErrorCase extends OpenApiAttribute
{
    public function __construct(
        /** HTTP status, as an int or an int-backed enum case. */
        public readonly int|BackedEnum $status,
        /** The default human message, and the documentation text. */
        public readonly string $message,
    ) {
    }
}
