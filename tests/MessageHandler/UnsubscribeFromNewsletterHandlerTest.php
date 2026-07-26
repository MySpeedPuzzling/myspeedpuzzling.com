<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use SpeedPuzzling\Web\Message\ConfirmNewsletterSubscription;
use SpeedPuzzling\Web\Message\SubscribeToNewsletter;
use SpeedPuzzling\Web\Message\UnsubscribeFromNewsletter;
use SpeedPuzzling\Web\Repository\NewsletterSubscriberRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\NewsletterTokenSigner;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\NewsletterAudience;
use SpeedPuzzling\Web\Value\NewsletterSubscriberStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class UnsubscribeFromNewsletterHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;

    private NewsletterSubscriberRepository $subscriberRepository;

    private PlayerRepository $playerRepository;

    private NewsletterTokenSigner $tokenSigner;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->messageBus = $container->get(MessageBusInterface::class);
        $this->subscriberRepository = $container->get(NewsletterSubscriberRepository::class);
        $this->playerRepository = $container->get(PlayerRepository::class);
        $this->tokenSigner = $container->get(NewsletterTokenSigner::class);
    }

    public function testPlayerUnsubscribes(): void
    {
        self::assertTrue($this->playerRepository->get(PlayerFixture::PLAYER_REGULAR)->newsletterEnabled);

        $token = $this->tokenSigner->generateUnsubscribeToken(
            NewsletterAudience::Player,
            PlayerFixture::PLAYER_REGULAR,
            PlayerFixture::PLAYER_REGULAR_EMAIL,
        );

        $this->messageBus->dispatch(new UnsubscribeFromNewsletter($token));

        self::assertFalse($this->playerRepository->get(PlayerFixture::PLAYER_REGULAR)->newsletterEnabled);
    }

    public function testGuestUnsubscribes(): void
    {
        $this->messageBus->dispatch(new SubscribeToNewsletter('leaving@example.com', 'en', null));

        $subscriber = $this->subscriberRepository->findByEmail('leaving@example.com');
        self::assertNotNull($subscriber);

        $confirmToken = $this->tokenSigner->generateConfirmToken(NewsletterAudience::Guest, $subscriber->id->toString(), $subscriber->email);
        $this->messageBus->dispatch(new ConfirmNewsletterSubscription($confirmToken));

        $unsubscribeToken = $this->tokenSigner->generateUnsubscribeToken(NewsletterAudience::Guest, $subscriber->id->toString(), $subscriber->email);
        $this->messageBus->dispatch(new UnsubscribeFromNewsletter($unsubscribeToken));

        $unsubscribed = $this->subscriberRepository->findByEmail('leaving@example.com');
        self::assertNotNull($unsubscribed);
        self::assertSame(NewsletterSubscriberStatus::Unsubscribed, $unsubscribed->status);
        self::assertNotNull($unsubscribed->unsubscribedAt);
    }
}
