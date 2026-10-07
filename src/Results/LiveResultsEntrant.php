<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * A participant read from a name tag's QR (GetLiveResultsEntrant): who it is and which rounds of the event they are
 * entered in - solo rounds themselves, pair/team rounds through their pair/team.
 */
readonly final class LiveResultsEntrant
{
    public function __construct(
        public string $participantId,
        public string $competitionId,
        public string $name,
        /** @var list<string> */
        public array $roundIds,
    ) {
    }
}
