<?php

use Langsys\OpenApiDocsGenerator\Generators\Attributes\Description;
use Langsys\OpenApiDocsGenerator\Generators\Attributes\EnvelopeField;
use Langsys\OpenApiDocsGenerator\Generators\Attributes\ErrorCode;
use Langsys\OpenApiDocsGenerator\Generators\Attributes\HttpStatus;
use Langsys\OpenApiDocsGenerator\Generators\Attributes\Throws;
use Spatie\LaravelData\Data;

#[ErrorCode('insufficient_balance', 'Insufficient balance')]
#[HttpStatus(402)]
#[Description('The account balance cannot cover the request.')]
class InsufficientBalanceErrorFixture extends Data
{
    public function __construct(
        public int $required,
        public int $available,
    ) {
    }
}

#[ErrorCode('validation_failed')]
#[HttpStatus(422)]
class ValidationErrorFixture extends Data
{
    public function __construct(
        #[EnvelopeField]
        public array $errors,
    ) {
    }
}

class ThrowsFixtureController
{
    #[Throws(InsufficientBalanceErrorFixture::class, ValidationErrorFixture::class)]
    public function store(): void
    {
    }

    #[Throws]
    public function index(): void
    {
    }
}

function classAttribute(string $class, string $attribute): object
{
    return (new ReflectionClass($class))->getAttributes($attribute)[0]->newInstance();
}

it('exposes code and message from a class-level ErrorCode attribute', function () {
    $attr = classAttribute(InsufficientBalanceErrorFixture::class, ErrorCode::class);

    expect($attr)->toBeInstanceOf(ErrorCode::class)
        ->and($attr->code)->toBe('insufficient_balance')
        ->and($attr->message)->toBe('Insufficient balance');
});

it('defaults the ErrorCode message to null', function () {
    $attr = classAttribute(ValidationErrorFixture::class, ErrorCode::class);

    expect($attr->code)->toBe('validation_failed')
        ->and($attr->message)->toBeNull();
});

it('exposes the HttpStatus value', function () {
    expect(classAttribute(InsufficientBalanceErrorFixture::class, HttpStatus::class)->status)->toBe(402)
        ->and(classAttribute(ValidationErrorFixture::class, HttpStatus::class)->status)->toBe(422);
});

it('accepts Description at class level', function () {
    expect(classAttribute(InsufficientBalanceErrorFixture::class, Description::class)->content)
        ->toBe('The account balance cannot cover the request.');
});

it('collects Throws error classes from a controller method', function () {
    $method = new ReflectionMethod(ThrowsFixtureController::class, 'store');
    $attr = $method->getAttributes(Throws::class)[0]->newInstance();

    expect($attr->errorClasses)->toBe([
        InsufficientBalanceErrorFixture::class,
        ValidationErrorFixture::class,
    ]);
});

it('allows an empty Throws list', function () {
    $method = new ReflectionMethod(ThrowsFixtureController::class, 'index');

    expect($method->getAttributes(Throws::class)[0]->newInstance()->errorClasses)->toBe([]);
});

it('marks a property as an envelope field', function () {
    $property = new ReflectionProperty(ValidationErrorFixture::class, 'errors');

    expect($property->getAttributes(EnvelopeField::class))->toHaveCount(1)
        ->and((new ReflectionProperty(InsufficientBalanceErrorFixture::class, 'required'))->getAttributes(EnvelopeField::class))->toHaveCount(0);
});

it('rejects error attributes on the wrong target', function () {
    $bad = new class {
        #[ErrorCode('x')]
        public string $p = '';
    };

    (new ReflectionProperty($bad, 'p'))->getAttributes(ErrorCode::class)[0]->newInstance();
})->throws(Error::class);
