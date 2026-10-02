<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Value\DuplicatePreventionKind;
use SpeedPuzzling\Web\Value\SolvingTimeSource;

readonly final class RecordDuplicatePrevention
{
    public function __construct(
        public string $playerId,
        public DuplicatePreventionKind $kind,
        public string $timeId,
        public string $puzzleId,
        public SolvingTimeSource $via,
    ) {
    }
}
