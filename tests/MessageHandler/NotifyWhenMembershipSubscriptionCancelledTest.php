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

        return new NotifyWhenMembershipSubscriptionCancelled(
            membershipRepository: $membershipRepository,
            mailer: $mailer,
            translator: $this->createStub(TranslatorInterface::class),
        );
    }

    private function createMembership(): Membership
    {
        $player = new Player(
            id: Uuid::uuid7(),
            code: 'testplayer',
            userId: 'auth0|test',
            email: 'test@example.com',
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
