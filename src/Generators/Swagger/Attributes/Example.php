<?php

namespace Langsys\SwaggerAutoGenerator\Generators\Swagger\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class Example extends SwaggerAttribute
{
    public function __construct(
        public string|int|bool $content,
        public array $arguments = [],
    ) {
    }

}
