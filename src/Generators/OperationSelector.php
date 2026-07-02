<?php

namespace Langsys\OpenApiDocsGenerator\Generators;

use Langsys\OpenApiDocsGenerator\Contracts\OperationFilter;
use Langsys\OpenApiDocsGenerator\Contracts\RouteResolver;
use Langsys\OpenApiDocsGenerator\Data\OperationContext;
use Langsys\OpenApiDocsGenerator\Data\ResolvableOperation;
use Langsys\OpenApiDocsGenerator\Data\SelectionReport;
use OpenApi\Annotations as OA;
use OpenApi\Context;
use OpenApi\Generator;
use Psr\Log\LoggerInterface;

/**
 * Selects which operations belong in a filtered documentation set.
 *
 * For each operation it resolves the backing Laravel route (action-first) and
 * builds an {@see OperationContext}, then decides disposition:
 *
 *  - Matched operations (a route was resolved): kept iff they match ANY include
 *    filter (an empty include list means "all") AND NO exclude filter.
 *  - Unmatched operations (no route): governed solely by the `unmatched` policy —
 *    'exclude' (default) drops them, 'include' keeps them. The rationale is that
 *    an operation we cannot tie to a route cannot be proven to carry the
 *    middleware a filtered set requires.
 *
 * Non-selected operations are removed in place; path items left with no
 * operations are dropped. The returned {@see SelectionReport} — always logged —
 * records kept/dropped/unmatched so annotation-vs-route drift is never silent.
 */
class OperationSelector
{
    private const OPERATION_METHODS = ['get', 'post', 'put', 'patch', 'delete', 'head', 'options', 'trace'];

    /**
     * @param  array<int, OperationFilter>  $include
     * @param  array<int, OperationFilter>  $exclude
     * @param  string  $unmatched  'exclude' (default) or 'include'.
     */
    public function __construct(
        private RouteResolver $routeResolver,
        private array $include = [],
        private array $exclude = [],
        private string $unmatched = 'exclude',
        private ?LoggerInterface $logger = null,
    ) {}

    /**
     * Filter the OpenAPI document in place and return a report of the outcome.
     */
    public function select(OA\OpenApi $openapi): SelectionReport
    {
        $report = new SelectionReport();

        if ($openapi->paths === Generator::UNDEFINED || ! is_array($openapi->paths)) {
            return $report;
        }

        foreach ($openapi->paths as $pathItem) {
            $this->selectPathItem($pathItem, $report);
        }

        $openapi->paths = array_values(array_filter(
            $openapi->paths,
            fn (OA\PathItem $pathItem): bool => $this->hasOperations($pathItem),
        ));

        $this->logSummary($report);

        return $report;
    }

    private function selectPathItem(OA\PathItem $pathItem, SelectionReport $report): void
    {
        foreach (self::OPERATION_METHODS as $method) {
            $operation = $pathItem->{$method};

            if ($operation === Generator::UNDEFINED) {
                continue;
            }

            $context = $this->buildContext($operation, $pathItem, $method);
            $entry = $this->entryFor($context);
            $isUnmatched = $context->route === null;

            if ($isUnmatched) {
                $report->unmatched[] = $entry;
            }

            if ($this->keeps($context, $isUnmatched)) {
                $report->kept[] = $entry;
            } else {
                $pathItem->{$method} = Generator::UNDEFINED;
                $report->dropped[] = $entry;
            }
        }
    }

    private function keeps(OperationContext $context, bool $isUnmatched): bool
    {
        if ($isUnmatched) {
            return $this->unmatched === 'include';
        }

        return $this->includedByFilters($context);
    }

    private function includedByFilters(OperationContext $context): bool
    {
        $included = $this->include === [] ? true : $this->anyMatches($this->include, $context);

        if (! $included) {
            return false;
        }

        return ! $this->anyMatches($this->exclude, $context);
    }

    /**
     * @param  array<int, OperationFilter>  $filters
     */
    private function anyMatches(array $filters, OperationContext $context): bool
    {
        foreach ($filters as $filter) {
            if ($filter->matches($context)) {
                return true;
            }
        }

        return false;
    }

    private function buildContext(OA\Operation $operation, OA\PathItem $pathItem, string $method): OperationContext
    {
        $path = $pathItem->path === Generator::UNDEFINED ? '' : (string) $pathItem->path;
        $action = $this->actionFor($operation);

        $resolved = $this->routeResolver->resolve(new ResolvableOperation(
            httpMethod: $method,
            path: $path,
            action: $action,
        ));

        return new OperationContext(
            operation: $operation,
            pathItem: $pathItem,
            httpMethod: $method,
            path: $path,
            route: $resolved,
        );
    }

    /**
     * Derive the controller action ("Namespace\Class@method") from the operation's
     * parse context. Returns null for operations with no class/method context
     * (e.g. closure or hand-authored path-only annotations).
     */
    private function actionFor(OA\Operation $operation): ?string
    {
        $context = $operation->_context ?? null;

        if (! $context instanceof Context) {
            return null;
        }

        $class = $context->class;
        $method = $context->method;
        $namespace = $context->namespace;

        if (! is_string($class) || $class === '' || ! is_string($method) || $method === '') {
            return null;
        }

        $fqcn = (is_string($namespace) && $namespace !== '') ? $namespace . '\\' . $class : $class;

        return ltrim($fqcn, '\\') . '@' . $method;
    }

    private function hasOperations(OA\PathItem $pathItem): bool
    {
        foreach (self::OPERATION_METHODS as $method) {
            if ($pathItem->{$method} !== Generator::UNDEFINED) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{method: string, path: string, action: ?string, tags: array<int, string>}
     */
    private function entryFor(OperationContext $context): array
    {
        $tags = $context->operation->tags;

        return [
            'method' => $context->httpMethod,
            'path' => $context->path,
            'action' => $context->route?->action() ?? $this->actionFor($context->operation),
            'tags' => ($tags === Generator::UNDEFINED || ! is_array($tags)) ? [] : array_values($tags),
        ];
    }

    private function logSummary(SelectionReport $report): void
    {
        if ($this->logger === null) {
            return;
        }

        $this->logger->info('[openapi-docs] operation selection: ' . $report->summaryLine());

        foreach ($report->unmatched as $entry) {
            $this->logger->warning(sprintf(
                '[openapi-docs] unmatched operation (no route resolved): %s %s',
                strtoupper($entry['method']),
                $entry['path'],
            ));
        }
    }
}
