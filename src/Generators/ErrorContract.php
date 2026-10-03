<?php

namespace Langsys\OpenApiDocsGenerator\Generators;

use BackedEnum;
use Langsys\OpenApiDocsGenerator\Exceptions\OpenApiDocsException;
use Langsys\OpenApiDocsGenerator\Contracts\ErrorCatalog;
use Langsys\OpenApiDocsGenerator\Generators\Attributes\Description;
use Langsys\OpenApiDocsGenerator\Generators\Attributes\ErrorCase;
use ReflectionClass;
use ReflectionEnum;
use ReflectionEnumBackedCase;
use Spatie\LaravelData\Data;

/**
 * The error class contract: how an API error declares its identity.
 *
 *   class NotFoundError extends ApiError
 *   {
 *       public const CODE = 'not_found';
 *       public const STATUS = 404;                  // or an int-backed enum case
 *
 *       public function template(): string
 *       {
 *           return 'Resource not found';
 *       }
 *   }
 *
 *   class ProjectNotFoundError extends NotFoundError
 *   {
 *       public const CODE = 'project_not_found';
 *       // STATUS inherited
 *
 *       public function template(): string
 *       {
 *           return 'No project with that id';
 *       }
 *   }
 *
 * Every non-abstract subclass of a configured `errors.base_class` is an error.
 * CODE and template() must be declared by the class itself: constants and methods
 * inherit silently, and an inherited CODE or message would make two failure modes
 * indistinguishable. STATUS may be inherited, since sharing a status is what a
 * specific error's parent is for. template() returns the source-language message; it
 * is a method rather than a constant so an app can write it through its framework's
 * translate function. It is read on an instance created without its constructor, so
 * the message never depends on an error's data: markers are filled from properties. It doubles as the documentation text, so a class-level
 * #[Description] is rejected rather than silently ignored. It may contain `{marker}`
 * placeholders, and each must name a public property of the class, which fills it.
 */
final class ErrorContract
{
    /** A `{marker}` placeholder in a template: a lowercase name in braces. */
    public const MARKER_PATTERN = '/\{([a-z][a-z0-9_]*)\}/';

    /** @var array<int, class-string> */
    private array $baseClasses = [];

    /**
     * @param  string|array<int, string>|null  $baseClasses  `errors.base_class`; null disables error discovery.
     *
     * @throws OpenApiDocsException when a base class doesn't exist or doesn't extend Spatie Data.
     */
    public function __construct(string|array|null $baseClasses = null)
    {
        foreach ((array) $baseClasses as $baseClass) {
            if (! is_string($baseClass) || ! class_exists($baseClass)) {
                throw new OpenApiDocsException(sprintf(
                    'errors.base_class %s does not exist.',
                    is_string($baseClass) ? $baseClass : get_debug_type($baseClass),
                ));
            }

            if (! is_a($baseClass, Data::class, true)) {
                throw new OpenApiDocsException(sprintf('errors.base_class %s must extend %s.', $baseClass, Data::class));
            }

            $this->baseClasses[] = ltrim($baseClass, '\\');
        }
    }

    /**
     * @return array<int, class-string>
     */
    public function baseClasses(): array
    {
        return $this->baseClasses;
    }

    /**
     * Whether a class is an error: a non-abstract subclass of a configured base class.
     */
    public function isErrorClass(string $class): bool
    {
        if ($this->baseClasses === [] || ! class_exists($class)) {
            return false;
        }

        if ((new ReflectionClass($class))->isAbstract()) {
            return false;
        }

        foreach ($this->baseClasses as $baseClass) {
            if (is_subclass_of($class, $baseClass)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a type is an error catalog: an enum whose cases are errors.
     */
    public function isErrorCatalog(string $type): bool
    {
        return enum_exists($type) && is_subclass_of($type, ErrorCatalog::class);
    }

    /**
     * The cases of an error catalog.
     *
     * @return array<int, ReflectionEnumBackedCase>
     *
     * @throws OpenApiDocsException when the enum isn't string-backed, since the backing
     *                              value is the error's code.
     */
    public function casesOf(string $enum): array
    {
        $reflection = new ReflectionEnum($enum);
        $backingType = $reflection->getBackingType()?->getName();

        if ($backingType !== 'string') {
            throw new OpenApiDocsException(sprintf(
                '%s implements %s, so it must be a string-backed enum whose backing value is the error code; got %s.',
                $enum,
                ErrorCatalog::class,
                $backingType ?? 'no backing type',
            ));
        }

        /** @var array<int, ReflectionEnumBackedCase> $cases */
        $cases = $reflection->getCases();

        return $cases;
    }

    /**
     * Read and validate one error declared as an enum case: its backing value is the
     * code, its #[ErrorCase] carries the status, and the enum's template() the message.
     *
     * @return array{code: string, message: string, status: int, markers: array<int, string>}
     *
     * @throws OpenApiDocsException naming the enum, the case and the rule it breaks.
     */
    public function readCase(ReflectionEnumBackedCase $case): array
    {
        $name = $case->getDeclaringClass()->getName() . '::' . $case->getName();
        $attributes = $case->getAttributes(ErrorCase::class);

        if ($attributes === []) {
            throw new OpenApiDocsException(sprintf(
                '%s must carry #[ErrorCase(status: …)]: every case of an error catalog declares its own status.',
                $name,
            ));
        }

        /** @var ErrorCase $errorCase */
        $errorCase = $attributes[0]->newInstance();
        $code = (string) $case->getBackingValue();

        if ($code === '') {
            throw new OpenApiDocsException(sprintf('%s must have a non-empty backing value: it is the error code.', $name));
        }

        $enum = $case->getDeclaringClass()->getName();
        $message = $enum::from($code)->template();

        if (! is_string($message) || $message === '') {
            throw new OpenApiDocsException(sprintf('The template() of %s must return a non-empty string.', $name));
        }

        $markers = $this->markerNames($message);

        if ($markers !== []) {
            throw new OpenApiDocsException(sprintf(
                'The template() of %s uses the marker {%s}, but an enum case has no properties to fill it; '
                . 'declare this error as a class with that property, or remove the marker.',
                $name,
                $markers[0],
            ));
        }

        return [
            'code' => $code,
            'message' => $message,
            'status' => $this->normalizeStatus($errorCase->status, 'The #[ErrorCase] status on ' . $name),
            'markers' => [],
        ];
    }

    /**
     * Read and validate an error class's identity.
     *
     * @return array{code: string, message: string, status: int, markers: array<int, string>}
     *
     * @throws OpenApiDocsException naming the class and the rule it breaks.
     */
    public function read(ReflectionClass $class): array
    {
        $this->rejectClassDescription($class);

        $code = $this->ownString($class, 'CODE');
        $message = $this->ownTemplate($class);

        return [
            'code' => $code,
            'message' => $message,
            'status' => $this->status($class),
            'markers' => $this->markers($class, $message),
        ];
    }

    /**
     * @throws OpenApiDocsException
     */
    private function ownString(ReflectionClass $class, string $name): string
    {
        $constant = $class->getReflectionConstant($name);

        if ($constant === false) {
            throw new OpenApiDocsException(sprintf(
                '%s must declare const %s: every concrete error class declares its own CODE and template().',
                $class->getName(),
                $name,
            ));
        }

        $declaringClass = $constant->getDeclaringClass()->getName();

        if ($declaringClass !== $class->getName()) {
            throw new OpenApiDocsException(sprintf(
                '%s inherits %s from %s instead of declaring its own. Constants inherit silently, '
                . 'so two failure modes would share one %s; declare const %s on %s.',
                $class->getName(),
                $name,
                $declaringClass,
                $name,
                $name,
                $class->getShortName(),
            ));
        }

        $value = $constant->getValue();

        if (! is_string($value) || $value === '') {
            throw new OpenApiDocsException(sprintf('%s::%s must be a non-empty string.', $class->getName(), $name));
        }

        return $value;
    }

    /**
     * The class's own public template(), called on an instance created without its
     * constructor: the source-language message, independent of any error's data.
     *
     * @throws OpenApiDocsException
     */
    private function ownTemplate(ReflectionClass $class): string
    {
        if (! $class->hasMethod('template')) {
            throw new OpenApiDocsException(sprintf(
                '%s must declare public function template(): string: every concrete error class declares its own CODE and template().',
                $class->getName(),
            ));
        }

        $method = $class->getMethod('template');
        $declaringClass = $method->getDeclaringClass()->getName();

        if ($declaringClass !== $class->getName()) {
            throw new OpenApiDocsException(sprintf(
                '%s inherits template() from %s instead of declaring its own. Methods inherit silently, '
                . 'so two failure modes would share one message; declare template() on %s.',
                $class->getName(),
                $declaringClass,
                $class->getShortName(),
            ));
        }

        if (! $method->isPublic()) {
            throw new OpenApiDocsException(sprintf(
                '%s::template() must be public: the documentation calls it on an instance created without its constructor.',
                $class->getName(),
            ));
        }

        $value = $method->invoke($class->newInstanceWithoutConstructor());

        if (! is_string($value) || $value === '') {
            throw new OpenApiDocsException(sprintf('%s::template() must return a non-empty string.', $class->getName()));
        }

        return $value;
    }

    /**
     * @throws OpenApiDocsException
     */
    private function status(ReflectionClass $class): int
    {
        if (! $class->hasConstant('STATUS')) {
            throw new OpenApiDocsException(sprintf(
                '%s has no STATUS constant, declared or inherited; declare const STATUS (an int, or an int-backed enum case) on it or a parent.',
                $class->getName(),
            ));
        }

        return $this->normalizeStatus($class->getConstant('STATUS'), $class->getName() . '::STATUS');
    }

    /**
     * An HTTP status from an int or an int-backed enum case.
     *
     * @throws OpenApiDocsException naming the subject that declared it.
     */
    private function normalizeStatus(mixed $value, string $subject): int
    {
        $status = $value instanceof BackedEnum ? $value->value : $value;

        if (! is_int($status) || $status < 100 || $status > 599) {
            throw new OpenApiDocsException(sprintf(
                '%s must be an HTTP status code between 100 and 599 (an int, or an int-backed enum case); got %s.',
                $subject,
                $value instanceof BackedEnum
                    ? get_class($value) . '::' . $value->name . ' (' . var_export($value->value, true) . ')'
                    : var_export($value, true),
            ));
        }

        return $status;
    }

    /**
     * @throws OpenApiDocsException
     */
    private function rejectClassDescription(ReflectionClass $class): void
    {
        for ($current = $class; $current !== false; $current = $current->getParentClass()) {
            if ($current->getAttributes(Description::class) !== []) {
                throw new OpenApiDocsException(sprintf(
                    '%s has a class-level #[Description]%s; an error class is documented by its template(), so remove the attribute.',
                    $class->getName(),
                    $current->getName() === $class->getName() ? '' : ' (on ' . $current->getName() . ')',
                ));
            }
        }
    }

    /**
     * The `{marker}` names in a class's template(), in order of first appearance. Each must name a
     * public, non-static property of the class, since that property fills it.
     *
     * @return array<int, string>
     *
     * @throws OpenApiDocsException
     */
    private function markers(ReflectionClass $class, string $message): array
    {
        $markers = $this->markerNames($message);

        foreach ($markers as $marker) {
            $property = $class->hasProperty($marker) ? $class->getProperty($marker) : null;

            if ($property === null || ! $property->isPublic() || $property->isStatic()) {
                throw new OpenApiDocsException(sprintf(
                    '%s::template() uses the marker {%s}, but %s has no public property $%s to fill it; add the property or remove the marker.',
                    $class->getName(),
                    $marker,
                    $class->getShortName(),
                    $marker,
                ));
            }
        }

        return $markers;
    }

    /**
     * The `{marker}` names in a message, in order of first appearance.
     *
     * @return array<int, string>
     */
    private function markerNames(string $message): array
    {
        preg_match_all(self::MARKER_PATTERN, $message, $matches);

        return array_values(array_unique($matches[1]));
    }
}
