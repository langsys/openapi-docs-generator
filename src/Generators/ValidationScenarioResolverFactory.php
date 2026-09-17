<?php

namespace Langsys\OpenApiDocsGenerator\Generators;

use Langsys\OpenApiDocsGenerator\Contracts\ValidationScenarioResolver;
use Langsys\OpenApiDocsGenerator\Exceptions\OpenApiDocsException;

/**
 * Builds {@see ValidationScenarioResolver} instances from
 * `implied_errors.validation_scenarios` config.
 *
 * Accepted descriptors, mirroring the implied-error rule escape hatch:
 *
 *   FieldErrorScenarios::class                                // no arguments
 *   ['class' => FieldErrorScenarios::class, 'args' => [...]]  // constructor arguments
 *   new FieldErrorScenarios(...)                              // already built
 *
 * A single descriptor or a list of them is accepted.
 */
class ValidationScenarioResolverFactory
{
    /**
     * @return array<int, ValidationScenarioResolver>
     *
     * @throws OpenApiDocsException
     */
    public function makeMany(mixed $descriptors): array
    {
        if ($descriptors === null || $descriptors === [] || $descriptors === '') {
            return [];
        }

        // A bare class name, an instance, or a single ['class' => …] descriptor.
        if (! is_array($descriptors) || isset($descriptors['class'])) {
            $descriptors = [$descriptors];
        }

        return array_map([$this, 'make'], array_values($descriptors));
    }

    /**
     * @throws OpenApiDocsException
     */
    public function make(mixed $descriptor): ValidationScenarioResolver
    {
        if ($descriptor instanceof ValidationScenarioResolver) {
            return $descriptor;
        }

        if (is_string($descriptor)) {
            $descriptor = ['class' => $descriptor];
        }

        if (! is_array($descriptor) || ! isset($descriptor['class'])) {
            throw new OpenApiDocsException(
                'Unrecognized validation scenario resolver descriptor: ' . json_encode($descriptor)
            );
        }

        $class = $descriptor['class'];

        if (! is_string($class) || ! class_exists($class)) {
            throw new OpenApiDocsException('Validation scenario resolver class does not exist: ' . (is_string($class) ? $class : get_debug_type($class)));
        }

        $resolver = new $class(...array_values($descriptor['args'] ?? []));

        if (! $resolver instanceof ValidationScenarioResolver) {
            throw new OpenApiDocsException(
                "Validation scenario resolver class {$class} must implement " . ValidationScenarioResolver::class
            );
        }

        return $resolver;
    }
}
