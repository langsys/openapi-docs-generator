<?php

namespace Langsys\OpenApiDocsGenerator\Generators\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class OneOfItemsFrom extends OpenApiAttribute
{
    public function __construct(
        public string $group,
    ) {
    }
}
