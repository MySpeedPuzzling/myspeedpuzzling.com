<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component\MspRating;

use SpeedPuzzling\Web\Results\RatingLadderSpotlight;
use SpeedPuzzling\Web\Services\MspRating\RatingLadderThisWeek;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PuzzleIntelligenceRecalculator;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * "This week on the ladder" above the MSP Rating ladder (docs/features/msp-rating-history.md): the biggest climber, the
 * best new entry and the most active. A plain Twig component outside the ladder's Live root, so the ladder's
 * filters never re-render it. Each tile opens the player's rating card. Nothing at all when no tile has a player.
 */
#[AsTwigComponent]
final class ThisWeek
{
    private const int PIECES_COUNT = PuzzleIntelligenceRecalculator::RATING_PIECES_COUNTS[0];

    /** @var null|list<RatingLadderSpotlight> */
    private null|array $tiles = null;

    public function __construct(
        readonly private RatingLadderThisWeek $ratingLadderThisWeek,
    ) {
    }

    /**
     * @return list<RatingLadderSpotlight>
     */
    public function getTiles(): array
    {
        return $this->tiles ??= $this->ratingLadderThisWeek->spotlights(self::PIECES_COUNT);
    }
}
