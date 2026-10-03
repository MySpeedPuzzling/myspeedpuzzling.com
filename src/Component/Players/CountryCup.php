<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component\Players;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Query\GetCommunityScopeStats;
use SpeedPuzzling\Web\Results\CountryCupStandings;
use SpeedPuzzling\Web\Services\Community\CountryRankings;
use SpeedPuzzling\Web\Services\ViewerCountry;
use SpeedPuzzling\Web\Value\CommunityScope;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\CountryCupMeasure;
use SpeedPuzzling\Web\Value\CountryCupPeriod;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * The Country Cup (docs/features/players-page/README.md, stream S4): pieces placed per active puzzler
 * (CommunityScopeStatistics::CUP_MINIMUM_ACTIVE and more) or in total, this month or last month. The viewer's
 * country is highlighted and, with the page scope's country, added below the leaders when it is not among them.
 *
 * All four boards are rendered and switched in the browser (players_tabs_controller.js), from the one memoized
 * countries() statement the country tiles share.
 */
#[AsTwigComponent]
final class CountryCup
{
    public CommunityScope $scope;

    public function __construct(
        readonly private GetCommunityScopeStats $getCommunityScopeStats,
        readonly private CountryRankings $countryRankings,
        readonly private ViewerCountry $viewerCountry,
        readonly private ClockInterface $clock,
    ) {
        $this->scope = CommunityScope::world();
    }

    /**
     * False before the community stats cron's first run - the section stays out instead of showing empty boards.
     */
    public function hasNumbers(): bool
    {
        return $this->getCommunityScopeStats->countries() !== [];
    }

    /**
     * Every measure in every period.
     *
     * @return list<CountryCupStandings>
     */
    public function getStandings(): array
    {
        $countries = $this->getCommunityScopeStats->countries();
        $viewerCountry = $this->getViewerCountry();
        $standings = [];

        foreach ($this->getPeriods() as $period) {
            foreach (CountryCupMeasure::cases() as $measure) {
                $standings[] = $this->countryRankings->cup($countries, $measure, $period, $viewerCountry, $this->scope->country);
            }
        }

        return $standings;
    }

    /**
     * In calendar order, so the tabs read "September final · October so far".
     *
     * @return list<CountryCupPeriod>
     */
    public function getPeriods(): array
    {
        return [CountryCupPeriod::LastMonth, CountryCupPeriod::ThisMonth];
    }

    /**
     * Last month in a month's first days (CountryCupPeriod::LAST_MONTH_FIRST_DAYS), this month otherwise.
     */
    public function getDefaultPeriod(): CountryCupPeriod
    {
        return CountryCupPeriod::defaultAt($this->clock->now());
    }

    /**
     * The first day of the month a period stands for. Taken from when the numbers were computed, so the label always
     * matches them - also in the minutes after midnight on the 1st, before the cron's next run.
     */
    public function getMonth(CountryCupPeriod $period): DateTimeImmutable
    {
        $computedAt = ($this->getCommunityScopeStats->countries()[0] ?? null)->computedAt ?? $this->clock->now();
        $thisMonth = $computedAt->modify('first day of this month')->setTime(0, 0);

        return $period === CountryCupPeriod::ThisMonth ? $thisMonth : $thisMonth->modify('-1 month');
    }

    public function getViewerCountry(): null|CountryCode
    {
        return $this->viewerCountry->fromProfile();
    }

    public function isSignedIn(): bool
    {
        return $this->viewerCountry->isSignedIn();
    }
}
