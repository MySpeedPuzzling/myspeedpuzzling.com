<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Insights;

use DateTimeImmutable;
use SpeedPuzzling\Web\Query\GetPlayerRatingSnapshots;
use SpeedPuzzling\Web\Results\Insights\RatingDay;
use SpeedPuzzling\Web\Results\Insights\RatingOverTimeResult;
use SpeedPuzzling\Web\Results\Insights\RatingOverTimeSeries;
use SpeedPuzzling\Web\Services\Insights\Model\RatingModel;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PuzzleIntelligenceRecalculator;

/**
 * "Your rating over time" (docs/features/insights/computation.md §4c.6) from player_rating_snapshot as it is: the
 * daily values, the change against the previous stored day, the change over 30 / 90 days and the biggest rise and
 * drop. One statement.
 */
readonly final class RatingOverTime
{
    public function __construct(
        private GetPlayerRatingSnapshots $getPlayerRatingSnapshots,
    ) {
    }

    public function build(string $playerId, null|DateTimeImmutable $from = null, null|DateTimeImmutable $to = null): RatingOverTimeResult
    {
        return self::fromRows($this->getPlayerRatingSnapshots->forPlayer($playerId, PuzzleIntelligenceRecalculator::RATING_PIECES_COUNTS, $from, $to));
    }

    /**
     * @param list<array{pieces_count: int, date: DateTimeImmutable, rating: float, place: null|int}> $rows
     */
    public static function fromRows(array $rows): RatingOverTimeResult
    {
        /** @var array<int, non-empty-list<RatingDay>> $bySize */
        $bySize = [];
        /** @var array<int, array{rating: float, date: DateTimeImmutable}> $previous */
        $previous = [];

        foreach ($rows as $row) {
            $piecesCount = $row['pieces_count'];
            $rating = $row['rating'] * RatingModel::DISPLAY_SCALE;
            $before = $previous[$piecesCount] ?? null;

            $bySize[$piecesCount][] = new RatingDay(
                date: $row['date'],
                rating: $rating,
                place: $row['place'],
                change: $before !== null ? $rating - $before['rating'] : null,
                daysSincePrevious: $before !== null ? (int) $before['date']->diff($row['date'])->days : null,
            );
            $previous[$piecesCount] = ['rating' => $rating, 'date' => $row['date']];
        }

        $series = [];

        foreach ($bySize as $piecesCount => $days) {
            $rise = null;
            $drop = null;

            foreach ($days as $day) {
                if ($day->change === null) {
                    continue;
                }

                if ($day->change > 0.0 && ($rise === null || $day->change > (float) $rise->change)) {
                    $rise = $day;
                }

                if ($day->change < 0.0 && ($drop === null || $day->change < (float) $drop->change)) {
                    $drop = $day;
                }
            }

            $series[] = new RatingOverTimeSeries(
                piecesCount: $piecesCount,
                days: $days,
                changeOver30Days: self::changeOver($days, 30),
                changeOver90Days: self::changeOver($days, 90),
                biggestRise: $rise,
                biggestDrop: $drop,
            );
        }

        return new RatingOverTimeResult($series);
    }

    /**
     * @param non-empty-list<RatingDay> $days
     */
    private static function changeOver(array $days, int $windowDays): null|float
    {
        $latest = $days[count($days) - 1];
        $since = $latest->date->modify("-{$windowDays} days");
        $base = null;

        foreach ($days as $day) {
            if ($day->date <= $since) {
                $base = $day;
            }
        }

        return $base !== null ? $latest->rating - $base->rating : null;
    }
}
