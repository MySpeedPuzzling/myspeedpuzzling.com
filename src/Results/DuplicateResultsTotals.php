<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class DuplicateResultsTotals
{
    public function __construct(
        public int $openCases,
        public int $playersAffected,
        public int $resolvedLast30Days,
        public int $confirmedReal,
        public int $autoRemoved,
        public int $autoRemovalsUndone,
    ) {
    }
}
