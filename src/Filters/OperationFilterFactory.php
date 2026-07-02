<?php

namespace Langsys\OpenApiDocsGenerator\Filters;

use Langsys\OpenApiDocsGenerator\Contracts\OperationFilter;
use Langsys\OpenApiDocsGenerator\Exceptions\OpenApiDocsException;

/**
 * Builds {@see OperationFilter} instances from config descriptors.
 *
 * Each descriptor is a single-key shorthand for a built-in filter, or a "class"
 * escape hatch for a custom filter:
 *
 *   ['middleware' => 'auth.apikey']            // MiddlewareFilter (match defaults to 'any')
 *   ['middleware' => [...], 'match' => 'all']  // MiddlewareFilter, all-of
 *   ['tag' => 'Public']                        // TagFilter
 *   ['path' => 'webhooks/*']                   // PathFilter
 *   ['operationId' => 'listProjects']          // OperationIdFilter
 *   ['class' => Custom::class, 'args' => [...]] // custom OperationFilter
 */
class OperationFilterFactory
{
    /**
     * @param  array<string, string>  $aliasMap  Router middleware alias map (alias => class),
     *                                           supplied to MiddlewareFilter for alias resolution.
     */
    public function __construct(private array $aliasMap = []) {}

    /**
     * @param  array<int, array<string, mixed>>  $descriptors
     * @return array<int, OperationFilter>
     */
    public function makeMany(array $descriptors): array
    {
        return array_map(fn (array $descriptor): OperationFilter => $this->make($descriptor), array_values($descriptors));
    }

    /**
     * @param  array<string, mixed>  $descriptor
     *
     * @throws OpenApiDocsException
     */
    public function make(array $descriptor): OperationFilter
    {
        if (isset($descriptor['class'])) {
            return $this->makeCustom($descriptor);
        }

        if (array_key_exists('middleware', $descriptor)) {
            return new MiddlewareFilter(
                $descriptor['middleware'],
                $this->aliasMap,
                $descriptor['match'] ?? 'any',
            );
        }

        if (array_key_exists('tag', $descriptor)) {
            return new TagFilter($descriptor['tag']);
        }

        if (array_key_exists('path', $descriptor)) {
            return new PathFilter($descriptor['path']);
        }

        if (array_key_exists('operationId', $descriptor)) {
            return new OperationIdFilter($descriptor['operationId']);
        }

        throw new OpenApiDocsException(
            'Unrecognized operation filter descriptor: ' . json_encode($descriptor)
        );
    }

    /**
     * @param  array<string, mixed>  $descriptor
     *
     * @throws OpenApiDocsException
     */
    private function makeCustom(array $descriptor): OperationFilter
    {
        $class = $descriptor['class'];

        if (! class_exists($class)) {
            throw new OpenApiDocsException("Operation filter class does not exist: {$class}");
        }

        $args = $descriptor['args'] ?? [];
        $filter = new $class(...array_values($args));

        if (! $filter instanceof OperationFilter) {
            throw new OpenApiDocsException(
                "Operation filter class {$class} must implement " . OperationFilter::class
            );
        }

        return $filter;
    }
}
