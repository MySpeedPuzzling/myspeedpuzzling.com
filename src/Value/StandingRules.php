<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use InvalidArgumentException;

/**
 * The settings of a `sum` standing (A-round-formats.md §3.3): an unfinished round scores the time limit plus
 * `penaltySecondsPerPiece` per missing piece; `order` orders the complete entrants; `missing` decides what a round
 * without a score does. `penaliseFinishedMissing` (competition toolkit T6, default off): the same penalty also for the
 * pieces a finished puzzle lacked (a piece missing from the factory) - off, they are shown and counted, never timed.
 */
final readonly class StandingRules
{
    public const int PENALTY_MAX = 600;

    public function __construct(
        public int $penaltySecondsPerPiece = 0,
        public StandingOrder $order = StandingOrder::FinishedRoundsFirst,
        public StandingMissing $missing = StandingMissing::Incomplete,
        public bool $penaliseFinishedMissing = false,
    ) {
        if ($penaltySecondsPerPiece < 0 || $penaltySecondsPerPiece > self::PENALTY_MAX) {
            throw new InvalidArgumentException(sprintf('The penalty per missing piece is 0..%d seconds.', self::PENALTY_MAX));
        }
    }

    /**
     * @return array{penaltySecondsPerPiece: int, order: string, missing: string, penaliseFinishedMissing: bool}
     */
    public function toArray(): array
    {
        return [
            'penaltySecondsPerPiece' => $this->penaltySecondsPerPiece,
            'order' => $this->order->value,
            'missing' => $this->missing->value,
            'penaliseFinishedMissing' => $this->penaliseFinishedMissing,
        ];
    }
}
