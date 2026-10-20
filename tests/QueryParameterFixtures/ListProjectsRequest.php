<?php

namespace Langsys\OpenApiDocsGenerator\Tests\QueryParameterFixtures;

use Langsys\OpenApiDocsGenerator\Generators\Attributes\Description;
use Langsys\OpenApiDocsGenerator\Generators\Attributes\Example;
use Langsys\OpenApiDocsGenerator\Generators\Attributes\Omit;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Optional;

class ListProjectsRequest extends ListRequest
{
    public function __construct(
        #[Description('Only projects in this status')]
        public ProjectStatus $status,
        public string|Optional $cursor,
        #[Description('Matches the project name')]
        #[Example('acme')]
        public ?string $search = null,
        #[Example(42)]
        public array $ids = [],
        #[MapInputName('include_archived')]
        public bool $includeArchived = false,
        public string|int $owner = 'me',
        /** @var array<string, string> */
        #[Example(['source' => 'import'])]
        public array $meta = [],
        #[Omit]
        public ?string $internal = null,
    ) {
    }
}
