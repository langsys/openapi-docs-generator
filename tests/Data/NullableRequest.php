<?php

namespace Langsys\OpenApiDocsGenerator\Tests\Data;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * A nullable property of every shape, and names no Faker function or hint matches, so
 * their examples come from the fallback. `name` is the non-nullable control.
 */
class NullableRequest extends Data
{
    public function __construct(
        public string $name,
        public ?string $code_verifier,
        public ?int $retry_count,
        public ?float $ratio,
        public ?bool $gate,
        public ?array $ip_allowlist,
        /** @var array<string, mixed> */
        public ?array $device_info,
        public string|array|null $order_by,
        public string|Optional|null $handoff,
        public ?ExampleData $example,
        /** @var ExampleData[] */
        public ?array $examples,
        public mixed $payload,
    ) {}
}
