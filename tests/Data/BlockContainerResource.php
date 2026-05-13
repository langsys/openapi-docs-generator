<?php

namespace Langsys\OpenApiDocsGenerator\Tests\Data;

use Langsys\OpenApiDocsGenerator\Generators\Attributes\OneOfItemsFrom;
use Spatie\LaravelData\Data;

class BlockContainerResource extends Data
{
    public function __construct(
        public string $title,
        #[OneOfItemsFrom('blocks')]
        /** @var array<int, object> */
        public array $content,
    ) {}
}
