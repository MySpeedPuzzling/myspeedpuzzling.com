<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Membership;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Events\MembershipStarted;
use SpeedPuzzling\Web\MessageHandler\NotifyWhenMembershipStarted;
use SpeedPuzzling\Web\Repository\MembershipRepository;
use SpeedPuzzling\Web\Services\PlayerAccountEmail;
use SpeedPuzzling\Web\Value\FreeTrialSource;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class NotifyWhenMembershipStartedTest extends TestCase
{
    public function testFreeTrialGetsItsOwnEmail(): void
    {
        $membership = Membership::startFreeTrial(Uuid::uuid7(), $this->player(), new DateTimeImmutable('2026-09-20 10:00:00'), FreeTrialSource::OfferModal);

        $email = $this->emailSentFor($membership);

        self::assertSame('emails/free_trial_started.html.twig', $email->getHtmlTemplate());
        self::assertSame('30.09.2026', $email->getContext()['trialEndsAt']);
    }

    public function testGrantedMembershipEmailCarriesTheDateItRunsUntil(): void
    {
        $membership = new Membership(Uuid::uuid7(), $this->player(), new DateTimeImmutable('2026-09-20'), grantedUntil: new DateTimeImmutable('2026-12-24'));

        $email = $this->emailSentFor($membership);

        self::assertSame('emails/membership_granted.html.twig', $email->getHtmlTemplate());
        self::assertSame('24.12.2026', $email->getContext()['membershipExpiresAt']);
    }

    public function testSubscriptionEmailIsUntouched(): void
    {
        $membership = new Membership(Uuid::uuid7(), $this->player(), new DateTimeImmutable('2026-09-20'), 'sub_123', new DateTimeImmutable('2026-10-20'));

        self::assertSame('emails/membership_subscribed.html.twig', $this->emailSentFor($membership)->getHtmlTemplate());
    }

    private function emailSentFor(Membership $membership): TemplatedEmail
    {
        $sent = null;

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())->method('send')->willReturnCallback(static function (TemplatedEmail $email) use (&$sent): void {
            $sent = $email;
        });

        $membershipRepository = $this->createStub(MembershipRepository::class);
        $membershipRepository->method('get')->willReturn($membership);

        // The address comes from the account, never from the player row
        $playerAccountEmail = $this->createStub(PlayerAccountEmail::class);
        $playerAccountEmail->method('ofPlayer')->willReturn('test@example.com');

        $handler = new NotifyWhenMembershipStarted($membershipRepository, $mailer, $this->createStub(TranslatorInterface::class), $playerAccountEmail);
        $handler(new MembershipStarted($membership->id));

        self::assertInstanceOf(TemplatedEmail::class, $sent);

        return $sent;
    }

    private function player(): Player
    {
        return new Player(
            id: Uuid::uuid7(),
            code: 'testplayer',
            userId: 'auth0|test',
            email: null,
            name: 'Test Player',
            registeredAt: new DateTimeImmutable(),
        );
    }
}
