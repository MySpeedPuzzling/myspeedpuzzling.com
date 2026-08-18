<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Message\SendPasswordResetLink;
use SpeedPuzzling\Web\MessageHandler\SendPasswordResetLinkHandler;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\UserAccountRepository;
use SpeedPuzzling\Web\Value\PasswordResetToken;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class SendPasswordResetLinkHandlerTest extends KernelTestCase
{
    private const string TEMPLATE = 'emails/password_reset.html.twig';

    private SendPasswordResetLinkHandler $handler;
    private TestMailerSpy $mailer;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->mailer = new TestMailerSpy();
        $this->entityManager = $container->get(EntityManagerInterface::class);

        $this->handler = new SendPasswordResetLinkHandler(
            userAccountRepository: $container->get(UserAccountRepository::class),
            playerRepository: $container->get(PlayerRepository::class),
            mailer: $this->mailer,
            translator: $container->get(TranslatorInterface::class),
            urlGenerator: $container->get(UrlGeneratorInterface::class),
            logger: new NullLogger(),
        );
    }

    public function testSendsResetLinkCarryingTheToken(): void
    {
        $this->createUserAccount('msp|sendreset1', 'send.reset.one@example.com');
        $token = PasswordResetToken::generate();

        ($this->handler)(new SendPasswordResetLink('Send.Reset.One@EXAMPLE.com', $token->toString(), 'en'));

        self::assertCount(1, $this->mailer->sent);
        $email = $this->assertTemplatedEmail($this->mailer->sent[0]);
        self::assertSame(['send.reset.one@example.com'], $this->toAddresses($email));
        self::assertSame(self::TEMPLATE, $email->getHtmlTemplate());

        $resetUrl = $email->getContext()['resetUrl'] ?? null;
        self::assertIsString($resetUrl);
        self::assertStringContainsString($token->toString(), $resetUrl);
    }

    public function testUnknownEmailSendsNothingAndDoesNotThrow(): void
    {
        ($this->handler)(new SendPasswordResetLink(
            'nobody.reset@example.com',
            PasswordResetToken::generate()->toString(),
            'en',
        ));

        self::assertCount(0, $this->mailer->sent);
    }

    public function testPlayerLocaleWinsOverFallbackLocale(): void
    {
        $this->createUserAccount('msp|sendreset2', 'send.reset.two@example.com');
        $this->createPlayer('msp|sendreset2', 'sendreset2', 'send.reset.two@example.com', locale: 'de');

        ($this->handler)(new SendPasswordResetLink(
            'send.reset.two@example.com',
            PasswordResetToken::generate()->toString(),
            'en',
        ));

        self::assertCount(1, $this->mailer->sent);
        self::assertSame('de', $this->assertTemplatedEmail($this->mailer->sent[0])->getLocale());
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
