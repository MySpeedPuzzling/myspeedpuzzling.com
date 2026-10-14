<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\CompetitionRoundNotFound;
use SpeedPuzzling\Web\Exceptions\RoundStopwatchRefused;
use SpeedPuzzling\Web\Message\ResetRoundStopwatch;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Repository\OnlineEntryRepository;
use SpeedPuzzling\Web\Repository\OnlineRoundRepository;
use SpeedPuzzling\Web\Services\OfficialResultsGuard;
use SpeedPuzzling\Web\Value\RoundStopwatchRefusal;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Clears the round's stopwatch - never started again. Publishes nothing: the controller tells the open pages once the
 * dispatch returned, after the commit (RoundStopwatchLiveUpdates).
 *
 * A round with online timing starts a fresh run (docs/features/online-competitions/README.md §5.1): a run is one start
 * of the round clock, and what the phones record counts for the run it belongs to only (events from its start on). The
 * Reset is refused while the round has official results (RoundStopwatchRefusal::ResultsConfirmed - the results desk
 * clears them first: they belong to the run that was), and every online entry forgets what belonged to that run - the
 * pieces reported at its time-up and the review's decision (OnlineEntry::startFreshRun()). The events stay stored.
 * The message takes the event's participants lock, so a result recorded at the same moment never slips past the check.
 */
#[AsMessageHandler]
readonly final class ResetRoundStopwatchHandler
{
    public function __construct(
        private CompetitionRoundRepository $competitionRoundRepository,
        private OnlineRoundRepository $onlineRoundRepository,
        private OnlineEntryRepository $onlineEntryRepository,
        private OfficialResultsGuard $officialResultsGuard,
    ) {
    }

    /**
     * @throws CompetitionRoundNotFound a round of another event
     * @throws RoundStopwatchRefused
     */
    public function __invoke(ResetRoundStopwatch $message): void
    {
        $round = $this->competitionRoundRepository->get($message->roundId);
        $roundId = $round->id->toString();

        // The event whose lock this message holds - never a round of another one
        if ($round->competition->id->toString() !== strtolower($message->competitionId)) {
            throw new CompetitionRoundNotFound();
        }

        if ($this->onlineRoundRepository->ofRound($roundId) !== null) {
            if ($this->officialResultsGuard->roundHasResults($roundId)) {
                throw new RoundStopwatchRefused(RoundStopwatchRefusal::ResultsConfirmed);
            }

            foreach ($this->onlineEntryRepository->ofRound($roundId) as $onlineEntry) {
                $onlineEntry->startFreshRun();
            }
        }

        $round->resetStopwatch();
    }
}
