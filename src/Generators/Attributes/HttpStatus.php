<?php

namespace Langsys\OpenApiDocsGenerator\Generators\Attributes;

use Attribute;

/**
 * HTTP status code an error DTO is returned with.
 *
 * @example #[HttpStatus(402)]
 */
#[Attribute(Attribute::TARGET_CLASS)]
class HttpStatus extends OpenApiAttribute
{
    public function __construct(
        public readonly int $status,
    ) {
    }
}
