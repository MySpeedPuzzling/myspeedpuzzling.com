<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\MarkOauthIdentityUsed;
use SpeedPuzzling\Web\Repository\OauthIdentityRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class MarkOauthIdentityUsedHandler
{
    public function __construct(
        private OauthIdentityRepository $oauthIdentityRepository,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(MarkOauthIdentityUsed $message): void
    {
        $oauthIdentity = $this->oauthIdentityRepository->findByProviderUserId(
            $message->provider,
            $message->providerUserId,
        );

        // Racing an unlink is benign - there is nothing left to stamp
        $oauthIdentity?->markUsed($this->clock->now());
    }
}
