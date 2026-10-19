<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Insights;

use DateTimeZone;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Query\GetCloseCalls;
use SpeedPuzzling\Web\Query\GetInsightsContext;
use SpeedPuzzling\Web\Query\GetInsightsPuzzles;
use SpeedPuzzling\Web\Query\GetInsightsSoloResults;
use SpeedPuzzling\Web\Results\Insights\InsightsPage;
use SpeedPuzzling\Web\Results\Insights\InsightsReport;
use SpeedPuzzling\Web\Value\TimezoneIdentifier;

/**
 * The first response of the Insights page: four statements whatever the player's size - the solo results, the close
 * calls, the context (generation, profile card, learning ratios, rating) and the hydrate of the ≤ 50 puzzles shown
 * (pinned by InsightsQueryBudgetTest). Self-only: call it for the signed-in player's own id (the close calls use the
 * viewer's hidden players and private-profile allow list, exactly like the leaderboards they rank on).
 */
readonly final class InsightsPageLoader
{
    public function __construct(
        private GetInsightsSoloResults $getInsightsSoloResults,
        private GetCloseCalls $getCloseCalls,
        private GetInsightsContext $getInsightsContext,
        private GetInsightsPuzzles $getInsightsPuzzles,
        private InsightsBuilder $builder,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param null|string $timezone the player's own zone (PlayerProfile::$timezone)
     * @throws PlayerNotFound
     */
    public function report(string $playerId, InsightsCriteria $criteria, null|string $timezone): InsightsReport
    {
        return $this->builder->build(
            $playerId,
            $this->getInsightsSoloResults->forPlayer($playerId),
            $this->getCloseCalls->forPlayer($playerId),
            $this->getInsightsContext->forPlayer($playerId),
            $criteria,
            self::zone($timezone),
            $this->clock->now(),
        );
    }

    /**
     * @param null|string $timezone the player's own zone (PlayerProfile::$timezone)
     * @throws PlayerNotFound
     */
    public function load(string $playerId, InsightsCriteria $criteria, null|string $timezone): InsightsPage
    {
        $report = $this->report($playerId, $criteria, $timezone);

        return new InsightsPage($report, $this->getInsightsPuzzles->byIds($report->puzzleIdsShown));
    }

    public static function zone(null|string $timezone): null|DateTimeZone
    {
        return $timezone !== null && TimezoneIdentifier::isListed($timezone) ? new DateTimeZone($timezone) : null;
    }
}
