<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\CompetitionAdvancementStep;
use SpeedPuzzling\Web\Exceptions\InvalidAdvancementStep;
use SpeedPuzzling\Web\Message\CreateCompetitionAdvancementStep;
use SpeedPuzzling\Web\Repository\CompetitionAdvancementStepRepository;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Services\CompetitionToolkit\CompetitionAdvancementSteps;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * A new step comes last on the event's list (competition toolkit A8, A-round-formats.md §3.5).
 */
#[AsMessageHandler]
readonly final class CreateCompetitionAdvancementStepHandler
{
    public function __construct(
        private CompetitionRepository $competitionRepository,
        private CompetitionAdvancementStepRepository $stepRepository,
        private CompetitionAdvancementSteps $steps,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws InvalidAdvancementStep
     */
    public function __invoke(CreateCompetitionAdvancementStep $message): void
    {
        $competition = $this->competitionRepository->get($message->competitionId);
        $existing = $this->stepRepository->ofCompetition($competition);

        if (count($existing) >= CompetitionAdvancementStep::MAX_PER_COMPETITION) {
            throw new InvalidAdvancementStep('limit_reached');
        }

        $settings = $this->steps->validated($competition, $existing, $message->stepId->toString(), $message->settings);

        $this->stepRepository->save(new CompetitionAdvancementStep(
            id: $message->stepId,
            competition: $competition,
            name: CompetitionAdvancementStep::cleanName($message->name),
            sourceRoundIds: $settings->sourceRoundIds,
            targetRoundIds: $settings->targetRoundIds,
            distribution: $settings->distribution,
            createdAt: $this->clock->now(),
            targetBySource: $settings->targetBySource,
            qualifyCount: $settings->qualifyCount,
            qualifyScope: $settings->qualifyScope,
            bestOfEachCountry: $settings->bestOfEachCountry,
            position: array_reduce($existing, static fn (int $carry, CompetitionAdvancementStep $step): int => max($carry, $step->position + 1), 0),
        ));
    }
}
