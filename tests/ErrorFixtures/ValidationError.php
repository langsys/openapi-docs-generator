<?php

namespace Langsys\OpenApiDocsGenerator\Tests\ErrorFixtures;

use Langsys\OpenApiDocsGenerator\Generators\Attributes\Description;
use Langsys\OpenApiDocsGenerator\Generators\Attributes\EnvelopeField;
use Langsys\OpenApiDocsGenerator\Generators\Attributes\ErrorCode;
use Langsys\OpenApiDocsGenerator\Generators\Attributes\HttpStatus;
use Spatie\LaravelData\Data;

#[ErrorCode('validation_failed', 'The given data was invalid')]
#[HttpStatus(422)]
#[Description('One or more request fields failed validation.')]
class ValidationError extends Data
{
    public function __construct(
        /** @var array<string, string[]> */
        #[EnvelopeField]
        #[Description('Field name to list of validation messages')]
        public array $errors,
    ) {
    }
}
