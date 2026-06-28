<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use SpeedPuzzling\Web\Exceptions\CompetitionNotFound;
use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use SpeedPuzzling\Web\Query\GetEditionRounds;
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
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): CompetitionDetailResponse
    {
        /** @var string $competitionId */
        $competitionId = $uriVariables['id'];

        $competition = $this->getCompetitionEvents->byId($competitionId);

        // Privacy gate: only approved, non-rejected competitions are publicly readable through
        // the API. GetCompetitionEvents::byId() intentionally does NOT filter on approval (it
        // serves the owner/admin web flows too), so an unapproved or rejected competition — and
        // its not-yet-revealed puzzles — must 404 here instead of leaking. A competition can be
        // both approved and rejected (approve() and reject() do not clear each other), so the
        // rejected state must veto a stale approval.
        if ($competition->approvedAt === null || $competition->rejectedAt !== null) {
            throw new CompetitionNotFound();
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
            country_code: $competition->locationCountryCode?->name,
            is_online: $competition->isOnline,
            date_from: $competition->dateFrom?->format('c'),
            date_to: $competition->dateTo?->format('c'),
            link: $competition->link,
            registration_link: $competition->registrationLink,
            results_link: $competition->resultsLink,
            rounds: $rounds,
        );
    }

    private function mapRound(EditionRoundDetail $round): CompetitionRoundResponse
    {
        return new CompetitionRoundResponse(
            id: $round->id,
            name: $round->name,
            starts_at: $round->startsAt->format('c'),
            minutes_limit: $round->minutesLimit,
            category: $round->category->value,
            puzzles: array_map($this->mapPuzzle(...), $round->puzzles),
        );
    }

    private function mapPuzzle(EditionRoundPuzzle $puzzle): CompetitionRoundPuzzleResponse
    {
        return new CompetitionRoundPuzzleResponse(
            id: $puzzle->puzzleId,
            name: $puzzle->puzzleName,
            pieces_count: $puzzle->piecesCount,
            image: $puzzle->puzzleImage,
            manufacturer_name: $puzzle->manufacturerName,
        );
    }
}
