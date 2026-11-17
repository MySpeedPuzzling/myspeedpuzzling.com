<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\CompetitionStanding;
use SpeedPuzzling\Web\Exceptions\InvalidCompetitionStanding;
use SpeedPuzzling\Web\Message\CreateCompetitionStanding;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\CompetitionStandingRepository;
use SpeedPuzzling\Web\Services\CompetitionToolkit\CompetitionStandingRounds;
use SpeedPuzzling\Web\Services\RoundResults\CompetitionRoundSlugGenerator;
use SpeedPuzzling\Web\Value\StandingMode;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * A new standing comes last on the event's list, unpublished, with a slug free in the event (A-round-formats.md §3.2).
 */
#[AsMessageHandler]
readonly final class CreateCompetitionStandingHandler
{
    public function __construct(
        private CompetitionRepository $competitionRepository,
        private CompetitionStandingRepository $standingRepository,
        private CompetitionStandingRounds $standingRounds,
        private CompetitionRoundSlugGenerator $slugGenerator,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws InvalidCompetitionStanding
     */
    public function __invoke(CreateCompetitionStanding $message): void
    {
        $competition = $this->competitionRepository->get($message->competitionId);
        $existing = $this->standingRepository->ofCompetition($competition);

        if (count($existing) >= CompetitionStanding::MAX_PER_COMPETITION) {
            throw new InvalidCompetitionStanding('limit_reached');
        }

        try {
            $name = CompetitionStanding::cleanName($message->name);
        } catch (InvalidArgumentException) {
            throw new InvalidCompetitionStanding('invalid_name');
        }

        $roundIds = $this->standingRounds->validated($competition, $message->category, $message->roundIds);

        $this->standingRepository->save(new CompetitionStanding(
            id: $message->standingId,
            competition: $competition,
            category: $message->category,
            name: $name,
            slug: $this->slugGenerator->generate($name, array_map(static fn (CompetitionStanding $standing): string => $standing->slug, $existing)),
            roundIds: $roundIds,
            createdAt: $this->clock->now(),
            position: array_reduce($existing, static fn (int $carry, CompetitionStanding $standing): int => max($carry, $standing->position + 1), 0),
            mode: StandingMode::Sum,
            unfinishedPenaltySecondsPerPiece: $message->rules->penaltySecondsPerPiece,
            orderRule: $message->rules->order,
            missingRule: $message->rules->missing,
            penaliseFinishedMissing: $message->rules->penaliseFinishedMissing,
        ));
    }
}
