<?php

namespace Langsys\OpenApiDocsGenerator\Data;

/**
 * Structured outcome of an operation-selection pass.
 *
 * The motivation for filtered sets is annotation-vs-route drift, so selection is
 * never silent: this report exposes what was kept, dropped, and — importantly —
 * which operations could not be tied to a route ({@see $unmatched}). Consumers
 * can persist it (e.g. to gate CI on drift); the selector also logs a summary.
 *
 * An operation may appear in both {@see $unmatched} and its final disposition
 * ({@see $kept} or {@see $dropped}): "unmatched" records that no route was
 * resolved, while kept/dropped record what the configured policy did with it.
 *
 * @phpstan-type OperationEntry array{method: string, path: string, action: ?string, tags: array<int, string>}
 */
class SelectionReport
{
    /**
     * @param  array<int, array{method: string, path: string, action: ?string, tags: array<int, string>}>  $kept
     * @param  array<int, array{method: string, path: string, action: ?string, tags: array<int, string>}>  $dropped
     * @param  array<int, array{method: string, path: string, action: ?string, tags: array<int, string>}>  $unmatched
     */
    public function __construct(
        public array $kept = [],
        public array $dropped = [],
        public array $unmatched = [],
    ) {}

    /**
     * @return array{kept: int, dropped: int, unmatched: int}
     */
    public function counts(): array
    {
        return [
            'kept' => count($this->kept),
            'dropped' => count($this->dropped),
            'unmatched' => count($this->unmatched),
        ];
    }

    /**
     * One-line human summary, e.g. "kept 16, dropped 133, unmatched 2".
     */
    public function summaryLine(): string
    {
        $counts = $this->counts();

        return "kept {$counts['kept']}, dropped {$counts['dropped']}, unmatched {$counts['unmatched']}";
    }
}
