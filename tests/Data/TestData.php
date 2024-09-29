<?php

namespace Langsys\SwaggerAutoGenerator\Tests\Data;

use Langsys\SwaggerAutoGenerator\Generators\Swagger\Attributes\GroupedCollection;
use Spatie\LaravelData\Data;
use Langsys\SwaggerAutoGenerator\Generators\Swagger\Attributes\Description;
use Langsys\SwaggerAutoGenerator\Generators\Swagger\Attributes\Example;
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

    ) {}
}
