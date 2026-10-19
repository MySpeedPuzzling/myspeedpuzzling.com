<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Insights;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Results\Insights\ImprovementCurveResult;
use SpeedPuzzling\Web\Results\Insights\ImprovementCurveSize;
use SpeedPuzzling\Web\Results\Insights\InsightsImprovementFrame;
use SpeedPuzzling\Web\Results\Insights\InsightsRatingFrame;
use SpeedPuzzling\Web\Results\Insights\InsightsTopFrame;
use SpeedPuzzling\Web\Results\Insights\ShelfPicksResult;
use SpeedPuzzling\Web\Results\Insights\PairsTeamsSynergyResult;
use SpeedPuzzling\Web\Results\Insights\ResolveRadarResult;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PuzzleIntelligenceRecalculator;
use SpeedPuzzling\Web\Value\InsightsCacheKey;
use SpeedPuzzling\Web\Value\InsightsLazyPart;

/**
 * The lazy parts of the Insights page (docs/features/insights/page.md "Lazy frames"), each through its own part of the
 * page cache (InsightsCache: batch generation + the player's data + the viewer's blocks/allow list + the part's own
 * context). Call it for the signed-in player's own id only - like InsightsPageLoader.
 *
 * Statements on a cache miss, whatever the player's size (pinned by InsightsFramesBudgetTest): the cache key 1, plus
 * rating ≤ 8 (free) / ≤ 10 (member: + forecast), shelf picks 1 (free) / ≤ 6 (member), re-solve radar ≤ 4, pairs &
 * teams ≤ 4, improvement 1 (free) / 2 (member: + rating over time); the top row = rating + shelf under one key. Parts
 * resting on predictions (shelf, re-solve, top) cost nothing with predictions off. A hit costs only the key.
 *
 * A free player's part is built for the counts of its lock only (the template never renders more); the forecast and
 * the rating over time are not built for them at all.
 */
readonly final class InsightsFrames
{
    public function __construct(
        private InsightsCache $insightsCache,
        private RatingModelLoader $ratingModelLoader,
        private RatingForecast $ratingForecast,
        private ShelfPicks $shelfPicks,
        private ResolveRadar $resolveRadar,
        private PairsTeamsSynergy $pairsTeamsSynergy,
        private ImprovementCurve $improvementCurve,
        private RatingOverTime $ratingOverTime,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Sections 9 + 10 from one rating model (MSP rating = 500 pieces, its own 24-month window: no filter applies)
     */
    public function rating(string $playerId, bool $isMember): InsightsRatingFrame
    {
        return $this->ratingFor($this->insightsCache->keyFor($playerId), $playerId, $isMember);
    }

    /**
     * "What from my shelf suits me?" (ShelfPicks, the page's piece-count filter) - null with predictions off: a pick is
     * its expected time. A free player's part is the count of unsolved shelf puzzles only.
     */
    public function shelf(string $playerId, InsightsCriteria $criteria, bool $isMember, bool $predictionsAllowed): null|ShelfPicksResult
    {
        if ($predictionsAllowed === false) {
            return null;
        }

        return $this->shelfFor($this->insightsCache->keyFor($playerId), $playerId, $criteria, $isMember);
    }

    /**
     * The top row's "Try next" and "MSP rating" cards: the shelf's and the rating's cache entries under one key -
     * whichever frame comes first builds them, the others read them. Null with predictions off (both rest on them).
     */
    public function top(string $playerId, InsightsCriteria $criteria, bool $isMember, bool $predictionsAllowed): null|InsightsTopFrame
    {
        if ($predictionsAllowed === false) {
            return null;
        }

        $key = $this->insightsCache->keyFor($playerId);

        return new InsightsTopFrame(
            $this->ratingFor($key, $playerId, $isMember)->opportunities,
            $this->shelfFor($key, $playerId, $criteria, $isMember),
        );
    }

    private function ratingFor(InsightsCacheKey $key, string $playerId, bool $isMember): InsightsRatingFrame
    {
        return $this->insightsCache->get($key, self::part(InsightsLazyPart::Rating), ['member' => $isMember], function () use ($playerId, $isMember): InsightsRatingFrame {
            $model = $this->ratingModelLoader->load($playerId, PuzzleIntelligenceRecalculator::RATING_PIECES_COUNTS[0]);

            return new InsightsRatingFrame(
                RatingOpportunities::fromModel($model),
                $isMember ? $this->ratingForecast->forModel($playerId, $model) : null,
            );
        });
    }

    private function shelfFor(InsightsCacheKey $key, string $playerId, InsightsCriteria $criteria, bool $isMember): ShelfPicksResult
    {
        return $this->insightsCache->get(
            $key,
            self::part(InsightsLazyPart::Shelf),
            ['member' => $isMember, 'pieces' => $criteria->pieces->toParam()],
            fn (): ShelfPicksResult => $this->shelfPicks->build($playerId, $isMember, $criteria->pieces),
        );
    }

    /**
     * Section 11 - null when the player switched time predictions off: the radar is nothing but predictions
     */
    public function resolve(string $playerId, InsightsCriteria $criteria, bool $predictionsAllowed): null|ResolveRadarResult
    {
        if ($predictionsAllowed === false) {
            return null;
        }

        $key = $this->insightsCache->keyFor($playerId);

        return $this->insightsCache->get(
            $key,
            self::part(InsightsLazyPart::Resolve),
            ['pieces' => $criteria->pieces->toParam()],
            fn (): ResolveRadarResult => $this->resolveRadar->build($playerId, $criteria->pieces),
        );
    }

    /**
     * Section 13 - the period and the piece count of the page's filters
     */
    public function pairs(string $playerId, InsightsCriteria $criteria): PairsTeamsSynergyResult
    {
        $key = $this->insightsCache->keyFor($playerId);
        $now = $this->clock->now();

        return $this->insightsCache->get(
            $key,
            self::part(InsightsLazyPart::Pairs),
            ['filters' => self::filters(InsightsLazyPart::Pairs, $criteria), 'day' => $now->format('Y-m-d')],
            fn (): PairsTeamsSynergyResult => $this->pairsTeamsSynergy->build($playerId, $criteria->periodFrom($now), $criteria->periodBefore(), $criteria->pieces),
        );
    }

    /**
     * Section 15 - the pace curve of every piece count in the filter (the whole history: a curve is about how you got
     * here), the rating over time within the period (members)
     */
    public function improvement(string $playerId, InsightsCriteria $criteria, bool $isMember): InsightsImprovementFrame
    {
        $key = $this->insightsCache->keyFor($playerId);
        $now = $this->clock->now();

        return $this->insightsCache->get(
            $key,
            self::part(InsightsLazyPart::Improvement),
            ['filters' => self::filters(InsightsLazyPart::Improvement, $criteria), 'member' => $isMember, 'day' => $now->format('Y-m-d')],
            function () use ($playerId, $criteria, $isMember, $now): InsightsImprovementFrame {
                $curve = $this->improvementCurve->build($playerId);

                return new InsightsImprovementFrame(
                    new ImprovementCurveResult(array_values(array_filter(
                        $curve->sizes,
                        static fn (ImprovementCurveSize $size): bool => $criteria->pieces->contains($size->piecesCount),
                    ))),
                    $isMember ? $this->ratingOverTime->build($playerId, $criteria->periodFrom($now), $criteria->periodBefore()) : null,
                );
            },
        );
    }

    private static function part(InsightsLazyPart $part): string
    {
        return 'frame_' . $part->value;
    }

    private static function filters(InsightsLazyPart $part, InsightsCriteria $criteria): string
    {
        return ($criteria->isMember ? 'm' : 'f') . json_encode($part->query($criteria), JSON_THROW_ON_ERROR);
    }
}
