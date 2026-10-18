<?php

namespace Langsys\OpenApiDocsGenerator\Generators\Attributes;

use Attribute;
use ReflectionClass;

/**
 * Marks a Data class whose properties are query parameters: an operation whose
 * controller action takes it gets one `in: query` parameter per public property.
 *
 * It counts on the class or any parent, so a single list base DTO covers every
 * DTO that extends it.
 *
 * @example #[QueryParameters] abstract class ListRequest extends Data { … }
 */
#[Attribute(Attribute::TARGET_CLASS)]
class QueryParameters extends OpenApiAttribute
{
    /**
     * Whether the class, or any parent, carries the attribute. PHP does not
     * inherit attributes, so the parents are walked here.
     */
    public static function appliesTo(string $class): bool
    {
        for ($reflection = new ReflectionClass($class); $reflection !== false; $reflection = $reflection->getParentClass()) {
            if ($reflection->getAttributes(self::class) !== []) {
                return true;
            }
        }

        return false;
    }
}
