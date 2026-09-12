<?php

namespace Langsys\OpenApiDocsGenerator\Generators\Attributes;

use Attribute;

/**
 * Marks an error DTO property that is emitted at the top level of the error
 * envelope instead of under `details` (e.g. a validation error's `errors` map).
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class EnvelopeField extends OpenApiAttribute
{
}
