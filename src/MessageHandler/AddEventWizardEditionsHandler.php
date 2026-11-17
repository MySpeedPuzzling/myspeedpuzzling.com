<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\AddEventWizardEditions;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\CompetitionSeriesRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * AddEditions, then a round for each edition it created - nested in this handler's transaction (each in its own
 * savepoint), like AddCompetitionRoundWithPuzzlesHandler: a refused round rolls the dates back too. Which editions are
 * new is told by their ids: absent before, present after.
 */
#[AsMessageHandler]
readonly final class AddEventWizardEditionsHandler
{
    public function __construct(
        private MessageBusInterface $messageBus,
        private CompetitionRepository $competitionRepository,
        private CompetitionSeriesRepository $seriesRepository,
    ) {
    }

    /**
     * @return int how many editions were created (AddEditions' answer)
     */
    public function __invoke(AddEventWizardEditions $message): int
    {
        $existedBefore = [];
        foreach ($message->editions->editions as $edition) {
            $existedBefore[$edition->competitionId->toString()] = $this->competitionRepository->has($edition->competitionId);
        }

        $created = $this->messageBus->dispatch($message->editions)->last(HandledStamp::class)?->getResult();

        foreach ($message->rounds as $round) {
            $competitionId = strtolower($round->competitionId);

            if (($existedBefore[$competitionId] ?? true) === false && $this->competitionRepository->has(Uuid::fromString($competitionId))) {
                $this->messageBus->dispatch($round);
            }
        }

        // "When it happens" from the rule, only when dates were created and the series has no text of its own
        if ($message->schedule !== null && is_int($created) && $created > 0) {
            $this->seriesRepository->get($message->editions->seriesId)->scheduleFromDatesRule($message->schedule);
        }

        return is_int($created) ? $created : 0;
    }
}
