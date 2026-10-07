<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\ExpectedTimeSource;
use SpeedPuzzling\Web\Value\SuspiciousTimeReason;
use SpeedPuzzling\Web\Value\SuspiciousTimeTier;

/**
 * One pending case inside a puzzle card of the time verification queue - a line, not a whole card: the question
 * there is the puzzle's piece count.
 */
readonly final class SuspiciousTimePuzzleCardCase
{
    /**
     * @param list<SuspiciousTimeReason> $reasons
     */
    public function __construct(
        public string $caseId,
        public string $timeId,
        public string $playerId,
        public null|string $playerName,
        public string $playerCode,
        public bool $playerPrivate,
        public null|int $seconds,
        public int $puzzlersCount,
        public null|int $expectedSeconds,
        public null|ExpectedTimeSource $expectedSource,
        public null|SuspiciousTimeTier $tier,
        public array $reasons,
    ) {
    }

    /**
     * How far off: expected ÷ entered for a fast time, entered ÷ expected for a slow one - always ≥ 1 when raised.
     */
    public function ratio(): null|float
    {
        if ($this->seconds === null || $this->seconds <= 0 || $this->expectedSeconds === null || $this->expectedSeconds <= 0) {
            return null;
        }

        return max($this->expectedSeconds / $this->seconds, $this->seconds / $this->expectedSeconds);
    }

    public function trigger(): null|SuspiciousTimeReason
    {
        return $this->reasons[0] ?? null;
    }
}
