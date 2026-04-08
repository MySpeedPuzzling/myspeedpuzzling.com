<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Referral;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Exceptions\ReferralNotFound;
use SpeedPuzzling\Web\Message\AttributeReferral;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\ReferralRepository;
use SpeedPuzzling\Web\Value\ReferralSource;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class AttributeReferralHandler
{
    public function __construct(
        private ReferralRepository $referralRepository,
        private PlayerRepository $playerRepository,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(AttributeReferral $message): void
    {
        $code = $message->sessionReferralCode ?? $message->cookieReferralCode;
        $source = $message->sessionReferralCode !== null ? ReferralSource::Code : ReferralSource::Link;

        if ($code === null) {
            return;
        }

        // Check if subscriber already has a referral
        try {
            $this->referralRepository->getBySubscriberId($message->subscriberPlayerId);
            $this->logger->info('Subscriber already has a referral, skipping attribution', [
                'player_id' => $message->subscriberPlayerId,
            ]);
            return;
        } catch (ReferralNotFound) {
            // Good, no existing referral
        }

        // Find affiliate player by code
        try {
            $affiliatePlayer = $this->playerRepository->getByCode($code);
        } catch (PlayerNotFound) {
            $this->logger->warning('Referral code not found during attribution', [
                'code' => $code,
                'player_id' => $message->subscriberPlayerId,
            ]);
            return;
        }

        if (!$affiliatePlayer->isInReferralProgram()) {
            $this->logger->info('Player is not in referral program, skipping attribution', [
                'affiliate_player_id' => $affiliatePlayer->id->toString(),
            ]);
            return;
        }

        // Don't allow self-referral
        try {
            $subscriber = $this->playerRepository->get($message->subscriberPlayerId);
        } catch (PlayerNotFound) {
            return;
        }

        if ($affiliatePlayer->id->equals($subscriber->id)) {
            $this->logger->info('Self-referral detected, skipping referral attribution', [
                'player_id' => $message->subscriberPlayerId,
            ]);
            return;
        }

        $referral = new Referral(
            id: Uuid::uuid7(),
            subscriber: $subscriber,
            affiliatePlayer: $affiliatePlayer,
            source: $source,
            createdAt: $this->clock->now(),
        );

        $this->referralRepository->save($referral);
    }
}
