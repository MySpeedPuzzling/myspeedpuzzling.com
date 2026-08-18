<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Message\SendAccountDeletionLink;
use SpeedPuzzling\Web\Value\AccountDeletionToken;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Mime\Email;

final class SendAccountDeletionLinkHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testMailsTheLinkAndTheExportButtonInThePlayersLocale(): void
    {
        [$userAccount, $player] = $this->seedAccountWithPlayer(locale: 'cs');
        $token = AccountDeletionToken::generate();

        $this->messageBus->dispatch(new SendAccountDeletionLink($userAccount->userId, $token->toString(), fallbackLocale: 'en'));

        $email = $this->theOnlySentEmail();
        self::assertSame($userAccount->email, $email->getTo()[0]->getAddress());
        self::assertSame('Potvrďte smazání svého účtu na MySpeedPuzzling', $email->getSubject());

        $body = (string) $email->getHtmlBody();
        self::assertStringContainsString('/delete-account/' . $token->toString(), $body);
        self::assertStringContainsString('/export-dat-hrace/' . $player->id->toString(), $body, 'Export CTA follows the player locale');
        self::assertStringContainsString('Smazat můj účet', $body);
        self::assertStringContainsString('60 minut', $body);
    }

    public function testFallsBackToTheRequestLocaleWhenThePlayerHasNone(): void
    {
        [$userAccount] = $this->seedAccountWithPlayer(locale: null);

        $this->messageBus->dispatch(new SendAccountDeletionLink($userAccount->userId, AccountDeletionToken::generate()->toString(), fallbackLocale: 'de'));

        $email = $this->theOnlySentEmail();
        self::assertSame('Bestätige die Löschung deines MySpeedPuzzling-Kontos', $email->getSubject());
    }

    public function testAnUnknownAccountGetsNoMail(): void
    {
        $this->messageBus->dispatch(new SendAccountDeletionLink('msp|' . bin2hex(random_bytes(4)), AccountDeletionToken::generate()->toString(), fallbackLocale: 'en'));

        self::assertCount(0, $this->sentEmails());
    }

    /**
     * @return array{UserAccount, Player}
     */
    private function seedAccountWithPlayer(null|string $locale): array
    {
        $userId = 'msp|' . bin2hex(random_bytes(4));
        $email = sprintf('delete.link+%s@example.com', bin2hex(random_bytes(4)));

        $userAccount = new UserAccount(Uuid::uuid7(), $userId, $email, new DateTimeImmutable());
        $player = new Player(Uuid::uuid7(), 'DEL' . bin2hex(random_bytes(2)), $userId, $email, 'Leaving Soon', new DateTimeImmutable());

        if ($locale !== null) {
            $player->changeLocale($locale);
        }

        $this->entityManager->persist($userAccount);
        $this->entityManager->persist($player);
        $this->entityManager->flush();

        return [$userAccount, $player];
    }

    /**
     * @return array<Email>
     */
    private function sentEmails(): array
    {
        return array_values(array_filter(self::getMailerMessages(), static fn ($m): bool => $m instanceof Email));
    }

    private function theOnlySentEmail(): Email
    {
        $emails = $this->sentEmails();
        self::assertCount(1, $emails);

        return $emails[0];
    }
}
