<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use SpeedPuzzling\Web\Results\PlayerSkillResult;

/**
 * One row of the "Player insights" skill block on the profile page: the
 * player's skill tier for a piece count (Puzzle Insights - members-only,
 * exactly as on the website: the *token owner* must be a member to see it,
 * whoever the profile belongs to).
 *
 * An endpoint returns null for the whole list when the token is not entitled
 * to it (not a member, machine token, the player opted out of rankings, or
 * the profile is private and masked); an empty list means "no tier yet".
 */
final class PlayerSkillResponse
{
    public function __construct(
        public int $piecesCount,
        public string $tier,
        public float $percentile,
        public string $confidence,
        public int $qualifyingPuzzlesCount,
    ) {
    }

    public static function fromResult(PlayerSkillResult $skill): self
    {
        return new self(
            piecesCount: $skill->piecesCount,
            tier: $skill->skillTier->toApiValue(),
            percentile: $skill->skillPercentile,
            confidence: $skill->confidence->value,
            qualifyingPuzzlesCount: $skill->qualifyingPuzzlesCount,
        );
    }
}
