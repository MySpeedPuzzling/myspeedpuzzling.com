<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Insights;

use SpeedPuzzling\Web\Results\Insights\LadderPath;
use SpeedPuzzling\Web\Results\Insights\RatingEntryView;
use SpeedPuzzling\Web\Results\Insights\RatingMove;
use SpeedPuzzling\Web\Results\Insights\RatingOpportunitiesResult;
use SpeedPuzzling\Web\Services\Insights\Model\RatingModel;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\MspRatingCalculator;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\MspRatingFormula;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PuzzleIntelligenceRecalculator;
use SpeedPuzzling\Web\Value\RatingPortfolio;
use SpeedPuzzling\Web\Value\RatingStanding;

/**
 * "Where can I gain MSP rating?" (docs/features/insights/computation.md §4c.2): moves (first tries from the shelf,
 * re-solves, defending entries about to leave the window) each worth its what-if gain, the best 5 / 10 together,
 * points per hour, puzzles waiting to qualify, the portfolio strips - and the path onto the ladder only while it is
 * within reach. Members only (the page gates it); self-only.
 */
readonly final class RatingOpportunities
{
    public const int MOVES_SHOWN = 30;

    public function __construct(
        private RatingModelLoader $ratingModelLoader,
    ) {
    }

    public function build(string $playerId, int $piecesCount = PuzzleIntelligenceRecalculator::RATING_PIECES_COUNTS[0]): RatingOpportunitiesResult
    {
        return self::fromModel($this->ratingModelLoader->load($playerId, $piecesCount));
    }

    public static function fromModel(RatingModel $model): RatingOpportunitiesResult
    {
        $standing = $model->standing();

        if ($standing === RatingStanding::Private || $standing === RatingStanding::NoEntries) {
            return RatingOpportunitiesResult::empty($model->piecesCount, $standing);
        }

        $rating = $model->rating();
        $onLadder = $standing === RatingStanding::OnLadder;
        $byGainPerHour = $model->moves;
        usort($byGainPerHour, static fn (RatingMove $a, RatingMove $b): int => $b->gainPerHour <=> $a->gainPerHour ?: strcmp($a->puzzle->puzzleId, $b->puzzle->puzzleId));
        $afterBestTen = $model->ratingAfterBest(10);

        [$firstAttemptEntries, $bestTimeEntries, $leaving] = self::entries($model);

        return new RatingOpportunitiesResult(
            piecesCount: $model->piecesCount,
            standing: $standing,
            rating: $rating * RatingModel::DISPLAY_SCALE,
            place: $onLadder ? $model->place($rating) : null,
            ladderSize: count($model->ladder) + ($onLadder ? 1 : 0),
            firstAttemptAverage: $model->firstAttempts->mean() * RatingModel::DISPLAY_SCALE,
            bestTimeAverage: $model->bestTimes->mean() * RatingModel::DISPLAY_SCALE,
            entriesLeavingWithin60Days: $leaving,
            movesCount: count($model->moves),
            moves: array_slice($model->moves, 0, self::MOVES_SHOWN),
            movesByGainPerHour: array_slice($byGainPerHour, 0, self::MOVES_SHOWN),
            pending: $model->pending,
            bestFiveGain: ($model->ratingAfterBest(5) - $rating) * RatingModel::DISPLAY_SCALE,
            bestTenGain: ($afterBestTen - $rating) * RatingModel::DISPLAY_SCALE,
            placeAfterBestTen: $onLadder ? $model->place($afterBestTen) : null,
            ladderPath: $standing === RatingStanding::NearLadder ? new LadderPath(
                firstTries: $model->firstAttempts->count(),
                firstTriesNeeded: MspRatingCalculator::MINIMUM_FIRST_ATTEMPTS,
                results: $model->resultsInWindow,
                resultsNeeded: MspRatingCalculator::MINIMUM_TOTAL_SOLVES,
                newResultsNeeded: $model->newResultsNeeded(),
                ratingWhenOnLadder: $rating * RatingModel::DISPLAY_SCALE,
                placeWhenOnLadder: $model->place($rating),
            ) : null,
            firstAttemptEntries: $firstAttemptEntries,
            bestTimeEntries: $bestTimeEntries,
        );
    }

    /**
     * @return array{0: list<RatingEntryView>, 1: list<RatingEntryView>, 2: int}
     */
    private static function entries(RatingModel $model): array
    {
        $leavesBefore = MspRatingFormula::windowStart($model->now->modify('+' . RatingModel::DEFEND_WITHIN_DAYS . ' days'));
        $views = [RatingPortfolio::FirstAttempt->value => [], RatingPortfolio::BestTime->value => []];
        $leaving = 0;

        foreach ($model->solved as $puzzle) {
            foreach ([[RatingPortfolio::FirstAttempt, $puzzle->firstAttemptEntry], [RatingPortfolio::BestTime, $puzzle->bestTimeEntry]] as [$portfolio, $entry]) {
                if ($entry === null) {
                    continue;
                }

                if ($entry->solvedAt < $leavesBefore) {
                    $leaving++;
                }

                $views[$portfolio->value][] = new RatingEntryView(
                    portfolio: $portfolio,
                    puzzle: $puzzle->puzzle,
                    seconds: $entry->seconds,
                    solvedAt: $entry->solvedAt,
                    points: $entry->points * RatingModel::DISPLAY_SCALE,
                    counts: false,
                    leavesAt: $entry->solvedAt->modify('+' . MspRatingFormula::WINDOW_MONTHS . ' months'),
                );
            }
        }

        $lists = [];

        foreach ($views as $portfolio => $list) {
            usort($list, static fn (RatingEntryView $a, RatingEntryView $b): int => $b->points <=> $a->points);
            $counted = [];

            foreach ($list as $position => $view) {
                $counted[] = new RatingEntryView($view->portfolio, $view->puzzle, $view->seconds, $view->solvedAt, $view->points, $position < MspRatingFormula::CAP, $view->leavesAt);
            }

            $lists[$portfolio] = $counted;
        }

        return [$lists[RatingPortfolio::FirstAttempt->value], $lists[RatingPortfolio::BestTime->value], $leaving];
    }
}
