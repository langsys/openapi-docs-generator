<?php

namespace Langsys\OpenApiDocsGenerator\Generators;

use OpenApi\Annotations as OA;
use OpenApi\Generator;

/**
 * Prunes components and tags down to what a documentation set actually uses.
 *
 * Computes the transitive `$ref` closure reachable from the surviving operations
 * (and the security requirements they + the document declare), then removes every
 * component not in that closure and every tag no surviving operation references.
 *
 * This is what makes a filtered set correct rather than over-produced: schemas a
 * dropped operation used, and security schemes no surviving operation references,
 * fall away. Security-scheme awareness means that once operations are overridden
 * to a single scheme (see security_override), the unused schemes drop out by the
 * same closure pass. Tags keep their full object (name + description) and their
 * original order — only unused ones are removed.
 *
 * Unlike swagger-php's iterative CleanUnusedComponents, this walks an explicit
 * closure from roots, so mutually-referential-but-unreachable components are
 * correctly pruned.
 */
class ComponentTagPruner
{
    /**
     * Prunable component collections, mapped to [ref segment, name property].
     * Mirrors the two-element entries of OA\Components::$_nested; callbacks and
     * attachables are intentionally excluded.
     */
    private const COLLECTIONS = [
        'schemas' => ['schemas', 'schema'],
        'responses' => ['responses', 'response'],
        'parameters' => ['parameters', 'parameter'],
        'requestBodies' => ['requestBodies', 'request'],
        'examples' => ['examples', 'example'],
        'headers' => ['headers', 'header'],
        'securitySchemes' => ['securitySchemes', 'securityScheme'],
        'links' => ['links', 'link'],
    ];

    private const OPERATION_METHODS = ['get', 'post', 'put', 'patch', 'delete', 'head', 'options', 'trace'];

    public function prune(OA\OpenApi $openapi): void
    {
        $usedTagNames = $this->collectUsedTagNames($openapi);

        if ($openapi->components !== Generator::UNDEFINED) {
            $index = $this->indexComponents($openapi->components);
            $used = $this->computeClosure($openapi, $index);
            $this->removeUnusedComponents($openapi->components, $used);
        }

        $this->pruneTags($openapi, $usedTagNames);
    }

    /**
     * @return array<string, object>  ref => component object
     */
    private function indexComponents(OA\Components $components): array
    {
        $index = [];

        foreach (self::COLLECTIONS as $property => [$segment, $nameProperty]) {
            $collection = $components->{$property};
            if ($collection === Generator::UNDEFINED || ! is_array($collection)) {
                continue;
            }

            foreach ($collection as $component) {
                $ref = $this->componentRef($component, $segment, $nameProperty);
                if ($ref !== null) {
                    $index[$ref] = $component;
                }
            }
        }

        return $index;
    }

    /**
     * Transitive closure of component refs reachable from paths + security.
     *
     * @param  array<string, object>  $index  ref => component object
     * @return array<string, true>  used ref set
     */
    private function computeClosure(OA\OpenApi $openapi, array $index): array
    {
        $refs = [];
        $securityNames = [];

        if ($openapi->paths !== Generator::UNDEFINED && is_array($openapi->paths)) {
            $this->walk($this->toArray($openapi->paths), $refs, $securityNames);
        }

        // OpenAPI 3.1 webhooks can also reference components.
        if (property_exists($openapi, 'webhooks')
            && $openapi->webhooks !== Generator::UNDEFINED
            && is_array($openapi->webhooks)) {
            $this->walk($this->toArray($openapi->webhooks), $refs, $securityNames);
        }

        if ($openapi->security !== Generator::UNDEFINED && is_array($openapi->security)) {
            $this->collectSecurityNames($openapi->security, $securityNames);
        }

        $worklist = array_keys($refs);
        foreach (array_keys($securityNames) as $name) {
            $worklist[] = '#/components/securitySchemes/' . $name;
        }

        $used = [];
        while ($worklist !== []) {
            $ref = array_pop($worklist);
            if (isset($used[$ref])) {
                continue;
            }
            $used[$ref] = true;

            $component = $index[$ref] ?? null;
            if ($component === null) {
                continue;
            }

            $childRefs = [];
            $childSecurity = [];
            $this->walk($this->toArray($component), $childRefs, $childSecurity);

            foreach (array_keys($childRefs) as $childRef) {
                if (! isset($used[$childRef])) {
                    $worklist[] = $childRef;
                }
            }
            foreach (array_keys($childSecurity) as $name) {
                $secRef = '#/components/securitySchemes/' . $name;
                if (! isset($used[$secRef])) {
                    $worklist[] = $secRef;
                }
            }
        }

        return $used;
    }

    /**
     * @param  array<string, true>  $used
     */
    private function removeUnusedComponents(OA\Components $components, array $used): void
    {
        foreach (self::COLLECTIONS as $property => [$segment, $nameProperty]) {
            $collection = $components->{$property};
            if ($collection === Generator::UNDEFINED || ! is_array($collection)) {
                continue;
            }

            $kept = [];
            foreach ($collection as $component) {
                $ref = $this->componentRef($component, $segment, $nameProperty);

                // Keep components we can't name (can't safely prove they're unused).
                if ($ref === null || isset($used[$ref])) {
                    $kept[] = $component;
                }
            }

            $components->{$property} = $kept === [] ? Generator::UNDEFINED : array_values($kept);
        }
    }

    /**
     * @param  array<string, true>  $usedTagNames
     */
    private function pruneTags(OA\OpenApi $openapi, array $usedTagNames): void
    {
        if ($openapi->tags === Generator::UNDEFINED || ! is_array($openapi->tags)) {
            return;
        }

        $kept = array_values(array_filter(
            $openapi->tags,
            fn (OA\Tag $tag): bool => $tag->name !== Generator::UNDEFINED && isset($usedTagNames[$tag->name]),
        ));

        $openapi->tags = $kept === [] ? Generator::UNDEFINED : $kept;
    }

    /**
     * @return array<string, true>
     */
    private function collectUsedTagNames(OA\OpenApi $openapi): array
    {
        $names = [];

        if ($openapi->paths === Generator::UNDEFINED || ! is_array($openapi->paths)) {
            return $names;
        }

        foreach ($openapi->paths as $pathItem) {
            foreach (self::OPERATION_METHODS as $method) {
                $operation = $pathItem->{$method};
                if ($operation === Generator::UNDEFINED) {
                    continue;
                }

                $tags = $operation->tags;
                if ($tags === Generator::UNDEFINED || ! is_array($tags)) {
                    continue;
                }

                foreach ($tags as $tag) {
                    if (is_string($tag)) {
                        $names[$tag] = true;
                    }
                }
            }
        }

        return $names;
    }

    private function componentRef(object $component, string $segment, string $nameProperty): ?string
    {
        $name = $component->{$nameProperty} ?? Generator::UNDEFINED;

        if ($name === Generator::UNDEFINED || ! is_string($name) || $name === '') {
            return null;
        }

        return '#/components/' . $segment . '/' . $name;
    }

    /**
     * Recursively collect `$ref` strings and security-scheme names from a
     * decoded annotation structure.
     *
     * @param  array<string, true>  $refs
     * @param  array<string, true>  $securityNames
     */
    private function walk(mixed $data, array &$refs, array &$securityNames): void
    {
        if (! is_array($data)) {
            return;
        }

        foreach ($data as $key => $value) {
            if ($key === '$ref' && is_string($value)) {
                $refs[$value] = true;
                continue;
            }

            // A discriminator maps a value to a schema by $ref or by bare name —
            // neither of which appears under a "$ref" key, so collect them explicitly.
            if ($key === 'discriminator' && is_array($value)) {
                $this->collectDiscriminatorRefs($value, $refs);
                continue;
            }

            if ($key === 'security' && is_array($value)) {
                $this->collectSecurityNames($value, $securityNames);
                continue;
            }

            $this->walk($value, $refs, $securityNames);
        }
    }

    /**
     * Collect schema refs from a discriminator's `mapping`. Mapping targets may be
     * a full "#/components/..." ref or a bare schema name.
     *
     * @param  array<string, mixed>  $discriminator
     * @param  array<string, true>  $refs
     */
    private function collectDiscriminatorRefs(array $discriminator, array &$refs): void
    {
        $mapping = $discriminator['mapping'] ?? null;

        if (! is_array($mapping)) {
            return;
        }

        foreach ($mapping as $target) {
            if (! is_string($target) || $target === '') {
                continue;
            }

            $refs[str_starts_with($target, '#/') ? $target : '#/components/schemas/' . $target] = true;
        }
    }

    /**
     * @param  array<int, mixed>  $security  A security requirements list, e.g. [['apiKey' => []]].
     * @param  array<string, true>  $securityNames
     */
    private function collectSecurityNames(array $security, array &$securityNames): void
    {
        foreach ($security as $requirement) {
            if (is_array($requirement)) {
                foreach (array_keys($requirement) as $name) {
                    $securityNames[(string) $name] = true;
                }
            }
        }
    }

    /**
     * Serialize an annotation (sub)tree to a plain array so it can be walked
     * uniformly for `$ref` / security usage regardless of nesting shape.
     *
     * @return array<int|string, mixed>
     */
    private function toArray(mixed $value): array
    {
        $json = json_encode($value);
        if ($json === false) {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }
}
