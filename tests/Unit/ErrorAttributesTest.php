<?php

use Langsys\OpenApiDocsGenerator\Generators\Attributes\Description;
use Langsys\OpenApiDocsGenerator\Generators\Attributes\EnvelopeField;
use Langsys\OpenApiDocsGenerator\Generators\Attributes\Throws;
use Spatie\LaravelData\Data;

class ThrowsTargetFixture extends Data
{
    public function __construct(
        public int $required,
    ) {
    }
}

class EnvelopeFieldFixture extends Data
{
    public function __construct(
        #[EnvelopeField]
        #[Description('Field name to messages')]
        public array $errors,
        public int $other,
    ) {
    }
}

class ThrowsFixtureController
{
    #[Throws(ThrowsTargetFixture::class, EnvelopeFieldFixture::class)]
    public function store(): void
    {
    }

    #[Throws]
    public function index(): void
    {
    }
}

it('collects Throws error classes from a controller method', function () {
    $attr = (new ReflectionMethod(ThrowsFixtureController::class, 'store'))->getAttributes(Throws::class)[0]->newInstance();

    expect($attr->errorClasses)->toBe([ThrowsTargetFixture::class, EnvelopeFieldFixture::class]);
});

it('allows an empty Throws list', function () {
    $attr = (new ReflectionMethod(ThrowsFixtureController::class, 'index'))->getAttributes(Throws::class)[0]->newInstance();

    expect($attr->errorClasses)->toBe([]);
});

it('allows Throws on a controller class as well as a method', function () {
    $controller = new #[Throws(ThrowsTargetFixture::class)] class {
        public function anything(): void
        {
        }
    };

    $attr = (new ReflectionClass($controller))->getAttributes(Throws::class)[0]->newInstance();

    expect($attr->errorClasses)->toBe([ThrowsTargetFixture::class]);
});

it('marks a property as an envelope field alongside a property-level Description', function () {
    $errors = new ReflectionProperty(EnvelopeFieldFixture::class, 'errors');
    $other = new ReflectionProperty(EnvelopeFieldFixture::class, 'other');

    expect($errors->getAttributes(EnvelopeField::class))->toHaveCount(1)
        ->and($errors->getAttributes(Description::class)[0]->newInstance()->content)->toBe('Field name to messages')
        ->and($other->getAttributes(EnvelopeField::class))->toHaveCount(0);
});

it('rejects attributes on targets they do not support', function () {
    $cases = [
        'Throws on a property' => fn () => (new ReflectionProperty(new class {
            #[Throws]
            public string $p = '';
        }, 'p'))->getAttributes(Throws::class)[0]->newInstance(),
        'EnvelopeField on a class' => fn () => (new ReflectionClass(new #[EnvelopeField] class {
        }))->getAttributes(EnvelopeField::class)[0]->newInstance(),
        // An error class is documented by its MESSAGE constant, so Description is property-only.
        'Description on a class' => fn () => (new ReflectionClass(new #[Description('x')] class {
        }))->getAttributes(Description::class)[0]->newInstance(),
    ];

    foreach ($cases as $instantiate) {
        expect($instantiate)->toThrow(Error::class);
    }
});

it('no longer ships ErrorCode or HttpStatus attributes: error identity is class constants', function () {
    expect(class_exists('Langsys\\OpenApiDocsGenerator\\Generators\\Attributes\\ErrorCode'))->toBeFalse()
        ->and(class_exists('Langsys\\OpenApiDocsGenerator\\Generators\\Attributes\\HttpStatus'))->toBeFalse();
});
