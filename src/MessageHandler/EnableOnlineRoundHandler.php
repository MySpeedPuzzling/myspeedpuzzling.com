<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\OnlineRound;
use SpeedPuzzling\Web\Exceptions\CompetitionRoundNotFound;
use SpeedPuzzling\Web\Exceptions\OnlineTimingNotAvailable;
use SpeedPuzzling\Web\Message\EnableOnlineRound;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Repository\OnlineRoundRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlinePersonalLinks;
use SpeedPuzzling\Web\Value\OnlineTimingAvailability;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Turns online timing on for a round (docs/features/online-competitions/README.md): its OnlineRound with the Phase 1
 * defaults (detection off, no video, pieces left, complete puzzle required), and a personal link for every participant
 * of the event who is going and has none. Again on a round that has it = only the missing links.
 */
#[AsMessageHandler]
readonly final class EnableOnlineRoundHandler
{
    public function __construct(
        private CompetitionRoundRepository $competitionRoundRepository,
        private OnlineRoundRepository $onlineRoundRepository,
        private PlayerRepository $playerRepository,
        private OnlinePersonalLinks $onlinePersonalLinks,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return int how many participants got a personal link
     *
     * @throws CompetitionRoundNotFound
     * @throws OnlineTimingNotAvailable
     */
    public function __invoke(EnableOnlineRound $message): int
    {
        $round = $this->competitionRoundRepository->get($message->roundId);
        $competition = $round->competition;
        $competitionId = $competition->id->toString();

        // The caller was authorised for this competition only
        if ($competitionId !== strtolower($message->competitionId)) {
            throw new CompetitionRoundNotFound();
        }

        $availability = OnlineTimingAvailability::of($competition->isOnline, $round->category);

        if ($availability->isAvailable() === false) {
            throw new OnlineTimingNotAvailable($availability);
        }

        $now = $this->clock->now();

        if ($this->onlineRoundRepository->ofRound($round->id->toString()) === null) {
            $this->onlineRoundRepository->save(new OnlineRound(
                id: Uuid::uuid7(),
                round: $round,
                enabledAt: $now,
                enabledBy: $this->playerRepository->get($message->actingPlayerId),
            ));
        }

        return $this->onlinePersonalLinks->giveMissing($competitionId, $now);
    }
}
