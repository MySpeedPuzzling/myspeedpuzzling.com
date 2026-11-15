<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * Where a button or a line of the import's guided flow leads (Services\DataImport\ImportFlow): its text and an
 * existing route of the import with that route's parameters.
 */
readonly final class ImportFlowAction
{
    /**
     * @param array<string, string> $parameters of the route
     */
    public function __construct(
        public ImportFlowText $label,
        public string $route,
        public array $parameters,
        /** The step the action belongs to (ImportFlow::FILE … ImportFlow::ADD) */
        public int $step,
    ) {
    }
}
