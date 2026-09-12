<?php

namespace Langsys\OpenApiDocsGenerator\Data;

/**
 * An API error discovered from a Spatie Data class carrying #[ErrorCode].
 *
 * Produced by DtoSchemaBuilder; consumed by OpenApiGenerator to emit
 * components.responses.{schemaName} and (L2) to attach errors to operations.
 */
final class ErrorDefinition
{
    public function __construct(
        /** @var class-string */
        public readonly string $className,
        /** Details schema name (class basename), e.g. "InsufficientBalanceError". */
        public readonly string $schemaName,
        /** Envelope schema name, e.g. "InsufficientBalanceErrorResponse". */
        public readonly string $responseSchemaName,
        /** Error object schema name, e.g. "InsufficientBalanceErrorBody". */
        public readonly string $bodySchemaName,
        /** snake_case code from #[ErrorCode]. */
        public readonly string $code,
        /** Default human message from #[ErrorCode], if any. */
        public readonly ?string $message,
        /** HTTP status from #[HttpStatus]. */
        public readonly int $status,
        /** Class-level #[Description], if any. */
        public readonly ?string $description,
        /** Whether the class has non-envelope properties (i.e. a details schema exists). */
        public readonly bool $hasDetails,
    ) {
    }
}
