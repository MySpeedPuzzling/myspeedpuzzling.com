<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Affiliate;
use SpeedPuzzling\Web\Exceptions\AffiliateNotFound;
use SpeedPuzzling\Web\Message\JoinReferralProgram;
use SpeedPuzzling\Web\Repository\AffiliateRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Value\AffiliateStatus;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class JoinReferralProgramHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private AffiliateRepository $affiliateRepository,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(JoinReferralProgram $message): void
    {
        // Check if already enrolled
        try {
            $this->affiliateRepository->getByPlayerId($message->playerId);
            return;
        } catch (AffiliateNotFound) {
            // Good, not yet enrolled
        }

        $player = $this->playerRepository->get($message->playerId);

        $affiliate = new Affiliate(
            id: Uuid::uuid7(),
            player: $player,
            code: $player->code,
            createdAt: $this->clock->now(),
            status: AffiliateStatus::Active,
        );

        $this->affiliateRepository->save($affiliate);
    }
}
