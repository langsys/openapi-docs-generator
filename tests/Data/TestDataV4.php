<?php

namespace Langsys\OpenApiDocsGenerator\Tests\Data;

use Illuminate\Support\Collection;
use Langsys\OpenApiDocsGenerator\Generators\Attributes\GroupedCollection;
use Spatie\LaravelData\Data;

class TestDataV4 extends Data
{
    public function __construct(
        /** @var ExampleData[] */
        public array $items,

        /** @var Collection<int, ExampleData> */
        public Collection $collection_items,

        /** @var ExampleData[] */
        #[GroupedCollection("en")]
        public array $grouped_items,

        public string $plain_string = 'hello',
    ) {}
}
