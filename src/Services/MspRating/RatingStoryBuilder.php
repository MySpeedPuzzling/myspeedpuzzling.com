<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\MspRating;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Query\GetPlayerRatingRanking;
use SpeedPuzzling\Web\Query\GetPlayerRatingSnapshots;
use SpeedPuzzling\Web\Results\PlayerProfile;
use SpeedPuzzling\Web\Results\RatingHistory;
use SpeedPuzzling\Web\Results\RatingMove;
use SpeedPuzzling\Web\Results\RatingStory;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PuzzleIntelligenceRecalculator;
use SpeedPuzzling\Web\Value\MspRatingTimeline;

/**
 * The rating card and the profile's rating block (docs/features/msp-rating-history.md) for a player on the ladder -
 * the profile row (GetPlayerProfile::byId()) already holds the place and the place on the compared day, so this adds two
 * statements: the rating + ladder size (allForPlayer()) and the stored days.
 */
readonly final class RatingStoryBuilder
{
    public const int PIECES_COUNT = PuzzleIntelligenceRecalculator::RATING_PIECES_COUNTS[0];

    public function __construct(
        private GetPlayerRatingRanking $getPlayerRatingRanking,
        private GetPlayerRatingSnapshots $getPlayerRatingSnapshots,
        private RatingHistoryChartFactory $chartFactory,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Null when the player is not on the ladder (private, opted out, not rated) - the history is never shown then
     */
    public function forPlayer(PlayerProfile $player): null|RatingStory
    {
        if ($player->mspRatingRank === null) {
            return null;
        }

        $today = $this->getPlayerRatingRanking->allForPlayer($player->playerId)[self::PIECES_COUNT] ?? null;

        if ($today === null) {
            return null;
        }

        $now = $this->clock->now();
        $history = RatingHistory::fromSnapshots($this->getPlayerRatingSnapshots->forPlayer($player->playerId, [self::PIECES_COUNT]));
        $since = MspRatingTimeline::moveReferenceDay($now, GetPlayerRatingRanking::MOVE_DAYS);
        $move = null;

        if ($since !== null) {
            $ratingBefore = $history->ratingOn($since);

            $move = RatingMove::between(
                rank: $player->mspRatingRank,
                rating: $today['elo_rating'],
                previousRank: $player->mspRatingRankBefore,
                previousRating: $ratingBefore !== null ? $ratingBefore / 1000 : null,
                referenceDay: $since,
                now: $now,
            );
        }

        $charts = $this->chartFactory->charts($history);
        $showsFormulaChange = false;

        if ($history->days !== []) {
            foreach (MspRatingTimeline::markersBetween($history->days[0]['date'], $history->days[count($history->days) - 1]['date']) as $marker) {
                $showsFormulaChange = $showsFormulaChange || $marker['kind'] === 'formula';
            }
        }

        return new RatingStory(
            rank: $player->mspRatingRank,
            total: $today['total'],
            rating: (int) round($today['elo_rating'] * 1000),
            move: $move,
            history: $history,
            ratingChart: $charts['rating'] ?? null,
            placeChart: $charts['place'] ?? null,
            showsFormulaChange: $showsFormulaChange,
        );
    }
}
