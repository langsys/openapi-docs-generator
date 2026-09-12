<?php

namespace Langsys\OpenApiDocsGenerator\Tests\ErrorOperationFixtures;

use OpenApi\Attributes as OA;

/**
 * An annotation-defined schema no operation references, which itself references an
 * error body. With pruning off it is kept, so the error it points at must be kept
 * too, or the document would carry a dangling $ref.
 */
#[OA\Schema(
    schema: 'NotFoundExample',
    properties: [new OA\Property(property: 'error', ref: '#/components/schemas/NotFoundErrorBody')],
)]
class ErrorWrapperSchema
{
}
