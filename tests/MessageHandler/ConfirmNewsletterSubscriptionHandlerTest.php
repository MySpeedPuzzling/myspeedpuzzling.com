<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use SpeedPuzzling\Web\Exceptions\InvalidNewsletterToken;
use SpeedPuzzling\Web\Message\ConfirmNewsletterSubscription;
use SpeedPuzzling\Web\Message\EditMessagingSettings;
use SpeedPuzzling\Web\Message\SubscribeToNewsletter;
use SpeedPuzzling\Web\Repository\NewsletterSubscriberRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\NewsletterTokenSigner;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\EmailNotificationFrequency;
use SpeedPuzzling\Web\Value\NewsletterAudience;
use SpeedPuzzling\Web\Value\NewsletterSubscriberStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

final class ConfirmNewsletterSubscriptionHandlerTest extends KernelTestCase
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

    public function testGuestConfirmation(): void
    {
        $this->messageBus->dispatch(new SubscribeToNewsletter('confirm-me@example.com', 'en', null));

        $subscriber = $this->subscriberRepository->findByEmail('confirm-me@example.com');
        self::assertNotNull($subscriber);

        $token = $this->tokenSigner->generateConfirmToken(NewsletterAudience::Guest, $subscriber->id->toString(), $subscriber->email);

        $this->messageBus->dispatch(new ConfirmNewsletterSubscription($token));

        $confirmed = $this->subscriberRepository->findByEmail('confirm-me@example.com');
        self::assertNotNull($confirmed);
        self::assertSame(NewsletterSubscriberStatus::Confirmed, $confirmed->status);
        self::assertNotNull($confirmed->confirmedAt);
    }

    public function testPlayerConfirmationReenablesNewsletter(): void
    {
        $this->messageBus->dispatch(new EditMessagingSettings(
            playerId: PlayerFixture::PLAYER_REGULAR,
            allowDirectMessages: true,
            emailNotificationsEnabled: true,
            emailNotificationFrequency: EmailNotificationFrequency::TwentyFourHours,
            newsletterEnabled: false,
        ));

        self::assertFalse($this->playerRepository->get(PlayerFixture::PLAYER_REGULAR)->newsletterEnabled);

        $token = $this->tokenSigner->generateConfirmToken(
            NewsletterAudience::Player,
            PlayerFixture::PLAYER_REGULAR,
            PlayerFixture::PLAYER_REGULAR_EMAIL,
        );

        $this->messageBus->dispatch(new ConfirmNewsletterSubscription($token));

        self::assertTrue($this->playerRepository->get(PlayerFixture::PLAYER_REGULAR)->newsletterEnabled);
    }

    public function testTokenForChangedEmailIsRejected(): void
    {
        $token = $this->tokenSigner->generateConfirmToken(
            NewsletterAudience::Player,
            PlayerFixture::PLAYER_REGULAR,
            'no-longer-this-address@example.com',
        );

        try {
            $this->messageBus->dispatch(new ConfirmNewsletterSubscription($token));
            self::fail('Expected the confirmation to be rejected');
        } catch (HandlerFailedException $exception) {
            self::assertInstanceOf(InvalidNewsletterToken::class, $exception->getPrevious());
        }
    }
}
