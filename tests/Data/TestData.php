<?php

namespace Langsys\OpenApiDocsGenerator\Tests\Data;

use Langsys\OpenApiDocsGenerator\Generators\Attributes\GroupedCollection;
use Spatie\LaravelData\Data;
use Langsys\OpenApiDocsGenerator\Generators\Attributes\Description;
use Langsys\OpenApiDocsGenerator\Generators\Attributes\Example;
use Spatie\LaravelData\DataCollection;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Illuminate\Support\Collection;

class TestData extends Data
{
    public function __construct(
        #[Example(468)]
        public int $id,
        #[Example('368c23fe-ae9c-4052-9f8c-0bb5622cf3ca')]
        public string $another_id,
        #[Example('collection as array ')]
        public Collection $collection,
        #[Example('array')]
        public array $array,
        #[GroupedCollection("es")]
        #[Example("es-cr")]
        public array $grouped_array,
        #[GroupedCollection("es-cr")]
        #[DataCollectionOf(ExampleData::class)]
        public DataCollection $grouped_collection,
        #[Example('A String')]
        public string $default_string = 'defaultString',
        public int $default_int = 3,
        public bool $default_bool = true,
        #[Example('case2')]
        public ExampleEnum $enum = ExampleEnum::CASE_1,
        public ?ExampleEnum $nullable_enum = null
    ) {}
}
