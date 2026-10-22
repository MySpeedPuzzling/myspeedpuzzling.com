<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use SpeedPuzzling\Web\Tests\TestClock;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\RemindPartnerListings;
use SpeedPuzzling\Web\Message\SendPartnerListingReminder;
use SpeedPuzzling\Web\MessageHandler\SendPartnerListingReminderHandler;
use SpeedPuzzling\Web\Query\CountNewPartnerListings;
use SpeedPuzzling\Web\Repository\PartnerListingRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\EmailPreferencesLinkGenerator;
use SpeedPuzzling\Web\Services\PartnerFinder\PartnerFinderEmailsUnsubscribeUrl;
use SpeedPuzzling\Web\Services\PartnerFinder\PartnerFinderUrls;
use SpeedPuzzling\Web\Services\PlayerAccountEmail;
use SpeedPuzzling\Web\Tests\DataFixtures\PartnerFinderFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Still looking?" two weeks before the event (docs/features/partner-finder.md "Phase 2 as built"): once per open
 * listing made before the two weeks started, the e-mail counting who listed since.
 */
final class RemindPartnerListingsTest extends KernelTestCase
{
    private Connection $database;
    private MessageBusInterface $messageBus;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->database = self::getContainer()->get(Connection::class);
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
    }

    public function testListingsMadeBeforeTheTwoWeeksAreRemindedOnce(): void
    {
        $today = $this->today();
        // The event in 10 days, so the two weeks started 4 days ago: John listed before, Michael 2 days ago - he has just looked
        $this->database->executeStatement('UPDATE competition SET date_from = :from, date_to = NULL WHERE id = :id', [
            'from' => $today->modify('+10 days')->format('Y-m-d 09:00:00'),
            'id' => PartnerFinderFixture::COMPETITION_PARTNER_FINDER_OPEN,
        ]);
        $this->setCreatedAt(PartnerFinderFixture::LISTING_REGULAR_PAIR, $today->modify('-20 days'));
        $this->setCreatedAt(PartnerFinderFixture::LISTING_FAVORITES_TEAM, $today->modify('-2 days'));

        self::assertSame(1, $this->remind());
        self::assertNotNull($this->remindedAt(PartnerFinderFixture::LISTING_REGULAR_PAIR));
        self::assertNull($this->remindedAt(PartnerFinderFixture::LISTING_FAVORITES_TEAM));
        self::assertSame([PartnerFinderFixture::LISTING_REGULAR_PAIR], $this->queuedReminders());

        // Once per listing
        self::assertSame(0, $this->remind());
    }

    public function testNothingWhileTheEventIsMoreThanTwoWeeksAway(): void
    {
        // The fixture event is 35 days away
        $this->setCreatedAt(PartnerFinderFixture::LISTING_REGULAR_PAIR, $this->today()->modify('-60 days'));

        self::assertSame(0, $this->remind());
    }

    public function testTheEMailCountsWhoListedSinceAndRenders(): void
    {
        // Michael listed after John (fixture: John 4 days ago, Michael 3 days ago)
        $mailer = new TestMailerSpy();
        $this->handler($mailer)(new SendPartnerListingReminder(PartnerFinderFixture::LISTING_REGULAR_PAIR));

        self::assertCount(1, $mailer->sent);
        $email = $mailer->sent[0];
        self::assertInstanceOf(TemplatedEmail::class, $email);
        self::assertSame(PlayerFixture::PLAYER_REGULAR_EMAIL, $email->getTo()[0]->getAddress());
        self::assertSame('emails/partner_reminder.html.twig', $email->getHtmlTemplate());
        self::assertSame('notifications', $email->getHeaders()->get('X-Transport')?->getBodyAsString());
        self::assertSame('List-Unsubscribe=One-Click', $email->getHeaders()->get('List-Unsubscribe-Post')?->getBodyAsString());
        self::assertSame(1, $email->getContext()['newListings']);

        self::getContainer()->get('twig.mime_body_renderer')->render($email);
        $html = (string) $email->getHtmlBody();
        self::assertStringContainsString('Partner Finder Open', $html);
        self::assertStringContainsString('1 puzzler has listed since you did', $html);
        self::assertStringContainsString(PartnerFinderFixture::COMPETITION_PARTNER_FINDER_OPEN_SLUG, $html);
    }

    public function testNothingIsSentToAPlayerWhoSwitchedTheseEMailsOffOrForAClosedListing(): void
    {
        self::getContainer()->get(PlayerRepository::class)->get(PlayerFixture::PLAYER_REGULAR)->changePartnerFinderEmails(false);
        $mailer = new TestMailerSpy();
        $this->handler($mailer)(new SendPartnerListingReminder(PartnerFinderFixture::LISTING_REGULAR_PAIR));
        $this->handler($mailer)(new SendPartnerListingReminder(PartnerFinderFixture::LISTING_STRIPE_CLOSED));

        self::assertCount(0, $mailer->sent);
    }

    public function testTheCountLeavesOutBlockedPuzzlers(): void
    {
        $count = self::getContainer()->get(CountNewPartnerListings::class);
        $listing = self::getContainer()->get(PartnerListingRepository::class)->get(PartnerFinderFixture::LISTING_REGULAR_PAIR);

        self::assertSame(1, $count->since($listing));

        $this->database->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, " . TestClock::SQL_NOW . ", 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => PlayerFixture::PLAYER_WITH_FAVORITES, 'blocked' => PlayerFixture::PLAYER_REGULAR],
        );

        self::assertSame(0, $count->since($listing));
    }

    private function remind(): int
    {
        // Through the bus: its transaction saves the marks, the e-mails queue after it
        $handled = $this->messageBus->dispatch(new RemindPartnerListings())->last(HandledStamp::class);
        self::assertNotNull($handled);
        $reminded = $handled->getResult();
        self::assertIsInt($reminded);

        return $reminded;
    }

    private function handler(TestMailerSpy $mailer): SendPartnerListingReminderHandler
    {
        $container = self::getContainer();

        return new SendPartnerListingReminderHandler(
            partnerListingRepository: $container->get(PartnerListingRepository::class),
            countNewPartnerListings: $container->get(CountNewPartnerListings::class),
            playerAccountEmail: $container->get(PlayerAccountEmail::class),
            emailPreferencesLinkGenerator: $container->get(EmailPreferencesLinkGenerator::class),
            unsubscribeUrl: $container->get(PartnerFinderEmailsUnsubscribeUrl::class),
            partnerFinderUrls: $container->get(PartnerFinderUrls::class),
            mailer: $mailer,
            translator: $container->get(TranslatorInterface::class),
        );
    }

    /**
     * @return list<string>
     */
    private function queuedReminders(): array
    {
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        $ids = [];

        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();

            if ($message instanceof SendPartnerListingReminder) {
                $ids[] = $message->listingId;
            }
        }

        return $ids;
    }

    private function today(): DateTimeImmutable
    {
        return self::getContainer()->get(ClockInterface::class)->now()->setTime(0, 0);
    }

    private function setCreatedAt(string $listingId, DateTimeImmutable $createdAt): void
    {
        $this->database->executeStatement('UPDATE partner_listing SET created_at = :at WHERE id = :id', [
            'at' => $createdAt->format('Y-m-d H:i:s'),
            'id' => $listingId,
        ]);
    }

    private function remindedAt(string $listingId): mixed
    {
        return $this->database->fetchOne('SELECT reminded_at FROM partner_listing WHERE id = :id', ['id' => $listingId]);
    }
}
