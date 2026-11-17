<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\CompetitionParticipantsLock;

/**
 * "Use these tables in later rounds of this category" (competition toolkit W3): every target round gets the source
 * round's tables the way "Same tables as round X" proposes them (SameTablesCopy) - the same person / exactly the same
 * people keep their table, the rest keep theirs or go after the highest. All or nothing over every target: a target
 * whose entrants or number of tables changed since the organiser saw the confirm (`targets` = round id => the hash the
 * confirm showed, SameTablesCopy::hash()) refuses the whole copy (TableNumbersCopyRefused, `changed_meanwhile`).
 * The handler answers round id => refs of the entries whose number changed.
 */
readonly final class CopyRoundTableNumbers implements SerializedByLock
{
    public function __construct(
        public string $competitionId,
        public string $sourceRoundId,
        /** @var array<string, string> target round id => SameTablesCopy::hash() the confirm showed */
        public array $targets,
    ) {
    }

    public function lockKey(): string
    {
        return CompetitionParticipantsLock::key($this->competitionId);
    }
}
