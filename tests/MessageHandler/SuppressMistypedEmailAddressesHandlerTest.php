<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\EmailAuditLog;
use SpeedPuzzling\Web\Message\SuppressMistypedEmailAddresses;
use SpeedPuzzling\Web\MessageHandler\SuppressMistypedEmailAddressesHandler;
use SpeedPuzzling\Web\Query\GetMistypedEmailAddresses;
use SpeedPuzzling\Web\Repository\EmailSuppressionRepository;
use SpeedPuzzling\Web\Value\BounceType;
use SpeedPuzzling\Web\Value\EmailSuppressionSource;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SuppressMistypedEmailAddressesHandlerTest extends KernelTestCase
{
    public function testAddressesSesCalledInvalidAreSuppressedOnceAndOtherBouncesAreLeftAlone(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);

        $this->bounced($entityManager, 'someone@gmail.coma', 'Transient/General smtp; 550 5.4.4 Invalid domain');
        $this->bounced($entityManager, 'full@example.com', 'Transient/MailboxFull smtp; 554 4.4.7 Message expired');
        $entityManager->flush();

        self::assertArrayHasKey('someone@gmail.coma', $container->get(GetMistypedEmailAddresses::class)->notSuppressed());

        $handler = $container->get(SuppressMistypedEmailAddressesHandler::class);
        $suppressed = $handler(new SuppressMistypedEmailAddresses());
        $entityManager->flush();

        self::assertContains('someone@gmail.coma', $suppressed);
        self::assertNotContains('full@example.com', $suppressed);

        $suppressions = $container->get(EmailSuppressionRepository::class);
        self::assertSame(EmailSuppressionSource::DomainNotFound, $suppressions->findByEmail('someone@gmail.coma')?->source);
        self::assertFalse($suppressions->isSuppressed('full@example.com'));

        self::assertSame([], $handler(new SuppressMistypedEmailAddresses()), 'A second run finds nothing new');
    }

    private function bounced(EntityManagerInterface $entityManager, string $recipient, string $reason): void
    {
        $log = new EmailAuditLog(Uuid::uuid7(), new DateTimeImmutable('2026-11-03T11:54:51'), $recipient, 'Your puzzles miss you', 'ses+https://default', 'content_digest_miss_you');
        $log->recordBounce(BounceType::Soft, new DateTimeImmutable('2026-11-03T11:54:52'), $reason);
        $entityManager->persist($log);
    }
}
