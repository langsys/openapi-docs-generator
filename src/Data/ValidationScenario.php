<?php

namespace Langsys\OpenApiDocsGenerator\Data;

use Langsys\OpenApiDocsGenerator\Exceptions\OpenApiDocsException;

/**
 * One field-level validation failure an endpoint can return.
 *
 * Produced by a {@see \Langsys\OpenApiDocsGenerator\Contracts\ValidationScenarioResolver}
 * and listed in the description of the operation's validation response, so a client
 * can see which outcomes it has to handle rather than one flat "the request failed
 * validation".
 */
final class ValidationScenario
{
    /**
     * @param  string|null  $field  The field this failure is attributed to, or null when
     *                              the rule validates the payload as a whole. Null is
     *                              deliberate: attributing such a rule to whichever
     *                              property it hangs off would mislead.
     * @param  string  $code  The machine-readable code, e.g. "already_taken".
     * @param  string  $message  The human-readable message for that failure.
     *
     * @throws OpenApiDocsException on an empty code, message, or field.
     */
    public function __construct(
        public readonly ?string $field,
        public readonly string $code,
        public readonly string $message,
    ) {
        if ($code === '') {
            throw new OpenApiDocsException('A validation scenario needs a non-empty code.');
        }

        if ($message === '') {
            throw new OpenApiDocsException(sprintf('The validation scenario `%s` needs a non-empty message.', $code));
        }

        if ($field === '') {
            throw new OpenApiDocsException(sprintf(
                'The validation scenario `%s` has an empty field; pass null when the rule validates the whole payload.',
                $code,
            ));
        }
    }

    /**
     * The scenario as one description line: "`field`.`code`: message", or
     * "`code`: message" when it belongs to no single field.
     */
    public function describe(): string
    {
        return $this->field === null
            ? sprintf('`%s`: %s', $this->code, $this->message)
            : sprintf('`%s`.`%s`: %s', $this->field, $this->code, $this->message);
    }
}
