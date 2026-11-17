<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\CompetitionToolkit;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Results\CompetitionStandingsPresent;
use SpeedPuzzling\Web\Services\AfterResponse;
use SpeedPuzzling\Web\Services\Standings\PublicStandingSnapshots;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The after-commit hook of the public standings (competition toolkit v2 S1, displays-standings.md §2.2): a write that can
 * change what a published standing shows starts a new generation of the event's guest snapshots
 * (PublicStandingSnapshots::changed()), so no reader gets the earlier snapshot again. Called only through
 * CompetitionDisplayLiveUpdates - the single after-commit choke point of every results / round write, which forwards here
 * first, before its own display gate.
 *
 * Gated by CompetitionStandingsPresence (no statement while cached), so an event without published standings costs
 * nothing - and live result entry of a running, unpublished round neither: the guest view counts published rounds only.
 * - results of a round → only when a published standing counts it AND its results are published;
 * - the round itself (publication of its results, its name...) → when a published standing counts it;
 * - event-wide (the participants sheet, rounds added / deleted / moved) → when the event has a published standing;
 * - a standing or advancement step written → always.
 *
 * **The public signal (competition toolkit v2 S4, displays-standings.md §4.2):** after the new generation, one public
 * Mercure update on `/competition-standings/{competitionId}` (topic()) - `{"type": "standing.signal"}`, no data, no
 * standing id: anybody may listen (the public standing pages, an OBS browser source), and each answers it by fetching
 * what it shows again (the `public_standing_table` fragment, the public JSON) - read through the public rules. Sent
 * once the answer went out (AfterResponse, like the other pushes - the generation is new before the response, so a
 * fetch the signal starts reads the new data); one per event and request however many writes bumped it.
 *
 * A failure never fails the write: logged (warning); the snapshots heal within their lifetime, the pages within their
 * 60 s poll when the push is lost.
 */
final class CompetitionStandingsLiveUpdates implements ResetInterface
{
    public const string TYPE = 'standing.signal';

    /**
     * Events whose signal waits for the end of the request - one signal each.
     *
     * @var array<string, true>
     */
    private array $pending = [];

    public function __construct(
        readonly private CompetitionStandingsPresence $presence,
        readonly private PublicStandingSnapshots $snapshots,
        readonly private LoggerInterface $logger,
        readonly private HubInterface $hub,
        readonly private AfterResponse $afterResponse,
    ) {
    }

    /**
     * The public, data-free topic of an event's standings.
     */
    public static function topic(string $competitionId): string
    {
        return '/competition-standings/' . strtolower($competitionId);
    }

    public function reset(): void
    {
        $this->pending = [];
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

        $this->changed($competitionId);
    }

    /**
     * @param \Closure(CompetitionStandingsPresent): bool $wanted
     */
    private function forRound(string $roundId, bool $forgetFirst, \Closure $wanted): void
    {
        try {
            $presence = $this->presence->forRound($roundId);

            // An event without a published standing (nearly all) pays nothing: whether this round's results are published
            // only matters once a standing is published, and publishing one forgets every answer of the event
            if ($forgetFirst && $presence->anyPublished) {
                $this->presence->forgetRound($roundId);
                $presence = $this->presence->forRound($roundId);
            }
        } catch (\Throwable $exception) {
            $this->logger->warning('Could not tell whether a round counts in a published standing', [
                'round_id' => $roundId,
                'exception' => $exception,
            ]);

            return;
        }

        if ($presence->competitionId !== null && $wanted($presence)) {
            $this->changed($presence->competitionId);
        }
    }

    private function forCompetition(string $competitionId, bool $forgetFirst): void
    {
        try {
            $presence = $this->presence->forCompetition($competitionId);

            // Rounds added, deleted or moved: only an event with a published standing reads its answers again (publishing
            // a standing forgets them all)
            if ($forgetFirst && $presence->anyPublished) {
                $this->presence->changed($competitionId);
                $presence = $this->presence->forCompetition($competitionId);
            }
        } catch (\Throwable $exception) {
            $this->logger->warning('Could not tell whether an event has published standings', [
                'competition_id' => $competitionId,
                'exception' => $exception,
            ]);

            return;
        }

        if ($presence->anyPublished) {
            $this->changed($competitionId);
        }
    }

    /**
     * A new generation of the event's snapshots now, the public signal once the answer went out.
     */
    private function changed(string $competitionId): void
    {
        $this->snapshots->changed($competitionId);

        $key = strtolower($competitionId);

        if (isset($this->pending[$key])) {
            return;
        }

        $this->pending[$key] = true;
        $this->afterResponse->defer(function () use ($key): void {
            unset($this->pending[$key]);
            $this->signal($key);
        });
    }

    private function signal(string $competitionId): void
    {
        try {
            $this->hub->publish(new Update(
                self::topic($competitionId),
                json_encode(['type' => self::TYPE], JSON_THROW_ON_ERROR),
                private: false,
            ));
        } catch (\Throwable $exception) {
            $this->logger->warning('Could not publish a public standings signal', [
                'competition_id' => $competitionId,
                'exception' => $exception,
            ]);
        }
    }
}
