<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use SpeedPuzzling\Web\Message\EditMessagingSettings;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\EmailNotificationFrequency;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class EditMessagingSettingsHandlerTest extends KernelTestCase
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

    public function testDisableDirectMessages(): void
    {
        $this->messageBus->dispatch(
            new EditMessagingSettings(
                playerId: PlayerFixture::PLAYER_REGULAR,
                allowDirectMessages: false,
                emailNotificationsEnabled: true,
                emailNotificationFrequency: EmailNotificationFrequency::TwentyFourHours,
                newsletterEnabled: true,
            ),
        );

        $player = $this->playerRepository->get(PlayerFixture::PLAYER_REGULAR);

        self::assertFalse($player->allowDirectMessages);
        self::assertTrue($player->emailNotificationsEnabled);
    }

    public function testDisableEmailNotifications(): void
    {
        $this->messageBus->dispatch(
            new EditMessagingSettings(
                playerId: PlayerFixture::PLAYER_REGULAR,
                allowDirectMessages: true,
                emailNotificationsEnabled: false,
                emailNotificationFrequency: EmailNotificationFrequency::TwentyFourHours,
                newsletterEnabled: true,
            ),
        );

        $player = $this->playerRepository->get(PlayerFixture::PLAYER_REGULAR);

        self::assertTrue($player->allowDirectMessages);
        self::assertFalse($player->emailNotificationsEnabled);
    }

    public function testDisableBothSettings(): void
    {
        $this->messageBus->dispatch(
            new EditMessagingSettings(
                playerId: PlayerFixture::PLAYER_REGULAR,
                allowDirectMessages: false,
                emailNotificationsEnabled: false,
                emailNotificationFrequency: EmailNotificationFrequency::TwentyFourHours,
                newsletterEnabled: true,
            ),
        );

        $player = $this->playerRepository->get(PlayerFixture::PLAYER_REGULAR);

        self::assertFalse($player->allowDirectMessages);
        self::assertFalse($player->emailNotificationsEnabled);
    }

    public function testChangeEmailNotificationFrequency(): void
    {
        $this->messageBus->dispatch(
            new EditMessagingSettings(
                playerId: PlayerFixture::PLAYER_REGULAR,
                allowDirectMessages: true,
                emailNotificationsEnabled: true,
                emailNotificationFrequency: EmailNotificationFrequency::SixHours,
                newsletterEnabled: false,
            ),
        );

        $player = $this->playerRepository->get(PlayerFixture::PLAYER_REGULAR);

        self::assertTrue($player->allowDirectMessages);
        self::assertTrue($player->emailNotificationsEnabled);
        self::assertSame(EmailNotificationFrequency::SixHours, $player->emailNotificationFrequency);
        self::assertFalse($player->newsletterEnabled);
    }
}
