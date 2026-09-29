<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Events\OauthIdentityLinked;
use SpeedPuzzling\Web\MessageHandler\NotifyWhenOauthIdentityLinked;
use SpeedPuzzling\Web\Value\OauthProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mime\Email;

final class NotifyWhenOauthIdentityLinkedTest extends KernelTestCase
{
    public function testOwnerGetsTheSecurityNoticeInTheirLanguage(): void
    {
        self::bootKernel();

        $userAccount = $this->seedAccount('de');

        $this->handler()(new OauthIdentityLinked($userAccount->id, OauthProvider::Apple, new DateTimeImmutable('2026-09-29 10:15:00 UTC')));

        $messages = self::getMailerMessages();
        self::assertCount(1, $messages);
        $email = $messages[0];
        self::assertInstanceOf(Email::class, $email);

        self::assertSame($userAccount->email, $email->getTo()[0]->getAddress());
        self::assertSame('Die Anmeldung mit Apple wurde mit deinem MySpeedPuzzling-Konto verbunden', $email->getSubject());

        $body = (string) $email->getHtmlBody();
        self::assertStringContainsString('29.09.2026 10:15 UTC', $body);
        self::assertStringContainsString($userAccount->email, $body);
        // The way back: the settings page (in their language) and a password reset
        self::assertStringContainsString('/de/', $body);
        self::assertStringContainsString('/password-reset', $body);
    }

    /**
     * Auth mails exist in all six locales (D17) - a missing key would show the
     * raw translation id to exactly the person who needs to understand it.
     */
    #[DataProvider('locales')]
    public function testNoticeIsFullyTranslated(string $locale): void
    {
        self::bootKernel();

        $userAccount = $this->seedAccount($locale);

        $this->handler()(new OauthIdentityLinked($userAccount->id, OauthProvider::Google, new DateTimeImmutable()));

        $messages = self::getMailerMessages();
        self::assertCount(1, $messages);
        self::assertInstanceOf(Email::class, $messages[0]);

        self::assertStringNotContainsString('oauth_identity_linked.', (string) $messages[0]->getSubject());
        self::assertStringNotContainsString('oauth_identity_linked.', (string) $messages[0]->getHtmlBody());
        self::assertStringContainsString('Google', (string) $messages[0]->getSubject());
    }

    public function testDeletedAccountIsSkippedQuietly(): void
    {
        self::bootKernel();

        $this->handler()(new OauthIdentityLinked(Uuid::uuid7(), OauthProvider::Google, new DateTimeImmutable()));

        self::assertCount(0, self::getMailerMessages());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function locales(): iterable
    {
        foreach (['en', 'cs', 'de', 'es', 'fr', 'ja'] as $locale) {
            yield $locale => [$locale];
        }
    }

    private function handler(): NotifyWhenOauthIdentityLinked
    {
        return self::getContainer()->get(NotifyWhenOauthIdentityLinked::class);
    }

    private function seedAccount(string $locale): UserAccount
    {
        $userId = 'msp|' . Uuid::uuid7()->toString();
        $email = sprintf('notice+%s@example.com', bin2hex(random_bytes(4)));

        $userAccount = new UserAccount(Uuid::uuid7(), $userId, $email, new DateTimeImmutable());
        $player = new Player(Uuid::uuid7(), 'NT' . bin2hex(random_bytes(3)), $userId, null, new DateTimeImmutable());
        $player->changeLocale($locale);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($userAccount);
        $entityManager->persist($player);
        $entityManager->flush();

        return $userAccount;
    }
}
