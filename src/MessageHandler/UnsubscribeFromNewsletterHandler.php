<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\InvalidNewsletterToken;
use SpeedPuzzling\Web\Exceptions\NewsletterSubscriberNotFound;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\PushNewsletterSubscriberToListmonk;
use SpeedPuzzling\Web\Message\UnsubscribeFromNewsletter;
use SpeedPuzzling\Web\Repository\NewsletterSubscriberRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\NewsletterTokenSigner;
use SpeedPuzzling\Web\Value\NewsletterAudience;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
readonly final class UnsubscribeFromNewsletterHandler
{
    public function __construct(
        private NewsletterTokenSigner $tokenSigner,
        private PlayerRepository $playerRepository,
        private NewsletterSubscriberRepository $newsletterSubscriberRepository,
        private ClockInterface $clock,
        private MessageBusInterface $messageBus,
    ) {
    }

    /**
     * @throws InvalidNewsletterToken
     */
    public function __invoke(UnsubscribeFromNewsletter $message): void
    {
        $claim = $this->tokenSigner->parseUnsubscribeToken($message->token);

        if ($claim->audience === NewsletterAudience::Player) {
            try {
                $player = $this->playerRepository->get($claim->id);
            } catch (PlayerNotFound) {
                throw new InvalidNewsletterToken();
            }

            if ($player->email === null || mb_strtolower(trim($player->email)) !== $claim->email) {
                throw new InvalidNewsletterToken();
            }

            $player->changeNewsletterEnabled(false);
        } else {
            try {
                $subscriber = $this->newsletterSubscriberRepository->get($claim->id);
            } catch (NewsletterSubscriberNotFound) {
                throw new InvalidNewsletterToken();
            }

            if ($subscriber->email !== $claim->email) {
                throw new InvalidNewsletterToken();
            }

            $subscriber->unsubscribe($this->clock->now());
        }

        $this->messageBus->dispatch(new PushNewsletterSubscriberToListmonk($claim->email));
    }
}
