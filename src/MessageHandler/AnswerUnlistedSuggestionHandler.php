<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\UnlistedPuzzleNotFound;
use SpeedPuzzling\Web\Message\AnswerUnlistedSuggestion;
use SpeedPuzzling\Web\Repository\UnlistedPuzzleRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * "Not this one" to a suggested catalogue puzzle - the player's own unlisted puzzle only.
 */
#[AsMessageHandler]
readonly final class AnswerUnlistedSuggestionHandler
{
    public function __construct(
        private UnlistedPuzzleRepository $unlistedRepository,
    ) {
    }

    /**
     * @throws UnlistedPuzzleNotFound
     */
    public function __invoke(AnswerUnlistedSuggestion $message): void
    {
        $this->unlistedRepository->getOwnedBy($message->unlistedPuzzleId, $message->playerId)->suggestionRefused();
    }
}
