<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use SpeedPuzzling\Web\Results\PlayerPuzzleSolves;

/**
 * The token owner's own history on a puzzle, always split by discipline -
 * solo, duo and team are different disciplines and are never merged (the same
 * split as /me/statistics). Not members-only; an endpoint returns null for this
 * object only when the token has no player behind it or no results:read / PAT.
 */
final class PlayerSolvesResponse
{
    public function __construct(
        public SolvesGroupResponse $solo,
        public SolvesGroupResponse $duo,
        public SolvesGroupResponse $team,
    ) {
    }

    public static function fromResult(PlayerPuzzleSolves $solves): self
    {
        return new self(
            solo: SolvesGroupResponse::fromResult($solves->solo),
            duo: SolvesGroupResponse::fromResult($solves->duo),
            team: SolvesGroupResponse::fromResult($solves->team),
        );
    }
}
