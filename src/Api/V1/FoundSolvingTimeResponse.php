<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

final class FoundSolvingTimeResponse
{
    public function __construct(
        public string $timeId,
        // Changes when moderators merge the puzzle into another one - the time keeps its id
        public string $puzzleId,
        // solo / duo / team - the list of GET /me/results it is in (a group edit on the website moves it)
        public string $type,
        // false = a pair/team result somebody else tracked: the token owner may neither edit nor delete it
        public bool $trackedByMe,
    ) {
    }
}
