<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Referral;
use SpeedPuzzling\Web\Exceptions\AffiliateNotFound;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Exceptions\ReferralNotFound;
use SpeedPuzzling\Web\Message\AttributeReferral;
use SpeedPuzzling\Web\Repository\AffiliateRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\ReferralRepository;
use SpeedPuzzling\Web\Value\ReferralSource;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class AttributeReferralHandler
{
    public function __construct(
        private AffiliateRepository $affiliateRepository,
        private ReferralRepository $referralRepository,
        private PlayerRepository $playerRepository,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(AttributeReferral $message): void
    {
        // Determine which code to use: session (manual entry) takes priority over cookie (referral link)
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

        try {
            $affiliate = $this->affiliateRepository->getByCode($code);
        } catch (AffiliateNotFound) {
            $this->logger->warning('Referral code not found during attribution', [
                'code' => $code,
                'player_id' => $message->subscriberPlayerId,
            ]);
            return;
        }

        if (!$affiliate->isActive()) {
            $this->logger->info('Affiliate is not active, skipping referral attribution', [
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
            $this->logger->info('Self-referral detected, skipping referral attribution', [
                'player_id' => $message->subscriberPlayerId,
            ]);
            return;
        }

        $referral = new Referral(
            id: Uuid::uuid7(),
            subscriber: $subscriber,
            affiliate: $affiliate,
            source: $source,
            createdAt: $this->clock->now(),
        );

        $this->referralRepository->save($referral);
    }
}
