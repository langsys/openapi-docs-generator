<?php

namespace Langsys\OpenApiDocsGenerator\Tests\QueryParameterFixtures;

use OpenApi\Attributes as OA;

#[OA\Info(title: 'Query parameter fixtures', version: '1.0.0')]
#[OA\Parameter(parameter: 'page', name: 'page', in: 'query', description: 'Page to request', schema: new OA\Schema(type: 'integer'))]
#[OA\Parameter(parameter: 'records_per_page', name: 'records_per_page', in: 'query', schema: new OA\Schema(type: 'integer'))]
#[OA\Parameter(parameter: 'order_by', name: 'order_by', in: 'query', schema: new OA\Schema(type: 'string'))]
#[OA\Parameter(parameter: 'filter_by', name: 'filter_by', in: 'query', schema: new OA\Schema(type: 'string'))]
#[OA\Parameter(parameter: 'projectId', name: 'projectId', in: 'path', required: true, schema: new OA\Schema(type: 'string'))]
class QueryParametersController
{
    #[OA\Get(
        path: '/api/projects',
        responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/ProjectPaginatedResponse'))],
    )]
    public function index(ListProjectsRequest $request): void
    {
    }

    #[OA\Get(
        path: '/api/projects/{projectId}/children',
        parameters: [new OA\Parameter(ref: '#/components/parameters/projectId')],
        responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/ProjectPaginatedResponse'))],
    )]
    public function children(string $projectId, ListProjectsRequest $request): void
    {
    }

    #[OA\Get(path: '/api/plain', responses: [new OA\Response(response: 200, description: 'OK')])]
    public function plain(PlainFilterRequest $request): void
    {
    }

    /** Takes a marked DTO; tests build operations for it by hand. */
    public function duplicated(ListProjectsRequest $request): void
    {
    }

    /** Takes a marked DTO with injected, computed, nested and dotted properties. */
    public function filtered(ProjectFiltersRequest $request): void
    {
    }

    /** Takes two marked DTOs that both read `search`. */
    public function twice(ListProjectsRequest $list, ProjectFiltersRequest $filters): void
    {
    }

    /** Takes no Data class at all. */
    public function bare(): void
    {
    }
}
