<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\CompetitionToolkit;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Results\CompetitionDisplaysPresent;
use SpeedPuzzling\Web\Services\OfficialResultsSubscription;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Tells an event's open displays (timer screens, stream overlays) that something they show changed - competition toolkit
 * D5, D-displays-overlays.md §7.3, R-review-and-plan.md §1.6. One private Mercure update on the event's display topic
 * (OfficialResultsSubscription::displayTopic()): `{"type": "display.signal", "what": "results|puzzles|round|design",
 * "roundId"?: "…", "displayId"?: "…"}` - no data, a leaked display token learns only that something changed; the open
 * displays answer it with a debounced state fetch (assets/displays/runtime.js), which reads everything through the
 * public rules (RoundPuzzlesVisibleNow, the results gating of GetCompetitionDisplayState).
 *
 * - Called by the controllers after the dispatch returned - after the commit - never from a handler: a rolled back
 *   write signals nothing. OfficialResultsLiveUpdates calls it for every results write it publishes.
 * - An event without displays gets nothing (rule 1: an untouched competition behaves as before) - asked through
 *   CompetitionDisplaysPresence, which costs no statement while its answer is cached. A results signal needs a display
 *   showing results (`uses_results`).
 * - The public stopwatch topic already reaches every display following the round (start, stop, schedule, reset - the
 *   runtime re-picks the round when another followed round starts): the stopwatch actions signal nothing here, except
 *   a start that reveals puzzles (the reveal push, puzzlesChanged() through OfficialResultsLiveUpdates::puzzlesRevealed()).
 * - A failure never fails the write: logged (warning), the displays catch up on their 60 s state fetch.
 */
final readonly class CompetitionDisplayLiveUpdates
{
    public const string TYPE = 'display.signal';

    public function __construct(
        private HubInterface $hub,
        private CompetitionDisplaysPresence $presence,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Results of the round changed (entries recorded, taken out, a round refresh).
     */
    public function resultsChanged(string $roundId): void
    {
        $this->signalRound($roundId, 'results', static fn (CompetitionDisplaysPresent $presence): bool => $presence->results);
    }

    /**
     * Results of some of the event's rounds changed at once (the participants sheet).
     */
    public function resultsChangedInCompetition(string $competitionId): void
    {
        $this->signalCompetition($competitionId, 'results', static fn (CompetitionDisplaysPresent $presence): bool => $presence->results);
    }

    /**
     * What of the round's puzzles is visible changed or will at another moment (a reveal, a reveal setting, a puzzle
     * added, removed or arranged).
     */
    public function puzzlesChanged(string $roundId): void
    {
        $this->signalRound($roundId, 'puzzles', static fn (CompetitionDisplaysPresent $presence): bool => $presence->displays);
    }

    /**
     * The round itself changed - its name, start, limit, badge, format, publication of its results.
     */
    public function roundChanged(string $roundId): void
    {
        $this->signalRound($roundId, 'round', static fn (CompetitionDisplaysPresent $presence): bool => $presence->displays);
    }

    /**
     * The event's rounds changed - one added, deleted or moved in or out: a display following "the current round" may
     * pick another one, and its subscription (the followed rounds' stopwatch topics) changes.
     */
    public function roundsChanged(string $competitionId): void
    {
        $this->signalCompetition($competitionId, 'round', static fn (CompetitionDisplaysPresent $presence): bool => $presence->displays);
    }

    /**
     * One display changed - its design saved, its settings (follow mode, pinned round, language), its link regenerated or
     * it was deleted: the open page reads its state again (an old token answers 404 - "no longer valid" at once).
     * Always signalled: the display existed a moment ago (a deleted one leaves no display to ask about).
     */
    public function displayChanged(string $competitionId, string $displayId): void
    {
        $this->publish($competitionId, ['type' => self::TYPE, 'what' => 'design', 'displayId' => strtolower($displayId)]);
    }

    /**
     * @param \Closure(CompetitionDisplaysPresent): bool $wanted
     */
    private function signalRound(string $roundId, string $what, \Closure $wanted): void
    {
        try {
            $presence = $this->presence->forRound($roundId);
        } catch (\Throwable $exception) {
            $this->logger->warning('Could not tell whether a round has displays', [
                'round_id' => $roundId,
                'exception' => $exception,
            ]);

            return;
        }

        if ($presence->competitionId === null || $wanted($presence) === false) {
            return;
        }

        $this->publish($presence->competitionId, ['type' => self::TYPE, 'what' => $what, 'roundId' => strtolower($roundId)]);
    }

    /**
     * @param \Closure(CompetitionDisplaysPresent): bool $wanted
     */
    private function signalCompetition(string $competitionId, string $what, \Closure $wanted): void
    {
        try {
            $presence = $this->presence->forCompetition($competitionId);
        } catch (\Throwable $exception) {
            $this->logger->warning('Could not tell whether an event has displays', [
                'competition_id' => $competitionId,
                'exception' => $exception,
            ]);

            return;
        }

        if ($wanted($presence) === false) {
            return;
        }

        $this->publish($competitionId, ['type' => self::TYPE, 'what' => $what]);
    }

    /**
     * @param array<string, string> $signal
     */
    private function publish(string $competitionId, array $signal): void
    {
        try {
            $this->hub->publish(new Update(
                OfficialResultsSubscription::displayTopic($competitionId),
                json_encode($signal, JSON_THROW_ON_ERROR),
                private: true,
            ));
        } catch (\Throwable $exception) {
            $this->logger->warning('Could not publish a display signal', [
                'competition_id' => $competitionId,
                'exception' => $exception,
            ]);
        }
    }
}
