<?php

namespace Langsys\OpenApiDocsGenerator\Generators;

/**
 * Finds unresolved local `$ref`s in a generated OpenAPI document — references
 * that point at a component (or any `#/...` location) that is never defined.
 *
 * The pruner guarantees the generator never *drops* something referenced, but it
 * cannot catch a `$ref` that was referenced-but-never-defined in the first place
 * (e.g. an `@OA\Parameter(ref="#/components/parameters/tier")` with no matching
 * definition block). Because the scan runs with swagger-php validation disabled,
 * such refs otherwise pass through silently. This validator surfaces them.
 *
 * It is shape-agnostic: it collects `$ref` strings at any nesting plus
 * `discriminator.mapping` targets, then resolves each local ref as a JSON pointer
 * against the document.
 */
class ReferenceValidator
{
    /**
     * @param  array<int|string, mixed>  $document  The decoded OpenAPI document.
     * @return array<int, array{ref: string, location: string}>  Unresolved refs, most-referenced first is not guaranteed; order follows discovery.
     */
    public function unresolvedRefs(array $document): array
    {
        /** @var array<int, array{0: string, 1: array<int, string>}> $refs */
        $refs = [];
        $this->collect($document, [], $refs);

        $unresolved = [];
        $seen = [];

        foreach ($refs as [$ref, $location]) {
            if (! $this->isLocal($ref) || $this->resolves($ref, $document)) {
                continue;
            }

            $formatted = $this->formatLocation($location);
            $key = $ref . '@' . $formatted;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $unresolved[] = ['ref' => $ref, 'location' => $formatted];
        }

        return $unresolved;
    }

    /**
     * @param  array<int, string>  $path
     * @param  array<int, array{0: string, 1: array<int, string>}>  $refs
     */
    private function collect(mixed $node, array $path, array &$refs): void
    {
        if (! is_array($node)) {
            return;
        }

        foreach ($node as $key => $value) {
            if ($key === '$ref' && is_string($value)) {
                $refs[] = [$value, $path];
                continue;
            }

            if ($key === 'discriminator' && is_array($value) && isset($value['mapping']) && is_array($value['mapping'])) {
                foreach ($value['mapping'] as $target) {
                    if (is_string($target) && $target !== '') {
                        $ref = str_starts_with($target, '#/') ? $target : '#/components/schemas/' . $target;
                        $refs[] = [$ref, [...$path, 'discriminator', 'mapping']];
                    }
                }
            }

            $this->collect($value, [...$path, (string) $key], $refs);
        }
    }

    private function isLocal(string $ref): bool
    {
        return str_starts_with($ref, '#/');
    }

    /**
     * Resolve a local `#/...` ref as a JSON pointer against the document.
     *
     * @param  array<int|string, mixed>  $document
     */
    private function resolves(string $ref, array $document): bool
    {
        $pointer = ltrim(substr($ref, 1), '/');
        $segments = $pointer === '' ? [] : explode('/', $pointer);

        $node = $document;
        foreach ($segments as $segment) {
            $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);

            if (is_array($node) && array_key_exists($segment, $node)) {
                $node = $node[$segment];
                continue;
            }

            return false;
        }

        return true;
    }

    /**
     * Turn a location path into a friendly string: a ref used inside an operation
     * renders as "METHOD /path"; otherwise the raw JSON path.
     *
     * @param  array<int, string>  $segments
     */
    private function formatLocation(array $segments): string
    {
        if (($segments[0] ?? null) === 'paths' && isset($segments[1], $segments[2])) {
            return strtoupper($segments[2]) . ' ' . $segments[1];
        }

        return $segments === [] ? '(root)' : implode('/', $segments);
    }
}
