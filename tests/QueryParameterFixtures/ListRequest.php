<?php

namespace Langsys\OpenApiDocsGenerator\Tests\QueryParameterFixtures;

use Langsys\OpenApiDocsGenerator\Generators\Attributes\QueryParameters;
use Spatie\LaravelData\Data;

/**
 * The list parent every list DTO extends: its properties match the shared components.
 * They are plain properties, not constructor-promoted, so each child keeps its own constructor.
 */
#[QueryParameters]
abstract class ListRequest extends Data
{
    public int $page = 1;

    public int $records_per_page = 25;

    public string|array|null $order_by = null;

    public string|array|null $filter_by = null;
}
