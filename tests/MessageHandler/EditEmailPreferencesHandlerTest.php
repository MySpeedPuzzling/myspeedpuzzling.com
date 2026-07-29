<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use SpeedPuzzling\Web\Message\EditEmailPreferences;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\EmailNotificationFrequency;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class EditEmailPreferencesHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private PlayerRepository $playerRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->messageBus = $container->get(MessageBusInterface::class);
        $this->playerRepository = $container->get(PlayerRepository::class);
    }

    public function testDisableNewsletterAndNotifications(): void
    {
        $this->messageBus->dispatch(
            new EditEmailPreferences(
                playerId: PlayerFixture::PLAYER_REGULAR,
                newsletterEnabled: false,
                emailNotificationsEnabled: false,
                emailNotificationFrequency: EmailNotificationFrequency::TwentyFourHours,
            ),
        );

        $player = $this->playerRepository->get(PlayerFixture::PLAYER_REGULAR);

        self::assertFalse($player->newsletterEnabled);
        self::assertFalse($player->emailNotificationsEnabled);
    }

    public function testChangeFrequency(): void
    {
        $this->messageBus->dispatch(
            new EditEmailPreferences(
                playerId: PlayerFixture::PLAYER_REGULAR,
                newsletterEnabled: true,
                emailNotificationsEnabled: true,
                emailNotificationFrequency: EmailNotificationFrequency::OneWeek,
            ),
        );

        $player = $this->playerRepository->get(PlayerFixture::PLAYER_REGULAR);

        self::assertTrue($player->newsletterEnabled);
        self::assertTrue($player->emailNotificationsEnabled);
        self::assertSame(EmailNotificationFrequency::OneWeek, $player->emailNotificationFrequency);
    }

    public function testDirectMessagesSettingIsUntouched(): void
    {
        $before = $this->playerRepository->get(PlayerFixture::PLAYER_REGULAR)->allowDirectMessages;

        $this->messageBus->dispatch(
            new EditEmailPreferences(
                playerId: PlayerFixture::PLAYER_REGULAR,
                newsletterEnabled: false,
                emailNotificationsEnabled: false,
                emailNotificationFrequency: EmailNotificationFrequency::SixHours,
            ),
        );

        self::assertSame($before, $this->playerRepository->get(PlayerFixture::PLAYER_REGULAR)->allowDirectMessages);
    }
}
