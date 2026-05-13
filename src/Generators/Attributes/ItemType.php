<?php

namespace Langsys\OpenApiDocsGenerator\Generators\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class ItemType extends OpenApiAttribute
{
    public function __construct(
        public string $group,
        public ?string $handle = null,
    ) {
    }
}
