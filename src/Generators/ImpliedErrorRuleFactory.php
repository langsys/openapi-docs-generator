<?php

namespace Langsys\OpenApiDocsGenerator\Generators;

use Langsys\OpenApiDocsGenerator\Contracts\ImpliedErrorRule;
use Langsys\OpenApiDocsGenerator\Exceptions\OpenApiDocsException;

/**
 * Builds {@see ImpliedErrorRule} instances from `implied_errors.rules` config.
 *
 * Accepted descriptors, mirroring the operation-filter escape hatch:
 *
 *   ['class' => AuthorizesRule::class, 'args' => [...]]  // constructor arguments
 *   AuthorizesRule::class                                // no arguments
 *   new AuthorizesRule(...)                              // already built
 */
class ImpliedErrorRuleFactory
{
    /**
     * @param  array<int, mixed>  $descriptors
     * @return array<int, ImpliedErrorRule>
     *
     * @throws OpenApiDocsException
     */
    public function makeMany(array $descriptors): array
    {
        return array_map([$this, 'make'], array_values($descriptors));
    }

    /**
     * @throws OpenApiDocsException
     */
    public function make(mixed $descriptor): ImpliedErrorRule
    {
        if ($descriptor instanceof ImpliedErrorRule) {
            return $descriptor;
        }

        if (is_string($descriptor)) {
            $descriptor = ['class' => $descriptor];
        }

        if (! is_array($descriptor) || ! isset($descriptor['class'])) {
            throw new OpenApiDocsException(
                'Unrecognized implied error rule descriptor: ' . json_encode($descriptor)
            );
        }

        $class = $descriptor['class'];

        if (! is_string($class) || ! class_exists($class)) {
            throw new OpenApiDocsException('Implied error rule class does not exist: ' . (is_string($class) ? $class : gettype($class)));
        }

        $rule = new $class(...array_values($descriptor['args'] ?? []));

        if (! $rule instanceof ImpliedErrorRule) {
            throw new OpenApiDocsException(
                "Implied error rule class {$class} must implement " . ImpliedErrorRule::class
            );
        }

        return $rule;
    }
}
