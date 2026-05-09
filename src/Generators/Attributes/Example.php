<?php

namespace Langsys\OpenApiDocsGenerator\Generators\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class Example extends OpenApiAttribute
{
    public function __construct(
        public string|int|bool|float $content,
        public array $arguments = [],
    ) {
    }
}
