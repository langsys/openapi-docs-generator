<?php

namespace Langsys\OpenApiDocsGenerator\Tests\DanglingFixtures;

use OpenApi\Attributes as OAT;

/**
 * Fixture with a deliberately dangling reference: the operation references a
 * parameter component that is never defined. Kept OUT of tests/Fixtures so it
 * does not pollute the other scan-based tests.
 */
#[OAT\Info(title: "Dangling API", version: "1.0.0")]
class DanglingController
{
    #[OAT\Get(
        path: "/api/thing",
        parameters: [
            new OAT\Parameter(ref: "#/components/parameters/missingParam"),
        ],
        responses: [
            new OAT\Response(response: 200, description: "OK"),
        ]
    )]
    public function show(): void
    {
    }
}
