<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\EventWizardStructureLocked;
use SpeedPuzzling\Web\Message\DeleteCompetitionStanding;
use SpeedPuzzling\Web\Message\SetUpEventWizardStructure;
use SpeedPuzzling\Web\Query\GetEventWizardStructureLock;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\CompetitionStandingRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Every message runs nested in this handler's transaction (each in its own savepoint, flushed before the next one
 * reads), like SetUpEventWizardRoundHandler: whatever refuses rolls back everything the step wrote. The page's check
 * "no entry, result or stopwatch run yet" is made again here, under the event's participants lock (SerializedByLock),
 * before anything existing is touched - a round delete takes its entries with it.
 */
#[AsMessageHandler]
readonly final class SetUpEventWizardStructureHandler
{
    public function __construct(
        private MessageBusInterface $messageBus,
        private GetEventWizardStructureLock $structureLock,
        private CompetitionRepository $competitionRepository,
        private CompetitionStandingRepository $standingRepository,
    ) {
    }

    /**
     * @throws EventWizardStructureLocked
     */
    public function __invoke(SetUpEventWizardStructure $message): void
    {
        $changesExisting = $message->replaceStandings || $message->deletes !== [] || $message->edits !== [];

        if ($changesExisting && $this->structureLock->isLocked($message->competitionId)) {
            throw new EventWizardStructureLocked();
        }

        if ($message->replaceStandings) {
            foreach ($this->standingRepository->ofCompetition($this->competitionRepository->get($message->competitionId)) as $standing) {
                $this->messageBus->dispatch(new DeleteCompetitionStanding($standing->id->toString(), $message->competitionId));
            }
        }

        foreach ([...$message->deletes, ...$message->edits, ...$message->adds, ...$message->advancementSteps, ...$message->standings] as $inner) {
            $this->messageBus->dispatch($inner);
        }
    }
}
