<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Exceptions\AnalyticsStudioNotFound;
use SpeedPuzzling\Web\Message\WarmAnalyticsStudioPredictions;
use SpeedPuzzling\Web\Query\GetAnalyticsStudioEntrants;
use SpeedPuzzling\Web\Query\GetPlayerPredictions;
use SpeedPuzzling\Web\Results\AnalyticsStudioMember;
use SpeedPuzzling\Web\Services\AnalyticsStudio\AnalyticsStudioContextLoader;
use SpeedPuzzling\Web\Services\AnalyticsStudio\AnalyticsStudioPredictions;
use SpeedPuzzling\Web\Services\AnalyticsStudio\AnalyticsStudioRoundPredictionsBuilder;
use SpeedPuzzling\Web\Value\RoundClock;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * One turn of the round-wide prediction warm of an analytics studio round (analytics-studio.md R28-R33). Reads only and
 * writes only the studio's cache - the per-player entries the entrant, compare and round-predictions answers share
 * (AnalyticsStudioPredictions::forMember(), the same callback and key: every value is exactly what the detail shows).
 *
 * 1. A message older than STALE_AFTER_MS does nothing (a backlog after a consumer outage never bursts).
 * 2. The round and its visible puzzles now (2 statements; RoundPuzzlesVisibleNow alone decides the puzzles): a round no
 *    longer publicly visible, another visible set than the message's (a reveal or a re-hide happened - the page asks for
 *    the new one itself), no visible puzzle or a pair/team round (D12) do nothing.
 * 3. The entrants (1): the public members who did not opt out, by player id; those already cached are skipped (one
 *    multi-get), the others computed one by one - the memos cleared every RESET_EVERY computed members. A member whose
 *    computation fails is one warning and "no prediction" kept for them (rememberNoPrediction()) - the others go on;
 *    more than MAX_FAILED_MEMBERS_PER_TURN failures in a turn end the warm (one warning, the cause is not a player).
 * 4. After SECONDS_PER_TURN with members left the next turn is queued (and the round's warming marker renewed, so no
 *    request starts a second chain meanwhile) and this one ends: it runs on the spreadsheet imports' consumer, whose
 *    rule is "no job longer than ~10 s" - an import never waits long behind a warm. After MAX_TURNS it stops with a
 *    warning. A chain that ends (done, nothing to do, its last turn) removes the marker.
 *
 * Any other failure is a warning and the end of the warm - no retries (the transport has none); the marker is left to
 * expire, so a failing round is not warmed again for a minute however often its page asks.
 */
#[AsMessageHandler]
readonly final class WarmAnalyticsStudioPredictionsHandler
{
    public const int STALE_AFTER_MS = 600_000;
    public const float SECONDS_PER_TURN = 8.0;
    public const int MAX_TURNS = 20;
    public const int RESET_EVERY = 25;
    /** More failed members in one turn than this end the warm - the cause is not one player (the database, Redis) */
    public const int MAX_FAILED_MEMBERS_PER_TURN = 3;

    public function __construct(
        private AnalyticsStudioContextLoader $contextLoader,
        private GetAnalyticsStudioEntrants $getAnalyticsStudioEntrants,
        private AnalyticsStudioPredictions $predictions,
        private AnalyticsStudioRoundPredictionsBuilder $roundPredictionsBuilder,
        private GetPlayerPredictions $getPlayerPredictions,
        private MessageBusInterface $messageBus,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        /** Arguments only so that a test can end a turn early */
        private float $secondsPerTurn = self::SECONDS_PER_TURN,
        private int $membersPerTurn = PHP_INT_MAX,
    ) {
    }

    public function __invoke(WarmAnalyticsStudioPredictions $message): void
    {
        try {
            if ($this->warm($message) === false) {
                $this->roundPredictionsBuilder->warmingEnded($message);
            }
        } catch (AnalyticsStudioNotFound) {
            $this->logger->info('Analytics studio round of a prediction warm is not there', ['round_id' => $message->roundId]);
            $this->roundPredictionsBuilder->warmingEnded($message);
        } catch (\Throwable $e) {
            $this->logger->warning('Could not warm the analytics studio predictions', [
                'round_id' => $message->roundId,
                'turn' => $message->turn,
                'exception' => $e,
            ]);
        }
    }

    /**
     * @return bool whether the next turn was queued (the chain goes on)
     * @throws AnalyticsStudioNotFound
     */
    private function warm(WarmAnalyticsStudioPredictions $message): bool
    {
        if (RoundClock::milliseconds($this->clock->now()) - $message->requestedAtMs > self::STALE_AFTER_MS) {
            return false;
        }

        $context = $this->contextLoader->load($message->roundId);

        if ($context->visibleHash !== $message->visibleHash || AnalyticsStudioRoundPredictionsBuilder::predictable($context) === false) {
            return false;
        }

        /** @var array<string, AnalyticsStudioMember> $byPlayer */
        $byPlayer = [];

        foreach ($this->getAnalyticsStudioEntrants->forRound($context->round) as $entrant) {
            foreach ($entrant->members as $member) {
                $playerId = $member->analysablePlayerId();

                if ($playerId !== null && $member->timePredictionsOptedOut === false) {
                    $byPlayer[$playerId] ??= $member;
                }
            }
        }

        ksort($byPlayer);
        $cached = $this->predictions->cachedForMembers(array_values($byPlayer), $context->visible);
        $missing = array_values(array_filter(
            $byPlayer,
            static fn (AnalyticsStudioMember $member): bool => array_key_exists($member->participantId, $cached) === false,
        ));

        $startedAt = hrtime(true);
        $computed = 0;
        $failed = 0;

        foreach ($missing as $index => $member) {
            try {
                $this->predictions->forMember($member, $context->visible);
            } catch (\Throwable $e) {
                if (++$failed > self::MAX_FAILED_MEMBERS_PER_TURN) {
                    throw $e;
                }

                $this->logger->warning('Could not compute a time prediction for the analytics studio', [
                    'round_id' => $message->roundId,
                    'player_id' => $member->analysablePlayerId(),
                    'exception' => $e,
                ]);
                $this->predictions->rememberNoPrediction($member, $context->visible);
            }

            $computed++;

            if ($computed % self::RESET_EVERY === 0) {
                $this->getPlayerPredictions->reset();
                $this->predictions->reset();
            }

            $left = $index + 1 < count($missing);
            $turnOver = $computed >= $this->membersPerTurn || (hrtime(true) - $startedAt) / 1_000_000_000 >= $this->secondsPerTurn;

            if ($left && $turnOver) {
                if ($message->turn + 1 > self::MAX_TURNS) {
                    $this->logger->warning('Analytics studio prediction warm stopped after its last turn', [
                        'round_id' => $message->roundId,
                        'turn' => $message->turn,
                        'members_left' => count($missing) - $index - 1,
                    ]);

                    return false;
                }

                $next = new WarmAnalyticsStudioPredictions(
                    $message->roundId,
                    $message->visibleHash,
                    $message->requestedAtMs,
                    $message->turn + 1,
                );
                $this->messageBus->dispatch($next);
                $this->roundPredictionsBuilder->markWarming($next);

                return true;
            }
        }

        return false;
    }
}
