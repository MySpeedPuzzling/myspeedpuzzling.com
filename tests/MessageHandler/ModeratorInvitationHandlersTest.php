<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Exceptions\ModeratorInvitationNotFound;
use SpeedPuzzling\Web\Message\AcceptModeratorInvitation;
use SpeedPuzzling\Web\Message\DeclineModeratorInvitation;
use SpeedPuzzling\Web\Message\GrantModeratorRole;
use SpeedPuzzling\Web\Message\InviteModerator;
use SpeedPuzzling\Web\Message\WithdrawModeratorInvitation;
use SpeedPuzzling\Web\Query\GetModeratorInvitations;
use SpeedPuzzling\Web\Query\GetNotifications;
use SpeedPuzzling\Web\Repository\ModeratorInvitationRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\Moderators\ModeratorGuide;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\NotificationType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Becoming a moderator: an admin invites, the player agrees to the moderator guide, only then the role
 * (docs/features/community-moderators.md)
 */
final class ModeratorInvitationHandlersTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private PlayerRepository $playerRepository;
    private ModeratorInvitationRepository $invitationRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->messageBus = $container->get(MessageBusInterface::class);
        $this->playerRepository = $container->get(PlayerRepository::class);
        $this->invitationRepository = $container->get(ModeratorInvitationRepository::class);
    }

    public function testInvitationNotifiesAndMailsButGrantsNothing(): void
    {
        $this->invite(PlayerFixture::PLAYER_REGULAR);

        self::assertFalse($this->playerRepository->get(PlayerFixture::PLAYER_REGULAR)->isModerator());
        self::assertTrue(self::getContainer()->get(GetModeratorInvitations::class)->hasPending(PlayerFixture::PLAYER_REGULAR));
        self::assertContains(NotificationType::ModeratorInvitationReceived, $this->notificationTypes());

        self::assertQueuedEmailCount(1);
        $email = self::getMailerMessage();
        self::assertNotNull($email);
        self::assertEmailAddressContains($email, 'To', PlayerFixture::PLAYER_REGULAR_EMAIL);
        self::assertEmailHtmlBodyContains($email, '/en/moderator-guide');
    }

    public function testAnOpenInvitationIsNotSentTwice(): void
    {
        $this->invite(PlayerFixture::PLAYER_REGULAR);
        $this->invite(PlayerFixture::PLAYER_REGULAR);

        self::assertQueuedEmailCount(1);
        self::assertCount(1, array_filter(
            $this->notificationTypes(),
            static fn (null|NotificationType $type): bool => $type === NotificationType::ModeratorInvitationReceived,
        ));
    }

    public function testAModeratorIsNotInvited(): void
    {
        $this->messageBus->dispatch(new GrantModeratorRole(PlayerFixture::PLAYER_REGULAR));
        $this->invite(PlayerFixture::PLAYER_REGULAR);

        self::assertNull($this->invitationRepository->findPendingForPlayer(PlayerFixture::PLAYER_REGULAR));
    }

    public function testAcceptingRecordsTheAgreementAndGrantsTheRole(): void
    {
        $invitationId = $this->invite(PlayerFixture::PLAYER_REGULAR);

        $this->messageBus->dispatch(new AcceptModeratorInvitation(PlayerFixture::PLAYER_REGULAR));

        $player = $this->playerRepository->get(PlayerFixture::PLAYER_REGULAR);
        self::assertTrue($player->isModerator());
        self::assertFalse($player->isAdmin);

        $invitation = $this->invitationRepository->get($invitationId->toString());
        self::assertTrue($invitation->accepted);
        self::assertNotNull($invitation->answeredAt);
        self::assertSame(ModeratorGuide::VERSION, $invitation->agreedGuideVersion);

        self::assertContains(NotificationType::ModeratorRoleGranted, $this->notificationTypes());
        self::assertQueuedEmailCount(2, message: 'The invitation, then the welcome');
        $welcome = self::getMailerMessage(1);
        self::assertNotNull($welcome);
        self::assertEmailHtmlBodyContains($welcome, '/admin/puzzle-change-requests');
        self::assertEmailHtmlBodyContains($welcome, '/en/moderator-guide');
    }

    public function testNobodyAcceptsWithoutAnInvitation(): void
    {
        try {
            $this->messageBus->dispatch(new AcceptModeratorInvitation(PlayerFixture::PLAYER_REGULAR));
            self::fail('Accepting without an invitation must fail');
        } catch (ModeratorInvitationNotFound) {
        }

        self::assertFalse($this->playerRepository->get(PlayerFixture::PLAYER_REGULAR)->isModerator());
    }

    public function testDecliningKeepsThePlayerAsTheyWereAndTheyCanBeAskedAgain(): void
    {
        $invitationId = $this->invite(PlayerFixture::PLAYER_REGULAR);

        $this->messageBus->dispatch(new DeclineModeratorInvitation(PlayerFixture::PLAYER_REGULAR));

        self::assertFalse($this->playerRepository->get(PlayerFixture::PLAYER_REGULAR)->isModerator());
        self::assertFalse($this->invitationRepository->get($invitationId->toString())->accepted);
        self::assertNull($this->invitationRepository->findPendingForPlayer(PlayerFixture::PLAYER_REGULAR));

        $this->invite(PlayerFixture::PLAYER_REGULAR);
        self::assertNotNull($this->invitationRepository->findPendingForPlayer(PlayerFixture::PLAYER_REGULAR));
    }

    public function testAWithdrawnInvitationCannotBeAccepted(): void
    {
        $invitationId = $this->invite(PlayerFixture::PLAYER_REGULAR);

        $this->messageBus->dispatch(new WithdrawModeratorInvitation($invitationId->toString()));

        self::assertNotNull($this->invitationRepository->get($invitationId->toString())->withdrawnAt);

        $this->expectException(ModeratorInvitationNotFound::class);
        $this->messageBus->dispatch(new AcceptModeratorInvitation(PlayerFixture::PLAYER_REGULAR));
    }

    private function invite(string $playerId): UuidInterface
    {
        $invitationId = Uuid::uuid7();
        $this->messageBus->dispatch(new InviteModerator($invitationId, $playerId, PlayerFixture::PLAYER_ADMIN));

        return $invitationId;
    }

    /**
     * Read through GetNotifications on purpose: a notification type without its own branch in that query is stored
     * but never shown.
     *
     * @return list<null|NotificationType>
     */
    private function notificationTypes(): array
    {
        $notifications = self::getContainer()->get(GetNotifications::class)->forPlayer(PlayerFixture::PLAYER_REGULAR, 100);

        return array_values(array_map(static fn ($notification): null|NotificationType => $notification->notificationType, $notifications));
    }
}
