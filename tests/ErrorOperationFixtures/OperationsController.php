<?php

namespace Langsys\OpenApiDocsGenerator\Tests\ErrorOperationFixtures;

use Langsys\OpenApiDocsGenerator\Generators\Attributes\Throws;
use Langsys\OpenApiDocsGenerator\Tests\ErrorFixtures\InsufficientBalanceError;
use Langsys\OpenApiDocsGenerator\Tests\ErrorFixtures\ValidationError;
use OpenApi\Attributes as OA;

#[OA\Info(title: 'Error Operations API', version: '1.0.0')]
class OperationsController
{
    #[OA\Post(path: '/api/purchase', tags: ['Billing'], responses: [new OA\Response(response: 200, description: 'OK')])]
    #[Throws(InsufficientBalanceError::class)]
    public function purchase(): void
    {
    }

    #[OA\Post(path: '/api/batch', tags: ['Billing'], responses: [new OA\Response(response: 200, description: 'OK')])]
    #[Throws(ValidationError::class, BatchTooLargeError::class)]
    public function batch(): void
    {
    }

    #[OA\Get(
        path: '/api/projects/{project}',
        tags: ['Projects'],
        responses: [
            new OA\Response(response: 200, description: 'OK'),
            new OA\Response(response: 402, description: 'Hand-written, wins over the attached one'),
        ],
    )]
    #[Throws(InsufficientBalanceError::class)]
    public function show(Project $project): void
    {
    }

    #[OA\Post(path: '/api/projects', tags: ['Projects'], responses: [new OA\Response(response: 201, description: 'Created')])]
    public function store(StoreProjectRequest $request): void
    {
    }
}
