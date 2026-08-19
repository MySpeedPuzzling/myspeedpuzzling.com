<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use SpeedPuzzling\Web\Exceptions\CompetitionNotFound;
use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use SpeedPuzzling\Web\Query\GetCompetitionSeries;
use SpeedPuzzling\Web\Query\GetEditionRounds;
use SpeedPuzzling\Web\Query\IsCompetitionPubliclyVisible;
use SpeedPuzzling\Web\Results\EditionRoundDetail;
use SpeedPuzzling\Web\Results\EditionRoundPuzzle;

/**
 * @implements ProviderInterface<CompetitionDetailResponse>
 */
final readonly class CompetitionDetailResponseProvider implements ProviderInterface
{
    public function __construct(
        private GetCompetitionEvents $getCompetitionEvents,
        private GetEditionRounds $getEditionRounds,
        private GetCompetitionSeries $getCompetitionSeries,
        private IsCompetitionPubliclyVisible $isCompetitionPubliclyVisible,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): CompetitionDetailResponse
    {
        /** @var string $competitionId */
        $competitionId = $uriVariables['id'];

        $competition = $this->getCompetitionEvents->byId($competitionId);

        // Privacy gate: only publicly visible competitions are readable through the API.
        // GetCompetitionEvents::byId() intentionally does NOT filter on approval (it serves the
        // owner/admin web flows too), so an unapproved or rejected competition — and its
        // not-yet-revealed puzzles — must 404 here instead of leaking. A competition can be both
        // approved and rejected (approve() and reject() do not clear each other), so the rejected
        // state must veto a stale approval. Editions of a series are never approved individually
        // (their own approved_at stays NULL) — they are visible iff their SERIES is approved and
        // not rejected. IsCompetitionPubliclyVisible is the single source of truth for both rules.
        if ($this->isCompetitionPubliclyVisible->check($competitionId) === false) {
            throw new CompetitionNotFound();
        }

        $series = null;

        if ($competition->seriesId !== null) {
            $seriesOverview = $this->getCompetitionSeries->byId($competition->seriesId);

            $series = new CompetitionSeriesSummaryResponse(
                id: $seriesOverview->id,
                name: $seriesOverview->name,
                slug: $seriesOverview->slug,
            );
        }

        // Rounds (and their puzzles) come from GetEditionRounds, the single source of truth for
        // the puzzle-reveal rule: puzzles flagged hide-until-round-starts are omitted (Entirely)
        // or stripped of their image (ImageOnly) until round.startsAt + 10 minutes. Participants
        // are never loaded here.
        $rounds = array_map(
            $this->mapRound(...),
            $this->getEditionRounds->forCompetition($competitionId),
        );

        return new CompetitionDetailResponse(
            id: $competition->id,
            name: $competition->name,
            shortcut: $competition->shortcut,
            slug: $competition->slug,
            logo: $competition->logo,
            description: $competition->description,
            location: $competition->location,
            countryCode: $competition->locationCountryCode?->name,
            isOnline: $competition->isOnline,
            dateFrom: $competition->dateFrom?->format('c'),
            dateTo: $competition->dateTo?->format('c'),
            link: $competition->link,
            registrationLink: $competition->registrationLink,
            resultsLink: $competition->resultsLink,
            rounds: $rounds,
            series: $series,
        );
    }

    private function mapRound(EditionRoundDetail $round): CompetitionRoundResponse
    {
        return new CompetitionRoundResponse(
            id: $round->id,
            name: $round->name,
            startsAt: $round->startsAt->format('c'),
            minutesLimit: $round->minutesLimit,
            category: $round->category->value,
            puzzles: array_map($this->mapPuzzle(...), $round->puzzles),
        );
    }

    private function mapPuzzle(EditionRoundPuzzle $puzzle): CompetitionRoundPuzzleResponse
    {
        return new CompetitionRoundPuzzleResponse(
            id: $puzzle->puzzleId,
            name: $puzzle->puzzleName,
            piecesCount: $puzzle->piecesCount,
            image: $puzzle->puzzleImage,
            manufacturerName: $puzzle->manufacturerName,
        );
    }
}
