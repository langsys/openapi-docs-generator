<?php

namespace Langsys\OpenApiDocsGenerator\Data;

/**
 * An API error discovered from a concrete subclass of `errors.base_class`, with its
 * identity read from the class's CODE, MESSAGE and STATUS constants.
 *
 * Produced by DtoSchemaBuilder; consumed by OpenApiGenerator to emit
 * components.responses.{schemaName} and scope error documentation, and by
 * OperationErrorAttacher to attach errors to operations.
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
        /** The CODE constant: the slug clients branch on. */
        public readonly string $code,
        /** The MESSAGE constant: the default human message, and the documentation text. */
        public readonly string $message,
        /** The STATUS constant, resolved to an int (enum cases read via ->value). */
        public readonly int $status,
        /** Whether the class has non-envelope properties (i.e. a details schema exists). */
        public readonly bool $hasDetails,
    ) {
    }

    /**
     * The one notation every error response description uses: "`code`: MESSAGE".
     *
     * A status with a single error shows this line as its description; a status
     * several errors share lists these lines under "Possible errors:".
     */
    public function codeAndMessage(): string
    {
        return sprintf('`%s`: %s', $this->code, $this->message);
    }
}
