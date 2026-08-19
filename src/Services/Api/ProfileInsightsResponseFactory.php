<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Api;

use SpeedPuzzling\Web\Api\V1\PlayerRatingResponse;
use SpeedPuzzling\Web\Api\V1\PlayerSkillResponse;
use SpeedPuzzling\Web\Query\GetBadges;
use SpeedPuzzling\Web\Query\GetPlayerRatingRanking;
use SpeedPuzzling\Web\Query\GetPlayerSkill;
use SpeedPuzzling\Web\Results\PlayerSkillResult;
use SpeedPuzzling\Web\Value\BadgeType;

/**
 * Builds the three profile-insight blocks the profile page shows - MSP Rating,
 * skill tiers, badges - for the public API (GET /me, GET /players/{id}), one
 * query each. Who may see which block is decided by the providers (the
 * website's gates: opt-out, private profile, token owner's membership); this
 * factory only builds what they ask for.
 */
final readonly class ProfileInsightsResponseFactory
{
    public function __construct(
        private GetPlayerRatingRanking $getPlayerRatingRanking,
        private GetPlayerSkill $getPlayerSkill,
        private GetBadges $getBadges,
    ) {
    }

    /**
     * @return list<PlayerRatingResponse> ascending by piece count, empty when not ranked yet
     */
    public function rating(string $playerId): array
    {
        $ratings = [];

        foreach ($this->getPlayerRatingRanking->allForPlayer($playerId) as $piecesCount => $rating) {
            $ratings[] = PlayerRatingResponse::fromRating($piecesCount, $rating);
        }

        return $ratings;
    }

    /**
     * @return list<PlayerSkillResponse> ascending by piece count, empty when no tier yet
     */
    public function skill(string $playerId): array
    {
        return array_map(
            static fn (PlayerSkillResult $skill): PlayerSkillResponse => PlayerSkillResponse::fromResult($skill),
            $this->getPlayerSkill->byPlayerId($playerId),
        );
    }

    /**
     * @return list<string> badge tokens (BadgeType values)
     */
    public function badges(string $playerId): array
    {
        return array_map(
            static fn (BadgeType $badge): string => $badge->value,
            $this->getBadges->forPlayer($playerId),
        );
    }
}
