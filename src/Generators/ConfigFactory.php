<?php

namespace Langsys\OpenApiDocsGenerator\Generators;

class ConfigFactory
{
    /**
     * Get the fully merged configuration for a documentation set.
     */
    public static function documentationConfig(string $documentation): array
    {
        $defaults = config('openapi-docs.defaults', []);
        $docConfig = config("openapi-docs.documentations.{$documentation}", []);

        return self::deepMerge($defaults, $docConfig);
    }

    /**
     * Deep merge two arrays.
     *
     * Associative arrays are merged recursively.
     * Scalars and indexed arrays are replaced by the override.
     */
    public static function deepMerge(array $base, array $override): array
    {
        $merged = $base;

        foreach ($override as $key => $value) {
            if (
                is_array($value)
                && isset($merged[$key])
                && is_array($merged[$key])
                && self::isAssociative($value)
                && self::isAssociative($merged[$key])
            ) {
                $merged[$key] = self::deepMerge($merged[$key], $value);
            } else {
                $merged[$key] = $value;
            }
        }

        return $merged;
    }

    /**
     * Determine if an array is associative (string keys).
     */
    private static function isAssociative(array $array): bool
    {
        if (empty($array)) {
            return true;
        }

        return !array_is_list($array);
    }
}
