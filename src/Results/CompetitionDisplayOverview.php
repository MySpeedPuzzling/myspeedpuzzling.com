<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\DisplayFollowMode;
use SpeedPuzzling\Web\Value\DisplayKind;

/**
 * One display in the organiser's list (the toolkit hub card and the displays page) - never shown to anybody else: the
 * token is the display's capability.
 */
readonly final class CompetitionDisplayOverview
{
    /**
     * @param null|list<string> $followRoundIds
     */
    public function __construct(
        public string $id,
        public string $competitionId,
        public DisplayKind $kind,
        public string $name,
        public string $token,
        public string $locale,
        public DisplayFollowMode $followMode,
        public null|string $pinnedRoundId,
        public null|array $followRoundIds,
        public int $designVersion,
        public DateTimeImmutable $updatedAt,
    ) {
    }

    /**
     * Pinned to a round that was deleted meanwhile = shows the current round.
     */
    public function followsCurrentRound(): bool
    {
        return $this->followMode === DisplayFollowMode::Current || $this->pinnedRoundId === null;
    }
}
