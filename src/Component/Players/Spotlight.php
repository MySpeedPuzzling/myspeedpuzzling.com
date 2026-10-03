<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component\Players;

use DateTimeImmutable;
use Locale;
use MessageFormatter;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Query\GetCommunityScopeStats;
use SpeedPuzzling\Web\Query\GetSpotlightPeople;
use SpeedPuzzling\Web\Query\GetUpcomingEventsCount;
use SpeedPuzzling\Web\Results\CommunityScopeStatistics;
use SpeedPuzzling\Web\Results\SpotlightPeople;
use SpeedPuzzling\Web\Results\SpotlightPerson;
use SpeedPuzzling\Web\Value\CommunityScope;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * The spotlight on the world or one country (docs/features/players-page/README.md, stream S2): the scope's numbers
 * with a sparkline, then Most active this month · Most followed · New faces.
 *
 * Three statements per render: the scope + world numbers, the upcoming events, the three people lists.
 */
#[AsTwigComponent]
final class Spotlight
{
    // Rows shown before "Show more" reveals the rest of the list (GetSpotlightPeople::LIMIT)
    public const int VISIBLE_ROWS = 5;

    // Bars of the sparkline: the last six complete months and the current one
    public const int SPARKLINE_MONTHS = 7;

    public CommunityScope $scope;

    /** @var null|array{scope: CommunityScopeStatistics, world: CommunityScopeStatistics} */
    private null|array $numbers = null;

    private null|SpotlightPeople $people = null;

    private null|int $upcomingEvents = null;

    public function __construct(
        readonly private GetCommunityScopeStats $getCommunityScopeStats,
        readonly private GetSpotlightPeople $getSpotlightPeople,
        readonly private GetUpcomingEventsCount $getUpcomingEventsCount,
        readonly private ClockInterface $clock,
    ) {
        $this->scope = CommunityScope::world();
    }

    public function getVisibleRows(): int
    {
        return self::VISIBLE_ROWS;
    }

    public function getStatistics(): CommunityScopeStatistics
    {
        return $this->numbers()['scope'];
    }

    public function getWorld(): CommunityScopeStatistics
    {
        return $this->numbers()['world'];
    }

    public function getPeople(): SpotlightPeople
    {
        return $this->people ??= $this->getSpotlightPeople->forScope($this->scope);
    }

    public function getUpcomingEvents(): int
    {
        return $this->upcomingEvents ??= $this->getUpcomingEventsCount->forScope($this->scope);
    }

    /**
     * The first day (UTC) of the month the numbers' "this month" is - the month of the last recalculation, so a page
     * shortly after midnight on the 1st does not label last month's pieces with the new month.
     */
    public function getMonth(): DateTimeImmutable
    {
        $reference = $this->getStatistics()->computedAt ?? $this->clock->now();

        return $reference->modify('first day of this month')->setTime(0, 0);
    }

    /**
     * Solves per month for the sparkline, oldest first, the current month last. Empty when there is nothing to draw.
     *
     * @return list<array{month: DateTimeImmutable, solves: int, height: int, current: bool}>
     */
    public function getSparkline(): array
    {
        $monthly = array_slice($this->getStatistics()->monthlySolves, -self::SPARKLINE_MONTHS);
        $highest = $monthly === [] ? 0 : max($monthly);

        if ($highest === 0) {
            return [];
        }

        $currentMonth = $this->getMonth();
        $last = count($monthly) - 1;
        $bars = [];

        foreach ($monthly as $index => $solves) {
            $bars[] = [
                'month' => $currentMonth->modify(sprintf('-%d months', $last - $index)),
                'solves' => $solves,
                // A month without solves keeps a sliver, so the bar is still there to read
                'height' => max(4, (int) round($solves / $highest * 100)),
                'current' => $index === $last,
            ];
        }

        return $bars;
    }

    /**
     * A big number shortened for the stat tiles: 13.6M, 236K (in the page's locale).
     */
    public function compactNumber(int $number): string
    {
        $formatted = MessageFormatter::formatMessage(Locale::getDefault(), '{0, number, ::compact-short .#}', [$number]);

        return is_string($formatted) ? $formatted : number_format($number);
    }

    public function daysSinceJoined(SpotlightPerson $person): int
    {
        return $person->registeredAt->diff($this->clock->now())->days;
    }

    /**
     * @return array{scope: CommunityScopeStatistics, world: CommunityScopeStatistics}
     */
    private function numbers(): array
    {
        return $this->numbers ??= $this->getCommunityScopeStats->forScopeWithWorld($this->scope);
    }
}
