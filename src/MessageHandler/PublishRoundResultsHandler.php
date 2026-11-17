<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\CompetitionRoundNotFound;
use SpeedPuzzling\Web\Message\PublishRoundResults;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Services\SecretPuzzleHides;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class PublishRoundResultsHandler
{
    public function __construct(
        private CompetitionRoundRepository $roundRepository,
        private ClockInterface $clock,
        private SecretPuzzleHides $secretPuzzleHides,
    ) {
    }

    /**
     * @throws CompetitionRoundNotFound
     */
    public function __invoke(PublishRoundResults $message): void
    {
        $round = $this->roundRepository->get($message->roundId);

        if ($round->competition->id->toString() !== strtolower($message->competitionId)) {
            throw new CompetitionRoundNotFound();
        }

        // The first publish of an unboxed round lets its pictures out (world-series-rules.md §7): its secret puzzles'
        // site-wide hide follows - under the round's lock, read again after it. Every other round runs what it ran before.
        if ($round->keepsPicturesUnboxed()) {
            $secretPuzzleIds = $this->secretPuzzleHides->lockRoundsForChange([$message->roundId]);
            $round = $this->roundRepository->get($message->roundId);
            $round->publishResults($this->clock->now());
            $this->secretPuzzleHides->resyncByIds($secretPuzzleIds);

            return;
        }

        $round->publishResults($this->clock->now());
    }
}
