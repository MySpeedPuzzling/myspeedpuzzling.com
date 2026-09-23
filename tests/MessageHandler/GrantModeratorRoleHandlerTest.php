<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use SpeedPuzzling\Web\Message\GrantModeratorRole;
use SpeedPuzzling\Web\Message\RevokeModeratorRole;
use SpeedPuzzling\Web\Query\GetNotifications;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\NotificationType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class GrantModeratorRoleHandlerTest extends KernelTestCase
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

    public function testPlayerBecomesModerator(): void
    {
        $player = $this->playerRepository->get(PlayerFixture::PLAYER_REGULAR);
        self::assertFalse($player->isModerator());

        $this->messageBus->dispatch(new GrantModeratorRole(PlayerFixture::PLAYER_REGULAR));

        $player = $this->playerRepository->get(PlayerFixture::PLAYER_REGULAR);
        self::assertTrue($player->isModerator());
        self::assertFalse($player->isAdmin, 'The moderator role must never make anyone an admin');
    }

    public function testGrantingTwiceKeepsTheOriginalDate(): void
    {
        $this->messageBus->dispatch(new GrantModeratorRole(PlayerFixture::PLAYER_REGULAR));
        $since = $this->playerRepository->get(PlayerFixture::PLAYER_REGULAR)->moderatorSince;

        $this->messageBus->dispatch(new GrantModeratorRole(PlayerFixture::PLAYER_REGULAR));

        self::assertSame($since, $this->playerRepository->get(PlayerFixture::PLAYER_REGULAR)->moderatorSince);
    }

    public function testRoleCanBeRevoked(): void
    {
        $this->messageBus->dispatch(new GrantModeratorRole(PlayerFixture::PLAYER_REGULAR));
        $this->messageBus->dispatch(new RevokeModeratorRole(PlayerFixture::PLAYER_REGULAR));

        $player = $this->playerRepository->get(PlayerFixture::PLAYER_REGULAR);
        self::assertFalse($player->isModerator());
        self::assertNull($player->moderatorSince);
    }

    public function testNewModeratorIsNotifiedInAppAndByEmail(): void
    {
        $this->messageBus->dispatch(new GrantModeratorRole(PlayerFixture::PLAYER_REGULAR));

        self::assertContains(NotificationType::ModeratorRoleGranted, $this->notificationTypes());

        self::assertQueuedEmailCount(1);
        $email = self::getMailerMessage();
        self::assertNotNull($email);
        self::assertEmailAddressContains($email, 'To', PlayerFixture::PLAYER_REGULAR_EMAIL);
        self::assertEmailHtmlBodyContains($email, '/admin/puzzle-change-requests');
        self::assertEmailHtmlBodyContains($email, '/admin/puzzle-merge-requests');
    }

    public function testGrantingTwiceWelcomesOnlyOnce(): void
    {
        $this->messageBus->dispatch(new GrantModeratorRole(PlayerFixture::PLAYER_REGULAR));
        $this->messageBus->dispatch(new GrantModeratorRole(PlayerFixture::PLAYER_REGULAR));

        self::assertQueuedEmailCount(1);
        self::assertCount(1, array_filter(
            $this->notificationTypes(),
            static fn (null|NotificationType $type): bool => $type === NotificationType::ModeratorRoleGranted,
        ));
    }

    public function testRevocationIsNotifiedInAppOnly(): void
    {
        $this->messageBus->dispatch(new GrantModeratorRole(PlayerFixture::PLAYER_REGULAR));
        $this->messageBus->dispatch(new RevokeModeratorRole(PlayerFixture::PLAYER_REGULAR));

        self::assertContains(NotificationType::ModeratorRoleRevoked, $this->notificationTypes());
        self::assertQueuedEmailCount(1, message: 'Only the welcome e-mail - a revocation sends none');
    }

    public function testRevokingSomeoneWhoIsNotAModeratorNotifiesNobody(): void
    {
        $this->messageBus->dispatch(new RevokeModeratorRole(PlayerFixture::PLAYER_REGULAR));

        self::assertNotContains(NotificationType::ModeratorRoleRevoked, $this->notificationTypes());
    }

    /**
     * Read through GetNotifications on purpose: a notification type without its
     * own branch in that query is stored but never shown.
     *
     * @return list<null|NotificationType>
     */
    private function notificationTypes(): array
    {
        $notifications = self::getContainer()->get(GetNotifications::class)->forPlayer(PlayerFixture::PLAYER_REGULAR, 100);

        return array_values(array_map(static fn ($notification): null|NotificationType => $notification->notificationType, $notifications));
    }
}
