<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * The seller's whole choice for one event (the picker): exactly these listings are brought, the rest of the
 * seller's rows for that event are removed.
 */
readonly final class ChooseEventOffers
{
    /**
     * @param list<string> $listItemIds
     */
    public function __construct(
        public string $playerId,
        public string $competitionId,
        public array $listItemIds,
    ) {
    }
}
