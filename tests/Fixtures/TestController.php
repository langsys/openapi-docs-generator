<?php

namespace Langsys\OpenApiDocsGenerator\Tests\Fixtures;

use OpenApi\Attributes as OAT;

#[OAT\Info(title: "Test API", version: "1.0.0")]
#[OAT\PathItem(path: "/api")]
class TestController
{
    #[OAT\Get(
        path: "/api/examples",
        summary: "List examples",
        responses: [
            new OAT\Response(
                response: 200,
                description: "Successful response",
                content: new OAT\JsonContent(
                    type: "array",
                    items: new OAT\Items(ref: "#/components/schemas/ExampleData")
                )
            ),
        ]
    )]
    public function index(): void
    {
    }
}
