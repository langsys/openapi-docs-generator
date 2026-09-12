<?php

namespace Langsys\OpenApiDocsGenerator\Tests\ErrorFixtures;

use OpenApi\Attributes as OA;

#[OA\Info(title: 'Error Fixture API', version: '1.0.0')]
class ErrorFixtureController
{
    #[OA\Post(
        path: '/api/purchase',
        tags: ['Billing'],
        responses: [
            new OA\Response(response: 200, description: 'OK'),
            new OA\Response(response: 402, ref: '#/components/responses/InsufficientBalanceError'),
        ],
    )]
    public function purchase(): void
    {
    }
}
