<?php

namespace Langsys\OpenApiDocsGenerator\Tests\ErrorFixtures;

use Langsys\OpenApiDocsGenerator\Generators\Attributes\Description;
use Langsys\OpenApiDocsGenerator\Generators\Attributes\EnvelopeField;

/** STATUS as an int-backed enum case; its only property is an envelope field. */
class ValidationError extends ApiError
{
    public const CODE = 'validation_failed';
    public const MESSAGE = 'One or more request fields failed validation.';
    public const STATUS = HttpCode::UNPROCESSABLE_ENTITY;

    public function __construct(
        /** @var array<string, string[]> */
        #[EnvelopeField]
        #[Description('Field name to list of validation messages')]
        public array $errors,
    ) {
    }
}
