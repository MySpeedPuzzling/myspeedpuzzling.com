<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use DateTimeImmutable;

/**
 * The pair/team model on a time split (Group\GroupPredictionEvaluator) - read only:
 * `myspeedpuzzling:evaluate-group-predictions --fit-before=`.
 */
readonly final class EvaluateGroupPredictions
{
    /**
     * @param list<string> $off parts switched off (Value\GroupModelParameters::PARTS)
     */
    public function __construct(
        public DateTimeImmutable $fitBefore,
        public null|DateTimeImmutable $until = null,
        public array $off = [],
    ) {
    }
}
