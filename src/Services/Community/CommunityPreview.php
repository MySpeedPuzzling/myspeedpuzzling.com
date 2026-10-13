<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Community;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Query\GetEventOccurrences;
use SpeedPuzzling\Web\Query\GetFastestPlayers;
use SpeedPuzzling\Web\Query\GetSpotlightPeople;
use SpeedPuzzling\Web\Results\CommunityLanding;
use SpeedPuzzling\Web\Results\EventsPage\AgendaRow;
use SpeedPuzzling\Web\Services\EventsPage\EventRowFactory;
use SpeedPuzzling\Web\Value\CommunityScope;
use SpeedPuzzling\Web\Value\EventOccurrenceStatus;
use SpeedPuzzling\Web\Value\EventsScope;
use SpeedPuzzling\Web\Value\OccurrenceDates;

/**
 * The Community landing's previews (docs/features/navigation README §4.9, COM-01) in three statements - one per
 * preview, the page's whole own cost (CommunityPageQueryBudgetTest):
 * - Leaderboards: the top LEADERS of the solo 500-piece board (GetFastestPlayers - private and opted-out rules, the
 *   viewer's blocks and the guests' one-minute cache come with it), without the viewer's own row;
 * - Puzzlers: the first PUZZLERS of the spotlight's "Most active this month" for the world (GetSpotlightPeople - public
 *   profiles only, for everybody);
 * - Events: the next EVENTS public occurrences, live or coming, dated (GetEventOccurrences::upcoming()), at most one per
 *   series - a weekly series would fill every row (decision 74) - and one per one-time event (a competition whose
 *   rounds fall on separate days is several occurrences).
 *
 * Nothing depends on the viewer but the players they hide, so the guests' page stays shared-cacheable and the
 * signed-in page costs the same for everybody. No state.
 */
readonly final class CommunityPreview
{
    public const int LEADERBOARD_PIECES = 500;
    public const int LEADERS = 3;
    public const int PUZZLERS = 3;
    public const int EVENTS = 3;

    public function __construct(
        private GetFastestPlayers $getFastestPlayers,
        private GetSpotlightPeople $getSpotlightPeople,
        private GetEventOccurrences $getEventOccurrences,
        private EventRowFactory $eventRows,
        private ClockInterface $clock,
    ) {
    }

    public function build(string $locale): CommunityLanding
    {
        $leaders = $this->getFastestPlayers->window(self::LEADERBOARD_PIECES, null, self::LEADERS);

        return new CommunityLanding(
            leaderboardPieces: self::LEADERBOARD_PIECES,
            leaders: $leaders->rows,
            leaderRanks: $leaders->ranks,
            puzzlers: array_slice($this->getSpotlightPeople->forScope(CommunityScope::world())->mostActive, 0, self::PUZZLERS),
            events: $this->events($locale),
        );
    }

    /**
     * @return list<AgendaRow>
     */
    private function events(string $locale): array
    {
        $now = $this->clock->now();
        $day = OccurrenceDates::today($now);
        $taken = [];
        $rows = [];

        // Ordered by start (GetEventOccurrences), so the first occurrence of a series is its next one
        foreach ($this->getEventOccurrences->upcoming() as $occurrence) {
            if ($occurrence->startDate === null) {
                continue;
            }

            $status = $occurrence->status($now);

            if ($status !== EventOccurrenceStatus::Live && $status !== EventOccurrenceStatus::Upcoming) {
                continue;
            }

            $key = $occurrence->seriesId !== null ? 'series:' . strtolower($occurrence->seriesId) : 'competition:' . strtolower($occurrence->competitionId);

            if (isset($taken[$key])) {
                continue;
            }

            $taken[$key] = true;
            $rows[] = $this->eventRows->row($occurrence, $status, count($rows), [], null, EventsScope::everywhere(), $now, $day, $locale, null);

            if (count($rows) === self::EVENTS) {
                break;
            }
        }

        return $rows;
    }
}
