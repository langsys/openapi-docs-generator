<?php

namespace Langsys\OpenApiDocsGenerator\Tests\Data;

use Langsys\OpenApiDocsGenerator\Generators\Attributes\Example;
use Spatie\LaravelData\Data;

/**
 * Array #[Example]s: on an object (a string-keyed map), on lists of strings, of numbers
 * and of objects, an empty one, and a scalar one on a list for contrast.
 */
class ArrayExampleData extends Data
{
    public function __construct(
        /** @var array<string, mixed> */
        #[Example(['browser' => 'Safari 18.1', 'os' => 'macOS 15.1'])]
        public array $device_info,
        #[Example(['203.0.113.0/24', '198.51.100.7'])]
        public array $ip_allowlist,
        #[Example([1.5, 2.25])]
        public array $weights,
        #[Example([['id' => 'a1'], ['id' => 'b2']])]
        public array $entries,
        #[Example([])]
        public array $tags,
        #[Example('203.0.113.0/24')]
        public array $ranges,
    ) {}
}
