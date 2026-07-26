<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use SpeedPuzzling\Web\Message\SubscribeToNewsletter;
use SpeedPuzzling\Web\Repository\NewsletterSubscriberRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\NewsletterSubscriberStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class SubscribeToNewsletterHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;

    private NewsletterSubscriberRepository $subscriberRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->messageBus = $container->get(MessageBusInterface::class);
        $this->subscriberRepository = $container->get(NewsletterSubscriberRepository::class);
    }

    public function testGuestSignupCreatesPendingSubscriber(): void
    {
        $this->messageBus->dispatch(new SubscribeToNewsletter(
            email: 'Fresh.Guest@Example.com',
            locale: 'cs',
            ipAddress: '203.0.113.7',
        ));

        $subscriber = $this->subscriberRepository->findByEmail('fresh.guest@example.com');

        self::assertNotNull($subscriber);
        self::assertSame(NewsletterSubscriberStatus::Pending, $subscriber->status);
        self::assertSame('cs', $subscriber->locale);
        self::assertSame('203.0.113.7', $subscriber->ipAddress);
    }

    public function testRepeatedSignupKeepsSingleRowAndRefreshesLocale(): void
    {
        $this->messageBus->dispatch(new SubscribeToNewsletter('repeat@example.com', 'cs', null));
        $first = $this->subscriberRepository->findByEmail('repeat@example.com');
        self::assertNotNull($first);

        $this->messageBus->dispatch(new SubscribeToNewsletter('repeat@example.com', 'en', '198.51.100.1'));
        $second = $this->subscriberRepository->findByEmail('repeat@example.com');

        self::assertNotNull($second);
        self::assertTrue($first->id->equals($second->id));
        self::assertSame('en', $second->locale);
        self::assertSame(NewsletterSubscriberStatus::Pending, $second->status);
    }

    public function testPlayerEmailDoesNotCreateGuestRow(): void
    {
        $this->messageBus->dispatch(new SubscribeToNewsletter(
            email: PlayerFixture::PLAYER_REGULAR_EMAIL,
            locale: 'en',
            ipAddress: null,
        ));

        self::assertNull($this->subscriberRepository->findByEmail(PlayerFixture::PLAYER_REGULAR_EMAIL));
    }

    public function testUnsupportedLocaleFallsBackToEnglish(): void
    {
        $this->messageBus->dispatch(new SubscribeToNewsletter('locale-fallback@example.com', 'xx', null));

        $subscriber = $this->subscriberRepository->findByEmail('locale-fallback@example.com');

        self::assertNotNull($subscriber);
        self::assertSame('en', $subscriber->locale);
    }
}
