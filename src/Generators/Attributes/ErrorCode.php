<?php

namespace Langsys\OpenApiDocsGenerator\Generators\Attributes;

use Attribute;

/**
 * Marks a Spatie Data class as an API error and assigns its machine-readable code.
 *
 * Presence of this attribute is the discovery rule: any Data class carrying
 * #[ErrorCode] is treated as an error DTO (no base-class or name-suffix check).
 *
 * @example #[ErrorCode('insufficient_balance', System::INSUFFICIENT_BALANCE_ERROR_MESSAGE)]
 */
#[Attribute(Attribute::TARGET_CLASS)]
class ErrorCode extends OpenApiAttribute
{
    public function __construct(
        /** snake_case slug emitted in the envelope's `code` field. */
        public readonly string $code,
        /** Default human-readable message emitted in the envelope's `error` field. */
        public readonly ?string $message = null,
    ) {
    }
}
