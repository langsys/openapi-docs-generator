<?php

namespace Langsys\OpenApiDocsGenerator\Tests\Data;

use Langsys\OpenApiDocsGenerator\Generators\Attributes\ItemType;

#[ItemType('blocks')]
class ParagraphResource extends AbstractBlockResource
{
    public function __construct(
        public string $id,
        public string $text,
    ) {
        parent::__construct($id);
    }
}
