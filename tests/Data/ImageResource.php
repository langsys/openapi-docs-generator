<?php

namespace Langsys\OpenApiDocsGenerator\Tests\Data;

use Langsys\OpenApiDocsGenerator\Generators\Attributes\ItemType;

#[ItemType('blocks', 'picture')]
class ImageResource extends AbstractBlockResource
{
    public function __construct(
        public string $id,
        public string $url,
    ) {
        parent::__construct($id);
    }
}
