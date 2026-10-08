<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\EventDetail;

use DateTimeImmutable;
use SpeedPuzzling\Web\Results\CompetitionReference;
use SpeedPuzzling\Web\Results\EditionRoundDetail;
use SpeedPuzzling\Web\Results\EventDetail\RoundsTimeline;
use SpeedPuzzling\Web\Results\EventDetail\TimelineRound;
use SpeedPuzzling\Web\Results\EventsPage\DateLeaf;
use SpeedPuzzling\Web\Results\EventsPage\WhenLabel;
use SpeedPuzzling\Web\Services\EventsPage\EventRowFactory;
use SpeedPuzzling\Web\Value\EventOccurrenceStatus;
use SpeedPuzzling\Web\Value\EventTime;
use SpeedPuzzling\Web\Value\OccurrenceDates;
use SpeedPuzzling\Web\Value\OccurrenceRound;
use SpeedPuzzling\Web\Value\RoundStatus;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The rounds timeline of the edition and one-time event pages (docs/features/events-page/detail-pages.md "Rounds
 * timeline", detail-pages-plan.md 1.3): one row per round in schedule order, past / live / next / later, earlier rounds
 * folded, the links each round offers - and the page's dates, from the same sessions rule as the events page
 * (OccurrenceDates::sessions()). Pure apart from the URL generator; each rule has a test in RoundsTimelineBuilderTest.
 */
readonly final class RoundsTimelineBuilder
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @param list<EditionRoundDetail> $rounds GetEditionRounds::forCompetition() - by start, ties by id
     * @param array<string, int> $resultsPerRound round id => results (CountCompetitionResults::perRound())
     */
    public function build(
        CompetitionReference $event,
        string $competitionId,
        array $rounds,
        bool $isOnline,
        bool $isPublic,
        array $resultsPerRound,
        bool $canAddTime,
        null|DateTimeImmutable $dateFrom,
        null|DateTimeImmutable $dateTo,
        DateTimeImmutable $now,
    ): RoundsTimeline {
        $isPastAt = static fn (EditionRoundDetail $round): bool => $round->startsAt->modify('+' . $round->minutesLimit . ' minutes') <= $now;

        // Next: the first round not over - a running one counts
        $nextRoundId = null;

        foreach ($rounds as $round) {
            if ($isPastAt($round) === false) {
                $nextRoundId = $round->id;

                break;
            }
        }

        // Folded: the past rounds before the latest past one, only when a next round exists
        $pastIds = array_values(array_map(
            static fn (EditionRoundDetail $round): string => $round->id,
            array_filter($rounds, $isPastAt),
        ));
        $foldedIds = $nextRoundId !== null ? array_slice($pastIds, 0, max(0, count($pastIds) - 1)) : [];

        $timelineRounds = [];
        $roundsWithResults = 0;

        foreach ($rounds as $round) {
            $started = $round->startsAt <= $now;
            $past = $isPastAt($round);
            $status = match (true) {
                $past => RoundStatus::Past,
                $started => RoundStatus::Live,
                $round->id === $nextRoundId => RoundStatus::Next,
                default => RoundStatus::Later,
            };

            $hasResults = ($resultsPerRound[$round->id] ?? 0) > 0 || $round->resultsPublished;

            if ($hasResults) {
                $roundsWithResults++;
            }

            $localDay = OccurrenceDates::localDay($round->startsAt, $round->timezone);

            $timelineRounds[] = new TimelineRound(
                round: $round,
                leaf: new DateLeaf(
                    from: $localDay,
                    to: null,
                    tone: $past ? DateLeaf::TONE_MUTED : ($isOnline ? DateLeaf::TONE_ONLINE : DateLeaf::TONE_IN_PERSON),
                ),
                status: $status,
                when: self::when($status, $round, $now),
                time: EventTime::fromRound($round),
                folded: in_array($round->id, $foldedIds, true),
                resultsUrl: $isPublic && $hasResults ? $this->resultsUrl($event, $round) : null,
                officialResults: $round->resultsPublished,
                addTimeUrl: $canAddTime && $started ? $this->addTimeUrl($competitionId, $round) : null,
                puzzlesAnnounced: $round->puzzles !== [],
            );
        }

        $sessions = OccurrenceDates::sessions($dateFrom, $dateTo, array_map(
            static fn (EditionRoundDetail $round): OccurrenceRound => new OccurrenceRound(
                id: $round->id,
                name: $round->name,
                startsAt: $round->startsAt,
                zone: $round->timezone,
                zoneAssumed: $round->timezoneAssumed,
            ),
            $rounds,
        ));

        $first = $sessions[0];
        $last = $sessions[count($sessions) - 1];
        $isLive = array_any($timelineRounds, static fn (TimelineRound $round): bool => $round->status === RoundStatus::Live);

        // Without rounds the span decides (a one-day event is live on its day)
        if ($rounds === []) {
            $isLive = $first->status($now, $event->seriesName !== null, $isOnline) === EventOccurrenceStatus::Live;
        }

        $firstRound = $rounds[0] ?? null;

        return new RoundsTimeline(
            rounds: $timelineRounds,
            nextRoundId: $nextRoundId,
            foldedCount: count($foldedIds),
            sessions: $sessions,
            start: $first->start,
            end: $last->end ?? $last->start,
            isLive: $isLive,
            roundsWithResults: $roundsWithResults,
            zone: $firstRound?->timezone,
            zoneAssumed: $firstRound !== null && $firstRound->timezoneAssumed,
        );
    }

    /**
     * Live: "Live"; Next: "Today" on the round's own day, else the events page's words by local days ("Tomorrow",
     * "This weekend", "In 13 days", nothing beyond 30 days)
     */
    private static function when(RoundStatus $status, EditionRoundDetail $round, DateTimeImmutable $now): null|WhenLabel
    {
        if ($status === RoundStatus::Live) {
            return new WhenLabel(WhenLabel::LIVE, 0, false);
        }

        if ($status !== RoundStatus::Next) {
            return null;
        }

        $roundDay = OccurrenceDates::localDay($round->startsAt, $round->timezone);
        $today = OccurrenceDates::localDay($now, $round->timezone);

        if ($roundDay == $today) {
            return new WhenLabel(WhenLabel::TODAY, 0, true);
        }

        return EventRowFactory::when(EventOccurrenceStatus::Upcoming, $roundDay, $today);
    }

    private function resultsUrl(CompetitionReference $event, EditionRoundDetail $round): null|string
    {
        if ($round->slug === null) {
            return null;
        }

        return match ($event->routeName()) {
            'event_detail' => $this->urlGenerator->generate('event_round_results', [
                'slug' => (string) $event->slug,
                'roundSlug' => $round->slug,
            ]),
            'edition_detail' => $this->urlGenerator->generate('edition_round_results', [
                'seriesSlug' => (string) $event->seriesSlug,
                'editionSlug' => (string) $event->slug,
                'roundSlug' => $round->slug,
            ]),
            default => null,
        };
    }

    /**
     * The add-time form with the event chosen - and the puzzle, when the round has exactly one with its picture shown
     */
    private function addTimeUrl(string $competitionId, EditionRoundDetail $round): string
    {
        $parameters = ['competition' => $competitionId];

        if (count($round->puzzles) === 1) {
            $puzzle = array_values($round->puzzles)[0];

            if ($puzzle->imageHidden === false) {
                $parameters['puzzleId'] = $puzzle->puzzleId;
            }
        }

        return $this->urlGenerator->generate('puzzle_add', $parameters);
    }
}
