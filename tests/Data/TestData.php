<?php
namespace Langsys\SwaggerAutoGenerator\Tests\Data;

use Langsys\SwaggerAutoGenerator\Generators\Swagger\Attributes\GroupedCollection;
use Spatie\LaravelData\Data;
use Langsys\SwaggerAutoGenerator\Generators\Swagger\Attributes\Description;
use Langsys\SwaggerAutoGenerator\Generators\Swagger\Attributes\Example;
use Illuminate\Support\Collection;

class TestData extends Data
{
    public function __construct(
        #[Description('List of locales the project is meant to be translated to.'), Example('fr-ca'), GroupedCollection('fr')]
        public array $target_locales,
    ) {
    }
}
