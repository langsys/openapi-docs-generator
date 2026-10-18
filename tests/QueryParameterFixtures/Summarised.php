<?php

namespace Langsys\OpenApiDocsGenerator\Tests\QueryParameterFixtures;

use Attribute;
use Spatie\LaravelData\Attributes\Computed;

/** An app's own computed marker: laravel-data treats a subclass of #[Computed] as computed. */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Summarised extends Computed
{
}
