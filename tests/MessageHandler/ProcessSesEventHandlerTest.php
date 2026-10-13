<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\EmailAuditLog;
use SpeedPuzzling\Web\Entity\NewsletterSubscriber;
use SpeedPuzzling\Web\Message\ProcessSesEvent;
use SpeedPuzzling\Web\Message\PushNewsletterSubscriberToListmonk;
use SpeedPuzzling\Web\MessageHandler\ProcessSesEventHandler;
use SpeedPuzzling\Web\Repository\EmailSuppressionRepository;
use SpeedPuzzling\Web\Repository\NewsletterSubscriberRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\BounceType;
use SpeedPuzzling\Web\Value\ContentDigestFrequency;
use SpeedPuzzling\Web\Value\NewsletterSubscriberStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Jan's rules (2026-10-12, docs/features/mass-mailing/README.md): a complaint switches off only what was complained
 * about, a hard bounce suppresses the address, everything else is stamped on the audit row
 */
final class ProcessSesEventHandlerTest extends KernelTestCase
{
    private ProcessSesEventHandler $handler;
    private EntityManagerInterface $entityManager;
    private PlayerRepository $playerRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->handler = $container->get(ProcessSesEventHandler::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->playerRepository = $container->get(PlayerRepository::class);
    }

    public function testDeliveryOpenAndClickAreStampedOnceOnTheMessagesAuditRow(): void
    {
        $auditLog = $this->auditLog('content_digest_activity');

        $this->process($this->event('Delivery', $auditLog, ['delivery' => ['timestamp' => '2026-10-12T08:00:01.000Z']]));
        $this->process($this->event('Open', $auditLog, ['open' => ['timestamp' => '2026-10-12T09:15:00.000Z']]));
        $this->process($this->event('Open', $auditLog, ['open' => ['timestamp' => '2026-10-12T19:00:00.000Z']]));
        $this->process($this->event('Click', $auditLog, ['click' => ['timestamp' => '2026-10-12T09:16:00.000Z', 'link' => 'https://myspeedpuzzling.com/en/hub']]));

        $reloaded = $this->reloadAuditLog($auditLog);
        self::assertEquals(new DateTimeImmutable('2026-10-12T08:00:01'), $reloaded->deliveredAt);
        self::assertEquals(new DateTimeImmutable('2026-10-12T09:15:00'), $reloaded->openedAt, 'The first open counts');
        self::assertEquals(new DateTimeImmutable('2026-10-12T09:16:00'), $reloaded->clickedAt);
    }

    public function testAHardBounceSuppressesTheAddressAndASoftOneDoesNot(): void
    {
        $hard = $this->auditLog('unread_digest', 'gone@example.com');
        $this->process($this->event('Bounce', $hard, ['bounce' => [
            'bounceType' => 'Permanent',
            'bounceSubType' => 'NoEmail',
            'timestamp' => '2026-10-12T08:00:02.000Z',
            'bouncedRecipients' => [['emailAddress' => 'Gone@Example.com', 'action' => 'failed', 'status' => '5.1.1', 'diagnosticCode' => 'smtp; 550 5.1.1 user unknown']],
        ]], recipients: ['gone@example.com']));

        $soft = $this->auditLog('unread_digest', 'full@example.com');
        $this->process($this->event('Bounce', $soft, ['bounce' => [
            'bounceType' => 'Transient',
            'bounceSubType' => 'MailboxFull',
            'timestamp' => '2026-10-12T08:00:02.000Z',
            'bouncedRecipients' => [['emailAddress' => 'full@example.com']],
        ]], recipients: ['full@example.com']));

        $suppressions = self::getContainer()->get(EmailSuppressionRepository::class);
        $suppression = $suppressions->findByEmail('gone@example.com');
        self::assertNotNull($suppression);
        self::assertSame('NoEmail', $suppression->bounceSubType);
        self::assertSame('smtp; 550 5.1.1 user unknown', $suppression->diagnosticCode);
        self::assertSame('unread_digest', $suppression->emailType);
        self::assertSame(BounceType::Hard, $this->reloadAuditLog($hard)->bounceType);

        self::assertFalse($suppressions->isSuppressed('full@example.com'));
        self::assertSame(BounceType::Soft, $this->reloadAuditLog($soft)->bounceType);
    }

    public function testAComplaintSwitchesOffOnlyTheKindOfEmailComplainedAbout(): void
    {
        $player = $this->playerRepository->get(PlayerFixture::PLAYER_REGULAR);
        self::assertTrue($player->resultEmailsEnabled);
        self::assertTrue($player->emailNotificationsEnabled);
        self::assertNotSame(ContentDigestFrequency::Off, $player->contentDigestFrequency);

        $this->complain('result_review');
        $player = $this->playerRepository->get(PlayerFixture::PLAYER_REGULAR);
        self::assertFalse($player->resultEmailsEnabled);
        self::assertTrue($player->emailNotificationsEnabled);
        self::assertNotSame(ContentDigestFrequency::Off, $player->contentDigestFrequency);
        self::assertTrue($player->newsletterEnabled);

        $this->complain('unread_digest');
        self::assertFalse($this->playerRepository->get(PlayerFixture::PLAYER_REGULAR)->emailNotificationsEnabled);

        $this->complain('content_digest_miss_you');
        self::assertSame(ContentDigestFrequency::Off, $this->playerRepository->get(PlayerFixture::PLAYER_REGULAR)->contentDigestFrequency);

        // Transactional: recorded, nothing switched off
        $auditLog = $this->complain('sign_in_link');
        self::assertNotNull($this->reloadAuditLog($auditLog)->complainedAt);
        self::assertTrue($this->playerRepository->get(PlayerFixture::PLAYER_REGULAR)->newsletterEnabled);
        self::assertFalse(self::getContainer()->get(EmailSuppressionRepository::class)->isSuppressed(PlayerFixture::PLAYER_REGULAR_EMAIL));
    }

    public function testANewsletterComplaintSwitchesTheNewsletterOffForPlayersAndGuestsAndTellsListmonk(): void
    {
        $guest = new NewsletterSubscriber(Uuid::uuid7(), 'guest-reader@example.com', 'en', new DateTimeImmutable('2026-09-01'), null);
        $guest->confirm(new DateTimeImmutable('2026-09-01'));
        $this->entityManager->persist($guest);
        $this->entityManager->flush();

        foreach ([PlayerFixture::PLAYER_REGULAR_EMAIL, 'guest-reader@example.com'] as $address) {
            // Identity feedback notification of news. (Listmonk's sender): notificationType, no tags
            $this->process([
                'notificationType' => 'Complaint',
                'complaint' => ['complainedRecipients' => [['emailAddress' => $address]], 'timestamp' => '2026-10-12T10:00:00.000Z', 'complaintFeedbackType' => 'abuse'],
                'mail' => ['timestamp' => '2026-10-12T09:00:00.000Z', 'source' => 'MySpeedPuzzling <newsletter@news.myspeedpuzzling.com>', 'destination' => [$address]],
            ]);
        }

        self::assertFalse($this->playerRepository->get(PlayerFixture::PLAYER_REGULAR)->newsletterEnabled);
        $reloadedGuest = self::getContainer()->get(NewsletterSubscriberRepository::class)->findByEmail('guest-reader@example.com');
        self::assertNotNull($reloadedGuest);
        self::assertSame(NewsletterSubscriberStatus::Unsubscribed, $reloadedGuest->status);

        $async = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $async);
        $pushed = array_map(
            static fn (object $message): string => $message instanceof PushNewsletterSubscriberToListmonk ? $message->email : '',
            array_map(static fn ($envelope): object => $envelope->getMessage(), $async->getSent()),
        );
        self::assertContains(PlayerFixture::PLAYER_REGULAR_EMAIL, $pushed);
        self::assertContains('guest-reader@example.com', $pushed);
    }

    public function testAnEventOfAPrunedAuditRowOrAnUnreadableOneChangesNothing(): void
    {
        $this->expectNotToPerformAssertions();

        $this->process([
            'eventType' => 'Delivery',
            'delivery' => ['timestamp' => '2026-10-12T08:00:01.000Z'],
            'mail' => ['timestamp' => '2026-10-12T08:00:00.000Z', 'destination' => ['someone@example.com'], 'tags' => ['audit_id' => [Uuid::uuid7()->toString()]]],
        ]);

        $this->handler->__invoke(new ProcessSesEvent('{"no": "type"}', 'sns-unreadable'));
    }

    private function complain(string $emailType): EmailAuditLog
    {
        $auditLog = $this->auditLog($emailType, PlayerFixture::PLAYER_REGULAR_EMAIL);

        $this->process($this->event('Complaint', $auditLog, ['complaint' => [
            'complainedRecipients' => [['emailAddress' => PlayerFixture::PLAYER_REGULAR_EMAIL]],
            'timestamp' => '2026-10-12T10:00:00.000Z',
        ]], recipients: [PlayerFixture::PLAYER_REGULAR_EMAIL]));

        return $auditLog;
    }

    private function auditLog(string $emailType, string $recipient = 'reader@example.com'): EmailAuditLog
    {
        $auditLog = new EmailAuditLog(Uuid::uuid7(), new DateTimeImmutable('2026-10-12T08:00:00'), $recipient, 'Subject', 'smtp://email-smtp.eu-central-1.amazonaws.com:587', $emailType);
        $this->entityManager->persist($auditLog);
        $this->entityManager->flush();

        return $auditLog;
    }

    /**
     * @param array<string, mixed> $detail
     * @param list<string> $recipients
     *
     * @return array<string, mixed>
     */
    private function event(string $type, EmailAuditLog $auditLog, array $detail, array $recipients = ['reader@example.com']): array
    {
        return ['eventType' => $type] + $detail + [
            'mail' => [
                'timestamp' => '2026-10-12T08:00:00.000Z',
                'source' => 'MySpeedPuzzling <notify@notify.myspeedpuzzling.com>',
                'destination' => $recipients,
                'tags' => [
                    'ses:configuration-set' => ['notifications'],
                    'audit_id' => [$auditLog->id->toString()],
                    'email_type' => [$auditLog->emailType],
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $event
     */
    private function process(array $event): void
    {
        $this->handler->__invoke(new ProcessSesEvent(json_encode($event, JSON_THROW_ON_ERROR), 'sns-' . bin2hex(random_bytes(4))));
        $this->entityManager->flush();
    }

    private function reloadAuditLog(EmailAuditLog $auditLog): EmailAuditLog
    {
        $this->entityManager->refresh($auditLog);

        return $auditLog;
    }
}
