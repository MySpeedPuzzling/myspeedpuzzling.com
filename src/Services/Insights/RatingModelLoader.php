<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Insights;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Query\GetInsightsRatingInputs;
use SpeedPuzzling\Web\Query\GetInsightsShelf;
use SpeedPuzzling\Web\Query\GetPlayerPredictions;
use SpeedPuzzling\Web\Results\Insights\ShelfPuzzle;
use SpeedPuzzling\Web\Services\Insights\Model\RatingModel;

/**
 * Reads what a rating what-if needs and builds the RatingModel: the solved puzzles with their entries, the shelf, the
 * time lists of those puzzles, the predictions (GetPlayerPredictions - list scale, never one per puzzle) and the
 * ladder. At most eight statements (one for a private player), whatever the player's size.
 */
readonly final class RatingModelLoader
{
    public function __construct(
        private GetInsightsRatingInputs $getInsightsRatingInputs,
        private GetInsightsShelf $getInsightsShelf,
        private GetPlayerPredictions $getPlayerPredictions,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param null|list<ShelfPuzzle> $shelf the unsolved shelf when the caller has it already
     */
    public function load(string $playerId, int $piecesCount, null|array $shelf = null): RatingModel
    {
        $now = $this->clock->now();
        $solved = $this->getInsightsRatingInputs->solvedPuzzles($playerId, $piecesCount);

        if ($solved['private']) {
            return RatingModel::build($now, $piecesCount, true, $solved['optedOut'], $solved['puzzles'], [], [], [], []);
        }

        $shelf ??= $this->getInsightsShelf->unsolved($playerId);
        $timeLists = $this->getInsightsRatingInputs->ratingTimes(
            RatingModel::timeListIds($solved['puzzles'], $shelf, $piecesCount),
            RatingModel::firstAttemptListIds($solved['puzzles'], $shelf, $piecesCount),
        );

        $predictionIds = array_keys($timeLists);
        $predictions = $predictionIds !== []
            ? $this->getPlayerPredictions->forPuzzles($playerId, array_map(strval(...), $predictionIds))
            : [];

        return RatingModel::build(
            now: $now,
            piecesCount: $piecesCount,
            private: false,
            optedOut: $solved['optedOut'],
            solved: $solved['puzzles'],
            shelf: $shelf,
            timeLists: $timeLists,
            predictions: $predictions,
            // No place for a player opted out of rankings - no ladder to read
            ladder: $solved['optedOut'] ? [] : $this->getInsightsRatingInputs->ladderRatings($piecesCount, $playerId),
        );
    }
}
