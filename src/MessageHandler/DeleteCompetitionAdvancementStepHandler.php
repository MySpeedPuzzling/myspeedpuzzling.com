<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\AdvancementStepNotFound;
use SpeedPuzzling\Web\Message\DeleteCompetitionAdvancementStep;
use SpeedPuzzling\Web\Repository\CompetitionAdvancementStepRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class DeleteCompetitionAdvancementStepHandler
{
    public function __construct(
        private CompetitionAdvancementStepRepository $stepRepository,
    ) {
    }

    /**
     * @throws AdvancementStepNotFound also for a step of another event
     */
    public function __invoke(DeleteCompetitionAdvancementStep $message): void
    {
        $step = $this->stepRepository->get($message->stepId);

        if ($step->competition->id->toString() !== strtolower($message->competitionId)) {
            throw new AdvancementStepNotFound();
        }

        $this->stepRepository->delete($step);
    }
}
