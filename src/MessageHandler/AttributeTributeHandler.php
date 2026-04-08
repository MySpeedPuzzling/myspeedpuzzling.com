<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Tribute;
use SpeedPuzzling\Web\Exceptions\AffiliateNotFound;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Exceptions\TributeNotFound;
use SpeedPuzzling\Web\Message\AttributeTribute;
use SpeedPuzzling\Web\Repository\AffiliateRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\TributeRepository;
use SpeedPuzzling\Web\Value\TributeSource;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class AttributeTributeHandler
{
    public function __construct(
        private AffiliateRepository $affiliateRepository,
        private TributeRepository $tributeRepository,
        private PlayerRepository $playerRepository,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(AttributeTribute $message): void
    {
        // Determine which code to use: session (manual entry) takes priority over cookie (referral link)
        $code = $message->sessionTributeCode ?? $message->cookieTributeCode;
        $source = $message->sessionTributeCode !== null ? TributeSource::Code : TributeSource::Link;

        if ($code === null) {
            return;
        }

        // Check if subscriber already has a tribute
        try {
            $this->tributeRepository->getBySubscriberId($message->subscriberPlayerId);
            // Already has a tribute, skip
            $this->logger->info('Subscriber already has a tribute, skipping attribution', [
                'player_id' => $message->subscriberPlayerId,
            ]);
            return;
        } catch (TributeNotFound) {
            // Good, no existing tribute
        }

        try {
            $affiliate = $this->affiliateRepository->getByCode($code);
        } catch (AffiliateNotFound) {
            $this->logger->warning('Tribute code not found during attribution', [
                'code' => $code,
                'player_id' => $message->subscriberPlayerId,
            ]);
            return;
        }

        if (!$affiliate->isActive()) {
            $this->logger->info('Affiliate is not active, skipping tribute attribution', [
                'affiliate_id' => $affiliate->id->toString(),
                'status' => $affiliate->status->value,
            ]);
            return;
        }

        // Don't allow self-referral
        try {
            $subscriber = $this->playerRepository->get($message->subscriberPlayerId);
        } catch (PlayerNotFound) {
            return;
        }

        if ($affiliate->player->id->equals($subscriber->id)) {
            $this->logger->info('Self-referral detected, skipping tribute attribution', [
                'player_id' => $message->subscriberPlayerId,
            ]);
            return;
        }

        $tribute = new Tribute(
            id: Uuid::uuid7(),
            subscriber: $subscriber,
            affiliate: $affiliate,
            source: $source,
            createdAt: $this->clock->now(),
        );

        $this->tributeRepository->save($tribute);
    }
}
