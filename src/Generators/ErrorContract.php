<?php

namespace Langsys\OpenApiDocsGenerator\Generators;

use BackedEnum;
use Langsys\OpenApiDocsGenerator\Exceptions\OpenApiDocsException;
use Langsys\OpenApiDocsGenerator\Generators\Attributes\Description;
use ReflectionClass;
use Spatie\LaravelData\Data;

/**
 * The error class contract: how an API error declares its identity.
 *
 *   class NotFoundError extends ApiError
 *   {
 *       public const CODE = 'not_found';
 *       public const MESSAGE = 'Resource not found';
 *       public const STATUS = 404;                  // or an int-backed enum case
 *   }
 *
 *   class ProjectNotFoundError extends NotFoundError
 *   {
 *       public const CODE = 'project_not_found';
 *       public const MESSAGE = 'No project with that id';
 *       // STATUS inherited
 *   }
 *
 * Every non-abstract subclass of a configured `errors.base_class` is an error.
 * CODE and MESSAGE must be declared by the class itself: constants inherit
 * silently, and an inherited CODE would make two failure modes indistinguishable.
 * STATUS may be inherited, since sharing a status is what a specific error's parent
 * is for. MESSAGE doubles as the documentation text, so a class-level
 * #[Description] is rejected rather than silently ignored.
 */
final class ErrorContract
{
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
     * Read and validate an error class's identity.
     *
     * @return array{code: string, message: string, status: int}
     *
     * @throws OpenApiDocsException naming the class and the rule it breaks.
     */
    public function read(ReflectionClass $class): array
    {
        $this->rejectClassDescription($class);

        return [
            'code' => $this->ownString($class, 'CODE'),
            'message' => $this->ownString($class, 'MESSAGE'),
            'status' => $this->status($class),
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
                '%s must declare const %s: every concrete error class declares its own CODE and MESSAGE.',
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

        $value = $class->getConstant('STATUS');
        $status = $value instanceof BackedEnum ? $value->value : $value;

        if (! is_int($status) || $status < 100 || $status > 599) {
            throw new OpenApiDocsException(sprintf(
                '%s::STATUS must be an HTTP status code between 100 and 599 (an int, or an int-backed enum case); got %s.',
                $class->getName(),
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
                    '%s has a class-level #[Description]%s; an error class is documented by its MESSAGE constant, so remove the attribute.',
                    $class->getName(),
                    $current->getName() === $class->getName() ? '' : ' (on ' . $current->getName() . ')',
                ));
            }
        }
    }
}
