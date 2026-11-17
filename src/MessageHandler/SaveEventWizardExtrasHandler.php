<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\CompetitionDisplayLimitReached;
use SpeedPuzzling\Web\Exceptions\EventWizardExtraRefused;
use SpeedPuzzling\Web\Exceptions\InvalidCompetitionStanding;
use SpeedPuzzling\Web\Message\SaveEventWizardExtras;
use SpeedPuzzling\Web\Services\EventWizard\EventWizardRefusal;
use SpeedPuzzling\Web\Value\CompetitionRefereeAddition;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Throwable;

/**
 * Every row's message runs nested in this handler's transaction (each in its own savepoint), like
 * SetUpEventWizardRoundHandler: a refused row rolls back every row before it. A refusal the page explains comes out as
 * EventWizardExtraRefused naming its row.
 */
#[AsMessageHandler]
readonly final class SaveEventWizardExtrasHandler
{
    public function __construct(
        private MessageBusInterface $messageBus,
    ) {
    }

    /**
     * @return list<CompetitionRefereeAddition> the outcome of every judge added, in order
     * @throws EventWizardExtraRefused
     */
    public function __invoke(SaveEventWizardExtras $message): array
    {
        if ($message->registration !== null) {
            $this->messageBus->dispatch($message->registration);
        }

        if ($message->links !== null) {
            $this->messageBus->dispatch($message->links);
        }

        foreach ($message->removeReferees as $remove) {
            $this->messageBus->dispatch($remove);
        }

        $outcomes = [];

        foreach ($message->addReferees as $add) {
            $outcome = $this->messageBus->dispatch($add)->last(HandledStamp::class)?->getResult();
            assert($outcome instanceof CompetitionRefereeAddition);
            $outcomes[] = $outcome;
        }

        if ($message->competitorPages !== null) {
            $this->messageBus->dispatch($message->competitorPages);
        }

        foreach ($message->displays as $row => $display) {
            try {
                $this->messageBus->dispatch($display);
            } catch (Throwable $failure) {
                $refusal = EventWizardRefusal::find($failure, CompetitionDisplayLimitReached::class);

                throw $refusal !== null ? new EventWizardExtraRefused($row, $refusal) : $failure;
            }
        }

        foreach ($message->standings as $standing) {
            try {
                $this->messageBus->dispatch($standing);
            } catch (Throwable $failure) {
                $refusal = EventWizardRefusal::find($failure, InvalidCompetitionStanding::class);

                throw $refusal !== null ? new EventWizardExtraRefused('standings', $refusal) : $failure;
            }
        }

        return $outcomes;
    }
}
