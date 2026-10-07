<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class DeleteCompetition
{
    public function __construct(
        public string $competitionId,
        // The internal API deletes only what nobody has a result in (CompetitionHasResults); the web form asks its own way
        public bool $refuseWhenItHasResults = false,
    ) {
    }
}
