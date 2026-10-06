<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\AddCompetitionRoundWithPuzzles;
use SpeedPuzzling\Web\Message\SetCompetitionRoundPuzzles;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Both messages run nested in this handler's transaction (each in its own savepoint), like
 * ProposeDuplicatePuzzleMergeHandler: whatever refuses the puzzles rolls the round back too.
 */
#[AsMessageHandler]
readonly final class AddCompetitionRoundWithPuzzlesHandler
{
    public function __construct(
        private MessageBusInterface $messageBus,
    ) {
    }

    public function __invoke(AddCompetitionRoundWithPuzzles $message): void
    {
        $this->messageBus->dispatch($message->round);

        if ($message->puzzleIds !== []) {
            $this->messageBus->dispatch(new SetCompetitionRoundPuzzles(
                roundId: $message->round->roundId->toString(),
                puzzleIds: $message->puzzleIds,
            ));
        }
    }
}
