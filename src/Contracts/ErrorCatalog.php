<?php

namespace Langsys\OpenApiDocsGenerator\Contracts;

/**
 * Marks a string-backed enum whose cases are API errors.
 *
 * An error with no typed details is a code, a message and a status. Declaring one
 * class per such error is a lot of files for three values, so they can be cases of
 * one enum instead:
 *
 *   enum ApiErrors: string implements ErrorCatalog
 *   {
 *       #[ErrorCase(status: 409)]
 *       case SamePlanCycle = 'same_plan_cycle';
 *
 *       public function template(): string
 *       {
 *           return match ($this) {
 *               self::SamePlanCycle => 'The plan cycle is already that one.',
 *           };
 *       }
 *   }
 *
 * The backing value is the error's CODE, and each case carries an
 * {@see \Langsys\OpenApiDocsGenerator\Generators\Attributes\ErrorCase} with its
 * status, and template() returns each case's message. The message is a method, not
 * an attribute argument, so an app can write it the way it writes any other text it
 * shows — through its framework's translate function, for instance. A case documents exactly as the equivalent class would: same
 * schema names, same envelope, same description line. An error that needs typed
 * details stays a class, since a case has no properties to carry them.
 *
 * Any enum implementing this interface inside the scanned directories is
 * discovered, with no configuration of its own.
 */
interface ErrorCatalog
{
    /**
     * The case's message in the source language: the documentation text, and the
     * template a client translates. A case has no properties, so it has no markers.
     */
    public function template(): string;
}
