<?php

namespace Langsys\OpenApiDocsGenerator\Tests\ErrorOperationFixtures;

use Langsys\OpenApiDocsGenerator\Contracts\ValidationScenarioResolver;
use Langsys\OpenApiDocsGenerator\Data\OperationContext;
use Langsys\OpenApiDocsGenerator\Data\ValidationScenario;
use ReflectionMethod;

/**
 * Stands in for an app resolver that walks a request DTO's rules and collects the
 * typed field errors it can raise. Returns a fixed list so tests read clearly.
 */
class FixedScenarioResolver implements ValidationScenarioResolver
{
    /** @var array<int, ValidationScenario> */
    private array $scenarios;

    public function __construct(ValidationScenario ...$scenarios)
    {
        $this->scenarios = $scenarios === [] ? self::defaults() : array_values($scenarios);
    }

    /** @return array<int, ValidationScenario> */
    public static function defaults(): array
    {
        return [
            // A nested, dotted field.
            new ValidationScenario('credit_card.cc_number', 'already_taken', 'This credit card has already been added.'),
            // One code, two rules, two sentences: both belong in the docs.
            new ValidationScenario('locale', 'invalid_option', 'The locale is not valid.'),
            new ValidationScenario('locale', 'invalid_option', 'The locale is not a target locale of this project.'),
            // A rule that judges something named in the route, so it belongs to no field.
            new ValidationScenario(null, 'expired', 'This invitation has expired.'),
        ];
    }

    public function scenariosFor(OperationContext $context, ?ReflectionMethod $action): array
    {
        return $this->scenarios;
    }
}
