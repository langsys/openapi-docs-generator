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
 *       #[ErrorCase(status: 409, message: 'The plan cycle is already that one.')]
 *       case SamePlanCycle = 'same_plan_cycle';
 *   }
 *
 * The backing value is the error's CODE, and each case carries an
 * {@see \Langsys\OpenApiDocsGenerator\Generators\Attributes\ErrorCase} with its
 * status and message. A case documents exactly as the equivalent class would: same
 * schema names, same envelope, same description line. An error that needs typed
 * details stays a class, since a case has no properties to carry them.
 *
 * Any enum implementing this interface inside the scanned directories is
 * discovered, with no configuration of its own.
 */
interface ErrorCatalog
{
}
