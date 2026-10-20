<?php

namespace Langsys\OpenApiDocsGenerator\Tests\QueryParameterFixtures;

use Langsys\OpenApiDocsGenerator\Generators\Attributes\Example;
use Langsys\OpenApiDocsGenerator\Generators\Attributes\QueryParameters;
use Spatie\LaravelData\Attributes\Computed;
use Spatie\LaravelData\Attributes\FromRouteParameter;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\Validation\DateFormat;
use Spatie\LaravelData\Attributes\Validation\Uuid;
use Spatie\LaravelData\Data;

/**
 * Marked on the class itself, with the properties a request does not carry as written:
 * computed ones (#[Computed] and a subclass of it), injected ones, a nested Data class
 * and a dotted input name; and #[Uuid] on a string, a union and an array, a
 * #[DateFormat('Y-m-d')] date, and #[Uuid] with a Faker #[Example] that does not fit it
 * (string, union, mixed); the array carries a list #[Example]. `search` is also read by
 * ListProjectsRequest.
 */
#[QueryParameters]
class ProjectFiltersRequest extends Data
{
    #[Computed]
    public string $label;

    #[Summarised]
    public string $summary;

    public function __construct(
        #[FromRouteParameter('project')]
        public string $project,
        // Positional: the flag is `replaceWhenPresentInBody` before laravel-data 4.12.
        #[FromRouteParameter('workspace', false)]
        public string $workspace,
        public ?ProjectFilter $filter = null,
        #[MapInputName('range.from')]
        public ?string $from = null,
        public ?string $search = null,
        #[Uuid]
        public ?string $owner_id = null,
        #[Uuid]
        public string|array|null $owners = null,
        #[Uuid, Example(['0b7e7dee-87ac-4f5e-9d3b-6a1d4c1d2e3f', '6f1c2d3e-4a5b-4c6d-8e7f-9a0b1c2d3e4f'])]
        public array $project_ids = [],
        #[DateFormat('Y-m-d')]
        public ?string $since = null,
        #[Uuid, Example(':word')]
        public ?string $owner_ref = null,
        #[Uuid, Example(':word')]
        public string|array|null $owner_refs = null,
        #[Uuid, Example(':word')]
        public mixed $owner_any = null,
    ) {
        $this->label = (string) $search;
        $this->summary = $this->label;
    }
}
