<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Membership;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Events\MembershipSubscriptionCancelled;
use SpeedPuzzling\Web\MessageHandler\NotifyWhenMembershipSubscriptionCancelled;
use SpeedPuzzling\Web\Repository\MembershipRepository;
use SpeedPuzzling\Web\Services\PlayerAccountEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class NotifyWhenMembershipSubscriptionCancelledTest extends TestCase
{
    public function testSendsEmailWhenSubscriptionIsCancelled(): void
    {
        $membership = $this->createMembership();

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())->method('send');

        $this->createHandler($membership, $mailer)(new MembershipSubscriptionCancelled($membership->id));
    }

    public function testDoesNotSendEmailToLifetimeMember(): void
    {
        // Claiming a lifetime voucher cancels the subscription on purpose
        $membership = $this->createMembership();
        $membership->grantLifetime();

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        $this->createHandler($membership, $mailer)(new MembershipSubscriptionCancelled($membership->id));
    }

    private function createHandler(Membership $membership, MailerInterface $mailer): NotifyWhenMembershipSubscriptionCancelled
    {
        $membershipRepository = $this->createStub(MembershipRepository::class);
        $membershipRepository->method('get')->willReturn($membership);

        // The address comes from the account, never from the player row
        $playerAccountEmail = $this->createStub(PlayerAccountEmail::class);
        $playerAccountEmail->method('ofPlayer')->willReturn('test@example.com');

        return new NotifyWhenMembershipSubscriptionCancelled(
            membershipRepository: $membershipRepository,
            mailer: $mailer,
            translator: $this->createStub(TranslatorInterface::class),
            playerAccountEmail: $playerAccountEmail,
        );
    }

    private function createMembership(): Membership
    {
        $player = new Player(
            id: Uuid::uuid7(),
            code: 'testplayer',
            userId: 'auth0|test',
            name: 'Test Player',
            registeredAt: new DateTimeImmutable(),
        );

        return new Membership(
            id: Uuid::uuid7(),
            player: $player,
            createdAt: new DateTimeImmutable('-30 days'),
            stripeSubscriptionId: 'sub_test_cancelled',
            endsAt: new DateTimeImmutable('+20 days'),
        );
    }
}
