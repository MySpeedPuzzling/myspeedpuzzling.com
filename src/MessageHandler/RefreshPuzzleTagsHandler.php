<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\RefreshPuzzleTags;
use SpeedPuzzling\Web\Results\PuzzleTagWriteOutcome;
use SpeedPuzzling\Web\Services\PuzzleTags\PuzzleTagWriter;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class RefreshPuzzleTagsHandler
{
    public function __construct(
        private PuzzleTagWriter $puzzleTagWriter,
    ) {
    }

    public function __invoke(RefreshPuzzleTags $message): PuzzleTagWriteOutcome
    {
        return $this->puzzleTagWriter->refresh($message->puzzleIds, $message->full);
    }
}
