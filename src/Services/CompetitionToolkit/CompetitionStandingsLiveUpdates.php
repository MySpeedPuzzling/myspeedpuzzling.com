<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\CompetitionToolkit;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Results\CompetitionStandingsPresent;
use SpeedPuzzling\Web\Services\Standings\PublicStandingSnapshots;

/**
 * The after-commit hook of the public standings (competition toolkit v2 S1, displays-standings.md §2.2): a write that can
 * change what a published standing shows starts a new generation of the event's guest snapshots
 * (PublicStandingSnapshots::changed()), so no reader gets the earlier snapshot again. Called only through
 * CompetitionDisplayLiveUpdates - the single after-commit choke point of every results / round write, which forwards here
 * first, before its own display gate. (The public Mercure signal is package S4.)
 *
 * Gated by CompetitionStandingsPresence (no statement while cached), so an event without published standings costs
 * nothing - and live result entry of a running, unpublished round neither: the guest view counts published rounds only.
 * - results of a round → only when a published standing counts it AND its results are published;
 * - the round itself (publication of its results, its name...) → when a published standing counts it;
 * - event-wide (the participants sheet, rounds added / deleted / moved) → when the event has a published standing;
 * - a standing or advancement step written → always.
 *
 * A failure never fails the write: logged (warning); the snapshots heal within their lifetime.
 */
final readonly class CompetitionStandingsLiveUpdates
{
    public function __construct(
        private CompetitionStandingsPresence $presence,
        private PublicStandingSnapshots $snapshots,
        private LoggerInterface $logger,
    ) {
    }

    public function resultsChanged(string $roundId): void
    {
        $this->forRound($roundId, false, static fn (CompetitionStandingsPresent $presence): bool => $presence->inPublishedStanding && $presence->roundPublished);
    }

    public function roundChanged(string $roundId): void
    {
        $this->forRound($roundId, true, static fn (CompetitionStandingsPresent $presence): bool => $presence->inPublishedStanding);
    }

    /**
     * Results of some of the event's rounds changed at once (the participants sheet).
     */
    public function competitionChanged(string $competitionId): void
    {
        $this->forCompetition($competitionId, false);
    }

    /**
     * The event's rounds changed (one added, deleted, moved in or out): which round counts where may have changed.
     */
    public function roundsChanged(string $competitionId): void
    {
        $this->forCompetition($competitionId, true);
    }

    /**
     * A standing (created, edited, deleted, published, unpublished) or an advancement step of the event was written.
     */
    public function standingsChanged(string $competitionId): void
    {
        try {
            $this->presence->changed($competitionId);
        } catch (\Throwable $exception) {
            $this->logger->warning('Could not forget whether an event has published standings', [
                'competition_id' => $competitionId,
                'exception' => $exception,
            ]);
        }

        $this->snapshots->changed($competitionId);
    }

    /**
     * @param \Closure(CompetitionStandingsPresent): bool $wanted
     */
    private function forRound(string $roundId, bool $forgetFirst, \Closure $wanted): void
    {
        try {
            if ($forgetFirst) {
                $this->presence->forgetRound($roundId);
            }

            $presence = $this->presence->forRound($roundId);
        } catch (\Throwable $exception) {
            $this->logger->warning('Could not tell whether a round counts in a published standing', [
                'round_id' => $roundId,
                'exception' => $exception,
            ]);

            return;
        }

        if ($presence->competitionId !== null && $wanted($presence)) {
            $this->snapshots->changed($presence->competitionId);
        }
    }

    private function forCompetition(string $competitionId, bool $forgetFirst): void
    {
        try {
            if ($forgetFirst) {
                $this->presence->changed($competitionId);
            }

            $presence = $this->presence->forCompetition($competitionId);
        } catch (\Throwable $exception) {
            $this->logger->warning('Could not tell whether an event has published standings', [
                'competition_id' => $competitionId,
                'exception' => $exception,
            ]);

            return;
        }

        if ($presence->anyPublished) {
            $this->snapshots->changed($competitionId);
        }
    }
}
