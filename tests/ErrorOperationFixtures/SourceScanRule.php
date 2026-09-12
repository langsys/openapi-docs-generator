<?php

namespace Langsys\OpenApiDocsGenerator\Tests\ErrorOperationFixtures;

use Langsys\OpenApiDocsGenerator\Contracts\ImpliedErrorRule;
use Langsys\OpenApiDocsGenerator\Data\OperationContext;
use ReflectionMethod;

/**
 * An app-owned rule: implies errors when an action's own source contains a needle,
 * e.g. an in-body authorization call that nothing on the route reveals.
 *
 * This is the shape an app writes when its convention lives in the method body.
 * The library deliberately ships no rule like this — the heuristic and its upkeep
 * belong to the app whose idiom it encodes.
 */
class SourceScanRule implements ImpliedErrorRule
{
    /** @param array<string, array<int, class-string>> $needles needle => error classes */
    public function __construct(private array $needles)
    {
    }

    public function errorsFor(OperationContext $context, ?ReflectionMethod $action): array
    {
        if ($action === null) {
            return [];
        }

        $source = $this->bodyOf($action);

        if ($source === null) {
            return [];
        }

        $classes = [];

        foreach ($this->needles as $needle => $errors) {
            if (str_contains($source, $needle)) {
                $classes = array_merge($classes, $errors);
            }
        }

        return $classes;
    }

    private function bodyOf(ReflectionMethod $action): ?string
    {
        $file = $action->getFileName();
        $start = $action->getStartLine();
        $end = $action->getEndLine();

        if ($file === false || $start === false || $end === false) {
            return null;
        }

        $lines = @file($file);

        if ($lines === false) {
            return null;
        }

        return implode('', array_slice($lines, $start - 1, $end - $start + 1));
    }
}
