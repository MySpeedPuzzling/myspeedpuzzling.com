<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Query\GetRoundStopwatchState;
use SpeedPuzzling\Web\Results\RoundStopwatchState;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Tells every open page of a round what its stopwatch does now - a public Mercure update on `/round-stopwatch/{roundId}`
 * (topic()) carrying the whole state (RoundStopwatchState: the projector page, the stopwatch control page, the live
 * entry, the participants' pages of online rounds).
 *
 * Called by the controllers after the dispatch returned, i.e. after the commit - never from a handler: a start
 * announced from inside the transaction would start every phone even when the commit then fails. The state is read
 * back from the database, so it is what was committed. A Mercure failure never fails the write: it is logged
 * (warning) and the pages catch up on their next state fetch.
 */
final readonly class RoundStopwatchLiveUpdates
{
    public function __construct(
        private HubInterface $hub,
        private GetRoundStopwatchState $getRoundStopwatchState,
        private LoggerInterface $logger,
    ) {
    }

    public static function topic(string $roundId): string
    {
        return OfficialResultsSubscription::stopwatchTopic($roundId);
    }

    /**
     * @return null|RoundStopwatchState what was published - null when nothing could be (logged)
     */
    public function stopwatchChanged(string $roundId): null|RoundStopwatchState
    {
        try {
            $state = $this->getRoundStopwatchState->forRound($roundId);

            $this->hub->publish(new Update(
                self::topic($roundId),
                json_encode($state, JSON_THROW_ON_ERROR),
                private: false,
            ));

            return $state;
        } catch (\Throwable $exception) {
            $this->logger->warning('Could not publish a round stopwatch update', [
                'round_id' => $roundId,
                'exception' => $exception,
            ]);

            return null;
        }
    }
}
