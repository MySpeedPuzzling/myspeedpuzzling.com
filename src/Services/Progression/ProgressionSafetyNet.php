<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Progression;

use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Message\RecalculateBadgesForPlayer;
use SpeedPuzzling\Web\Message\RecalculateXpForPlayer;
use SpeedPuzzling\Web\Query\GetProgressionDrift;
use SpeedPuzzling\Web\Results\ProgressionDrift;
use SpeedPuzzling\Web\Results\ProgressionSafetyNetRun;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/**
 * The nightly check of path independence (docs/features/xp-levels/README.md §Anti-farming): finds the players whose
 * progression disagrees with their current records (GetProgressionDrift, set-based) and - with $repair - rebuilds only
 * them, in this process (a full XP rebuild, an achievement evaluation). Anything found is a warning: the live wiring
 * should never leave drift behind, so a person looks at which check found it (a write path that is not wired, SQL).
 */
readonly final class ProgressionSafetyNet
{
    private const int CLEAR_EVERY = 100;

    public function __construct(
        private GetProgressionDrift $getProgressionDrift,
        private MessageBusInterface $commandBus,
        private ManagerRegistry $doctrine,
        private LoggerInterface $logger,
    ) {
    }

    public function check(bool $repair): ProgressionSafetyNetRun
    {
        $drift = $this->getProgressionDrift->find();

        if ($drift->isClean()) {
            return new ProgressionSafetyNetRun($drift, repaired: 0, failed: []);
        }

        $repaired = 0;
        $failed = [];

        if ($repair) {
            foreach ($this->repairs($drift) as $index => $message) {
                try {
                    $this->commandBus->dispatch($message, [new TransportNamesStamp(['sync'])]);
                    $repaired++;
                } catch (HandlerFailedException $exception) {
                    $failed[] = $message->playerId;
                    $this->logger->error('Progression repair of a player failed', [
                        'playerId' => $message->playerId,
                        'message' => $message::class,
                        'exception' => $exception,
                    ]);
                    // A failed flush closes the entity manager - the next player needs a fresh one
                    $this->doctrine->resetManager();
                }

                if (($index + 1) % self::CLEAR_EVERY === 0) {
                    $this->doctrine->getManager()->clear();
                }
            }

            $this->doctrine->getManager()->clear();
        }

        $this->logger->warning('Progression drift found - XP / achievements disagreed with the current records', [
            'counts' => $drift->counts(),
            'repair' => $repair,
            'repaired' => $repaired,
            'failed' => count($failed),
            'samplePlayerIds' => array_slice([...$drift->xpPlayerIds(), ...$drift->achievementPlayerIds()], 0, 20),
        ]);

        return new ProgressionSafetyNetRun($drift, $repaired, $failed);
    }

    /**
     * @return list<RecalculateXpForPlayer|RecalculateBadgesForPlayer>
     */
    private function repairs(ProgressionDrift $drift): array
    {
        $messages = [];

        foreach ($drift->xpPlayerIds() as $playerId) {
            $messages[] = new RecalculateXpForPlayer($playerId);
        }

        foreach ($drift->achievementPlayerIds() as $playerId) {
            $messages[] = new RecalculateBadgesForPlayer($playerId);
        }

        return $messages;
    }
}
