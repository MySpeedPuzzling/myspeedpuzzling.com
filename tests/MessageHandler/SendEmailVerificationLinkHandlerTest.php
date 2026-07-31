<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Message\SendEmailVerificationLink;
use SpeedPuzzling\Web\Message\VerifyEmail;
use SpeedPuzzling\Web\MessageHandler\SendEmailVerificationLinkHandler;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\UserAccountRepository;
use SpeedPuzzling\Web\Services\EmailVerificationTokenSigner;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class SendEmailVerificationLinkHandlerTest extends KernelTestCase
{
    private const string TEMPLATE = 'emails/verify_email.html.twig';

    private SendEmailVerificationLinkHandler $handler;
    private TestMailerSpy $mailer;
    private MessageBusInterface $messageBus;
    private UserAccountRepository $userAccountRepository;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->mailer = new TestMailerSpy();
        $this->messageBus = $container->get(MessageBusInterface::class);
        $this->userAccountRepository = $container->get(UserAccountRepository::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);

        $this->handler = new SendEmailVerificationLinkHandler(
            userAccountRepository: $this->userAccountRepository,
            playerRepository: $container->get(PlayerRepository::class),
            tokenSigner: $container->get(EmailVerificationTokenSigner::class),
            mailer: $this->mailer,
            translator: $container->get(TranslatorInterface::class),
            urlGenerator: $container->get(UrlGeneratorInterface::class),
            clock: new MockClock(),
            logger: new NullLogger(),
        );
    }

    public function testSendsLinkWhoseTokenActuallyVerifiesTheAccount(): void
    {
        $this->createUserAccount('msp|sendverify1', 'send.verify.one@example.com');

        ($this->handler)(new SendEmailVerificationLink('msp|sendverify1', 'en'));

        self::assertCount(1, $this->mailer->sent);
        $email = $this->assertTemplatedEmail($this->mailer->sent[0]);
        self::assertSame(['send.verify.one@example.com'], $this->toAddresses($email));
        self::assertSame(self::TEMPLATE, $email->getHtmlTemplate());

        // The two halves must fit: the token this handler mints is the one VerifyEmail accepts
        $token = $this->tokenFromVerificationUrl($email);

        $this->messageBus->dispatch(new VerifyEmail($token));

        $userAccount = $this->userAccountRepository->findByUserId('msp|sendverify1');
        self::assertNotNull($userAccount);
        self::assertNotNull($userAccount->emailVerifiedAt);
    }

    public function testUnknownUserIdSendsNothingAndDoesNotThrow(): void
    {
        ($this->handler)(new SendEmailVerificationLink('msp|sendverify-ghost', 'en'));

        self::assertCount(0, $this->mailer->sent);
    }

    public function testAlreadyVerifiedAccountSendsNothing(): void
    {
        $userAccount = $this->createUserAccount('msp|sendverify2', 'send.verify.two@example.com');
        $userAccount->markEmailVerified(new DateTimeImmutable());
        $this->entityManager->flush();

        ($this->handler)(new SendEmailVerificationLink('msp|sendverify2', 'en'));

        self::assertCount(0, $this->mailer->sent);
    }

    public function testPlayerLocaleWinsOverFallbackLocale(): void
    {
        $this->createUserAccount('msp|sendverify3', 'send.verify.three@example.com');
        $this->createPlayer('msp|sendverify3', 'sendverify3', 'send.verify.three@example.com', locale: 'de');

        ($this->handler)(new SendEmailVerificationLink('msp|sendverify3', 'en'));

        self::assertCount(1, $this->mailer->sent);
        self::assertSame('de', $this->assertTemplatedEmail($this->mailer->sent[0])->getLocale());
    }

    public function testFallbackLocaleUsedWhenPlayerHasNoLocale(): void
    {
        $this->createUserAccount('msp|sendverify4', 'send.verify.four@example.com');
        $this->createPlayer('msp|sendverify4', 'sendverify4', 'send.verify.four@example.com', locale: null);

        ($this->handler)(new SendEmailVerificationLink('msp|sendverify4', 'cs'));

        self::assertCount(1, $this->mailer->sent);
        self::assertSame('cs', $this->assertTemplatedEmail($this->mailer->sent[0])->getLocale());
    }

    private function tokenFromVerificationUrl(TemplatedEmail $email): string
    {
        $verificationUrl = $email->getContext()['verificationUrl'] ?? null;
        self::assertIsString($verificationUrl);

        $query = parse_url($verificationUrl, PHP_URL_QUERY);
        self::assertIsString($query);

        parse_str($query, $parameters);
        $token = $parameters['token'] ?? null;
        self::assertIsString($token);
        self::assertNotSame('', $token);

        return $token;
    }

    /**
     * @return list<string>
     */
    private function toAddresses(TemplatedEmail $email): array
    {
        return array_values(array_map(
            fn(Address $address): string => $address->getAddress(),
            $email->getTo(),
        ));
    }

    private function assertTemplatedEmail(RawMessage $message): TemplatedEmail
    {
        self::assertInstanceOf(TemplatedEmail::class, $message);

        return $message;
    }

    private function createUserAccount(string $userId, string $email): UserAccount
    {
        $userAccount = new UserAccount(Uuid::uuid7(), $userId, $email, new DateTimeImmutable());

        $this->entityManager->persist($userAccount);
        $this->entityManager->flush();

        return $userAccount;
    }

    private function createPlayer(string $userId, string $code, null|string $email, null|string $locale): Player
    {
        $player = new Player(
            Uuid::uuid7(),
            $code,
            $userId,
            $email,
            null,
            new DateTimeImmutable(),
        );

        if ($locale !== null) {
            $player->changeLocale($locale);
        }

        $this->entityManager->persist($player);
        $this->entityManager->flush();

        return $player;
    }
}
