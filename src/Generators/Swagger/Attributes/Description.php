<?php

namespace Langsys\SwaggerAutoGenerator\Generators\Swagger\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class Description extends SwaggerAttribute
{
    public function __construct(
        public string $content
    ) {
    }

}
