<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Message\CloseOutdatedPuzzleRequests;
use SpeedPuzzling\Web\Services\OutdatedPuzzleRequests;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Merges and record saves close the requests they leave nothing to do for themselves (OutdatedPuzzleRequests), so
 * this normally closes nothing. Whatever it does close went around them - a puzzle deleted or changed by SQL, a writer
 * not wired to OutdatedPuzzleRequests - and is worth a look: a warning.
 */
#[AsMessageHandler]
readonly final class CloseOutdatedPuzzleRequestsHandler
{
    public function __construct(
        private OutdatedPuzzleRequests $outdatedPuzzleRequests,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{mergeRequests: int, changeRequests: int} How many were closed
     */
    public function __invoke(CloseOutdatedPuzzleRequests $message): array
    {
        $closed = $this->outdatedPuzzleRequests->closeAll();

        if ($closed['mergeRequests'] > 0 || $closed['changeRequests'] > 0) {
            $this->logger->warning('Closed {mergeRequests} merge and {changeRequests} change requests left outdated by something that does not close them itself', $closed);
        }

        return $closed;
    }
}
