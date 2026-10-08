<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\ExpectedTimeSource;
use SpeedPuzzling\Web\Value\SuspiciousTimeReason;
use SpeedPuzzling\Web\Value\SuspiciousTimeReasonCode;
use SpeedPuzzling\Web\Value\SuspiciousTimeTier;

/**
 * One pending case inside a puzzle card of the time verification queue - a line of its overview; the case itself is
 * decided in its own card right below.
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
     * How far off: expected ÷ entered for a fast time, entered ÷ expected for a slow one - always ≥ 1 when raised. A
     * time judged by the community's slow floor (a pair/team, a new player) is compared with the community median.
     */
    public function ratio(): null|float
    {
        $expected = $this->comparedWithSeconds();

        if ($this->seconds === null || $this->seconds <= 0 || $expected === null || $expected <= 0) {
            return null;
        }

        return max($expected / $this->seconds, $this->seconds / $expected);
    }

    /**
     * The player's own expectation, or for a time below the community's slow floor what most puzzlers take.
     */
    public function comparedWithSeconds(): null|int
    {
        if ($this->expectedSeconds !== null) {
            return $this->expectedSeconds;
        }

        $median = $this->belowSlowFloor()?->params['median'] ?? null;

        return is_int($median) ? $median : null;
    }

    public function isComparedWithCommunity(): bool
    {
        return $this->expectedSeconds === null && $this->comparedWithSeconds() !== null;
    }

    private function belowSlowFloor(): null|SuspiciousTimeReason
    {
        foreach ($this->reasons as $reason) {
            if ($reason->code === SuspiciousTimeReasonCode::BelowSlowFloor) {
                return $reason;
            }
        }

        return null;
    }

    public function trigger(): null|SuspiciousTimeReason
    {
        return $this->reasons[0] ?? null;
    }
}
