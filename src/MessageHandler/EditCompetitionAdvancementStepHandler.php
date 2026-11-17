<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Entity\CompetitionAdvancementStep;
use SpeedPuzzling\Web\Exceptions\AdvancementStepNotFound;
use SpeedPuzzling\Web\Exceptions\InvalidAdvancementStep;
use SpeedPuzzling\Web\Message\EditCompetitionAdvancementStep;
use SpeedPuzzling\Web\Repository\CompetitionAdvancementStepRepository;
use SpeedPuzzling\Web\Services\CompetitionToolkit\CompetitionAdvancementSteps;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class EditCompetitionAdvancementStepHandler
{
    public function __construct(
        private CompetitionAdvancementStepRepository $stepRepository,
        private CompetitionAdvancementSteps $steps,
    ) {
    }

    /**
     * @throws AdvancementStepNotFound also for a step of another event
     * @throws InvalidAdvancementStep
     */
    public function __invoke(EditCompetitionAdvancementStep $message): void
    {
        $step = $this->stepRepository->get($message->stepId);

        if ($step->competition->id->toString() !== strtolower($message->competitionId)) {
            throw new AdvancementStepNotFound();
        }

        $step->change(
            CompetitionAdvancementStep::cleanName($message->name),
            $this->steps->validated($step->competition, $this->stepRepository->ofCompetition($step->competition), $step->id->toString(), $message->settings),
        );
    }
}
