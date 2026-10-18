<?php

namespace Langsys\OpenApiDocsGenerator\Tests\QueryParameterFixtures;

use Langsys\OpenApiDocsGenerator\Generators\Attributes\QueryParameters;
use Spatie\LaravelData\Attributes\Computed;
use Spatie\LaravelData\Attributes\FromRouteParameter;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;

/**
 * Marked on the class itself, with the properties a request does not carry as written:
 * a computed one, injected ones, a nested Data class and a dotted input name. `search`
 * is also read by ListProjectsRequest.
 */
#[QueryParameters]
class ProjectFiltersRequest extends Data
{
    #[Computed]
    public string $label;

    public function __construct(
        #[FromRouteParameter('project')]
        public string $project,
        #[FromRouteParameter('workspace', replaceWhenPresentInPayload: false)]
        public string $workspace,
        public ?ProjectFilter $filter = null,
        #[MapInputName('range.from')]
        public ?string $from = null,
        public ?string $search = null,
    ) {
        $this->label = (string) $search;
    }
}
