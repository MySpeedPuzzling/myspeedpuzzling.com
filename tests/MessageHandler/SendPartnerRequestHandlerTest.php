<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\UserBlock;
use SpeedPuzzling\Web\Exceptions\PartnerRequestLimitReached;
use SpeedPuzzling\Web\Exceptions\PartnerRequestNotPossible;
use SpeedPuzzling\Web\Message\SendPartnerRequest;
use SpeedPuzzling\Web\Message\SendPartnerRequestEmail;
use SpeedPuzzling\Web\Query\GetCompetitionParticipants;
use SpeedPuzzling\Web\Query\GetNotifications;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\PartnerFinderFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\PartnerKind;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * "I'd like to puzzle with you" (docs/features/partner-finder.md D6): checked before anything changes, silent for a
 * duplicate and for a block either way, limited per sender.
 */
final class SendPartnerRequestHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->connection = self::getContainer()->get(Connection::class);
    }

    public function testRequestIsSavedTheListedPuzzlerNotifiedAndTheSenderMadeGoing(): void
    {
        $requestId = Uuid::uuid7()->toString();

        self::assertSame([], $this->connections(PlayerFixture::PLAYER_ADMIN));

        $this->send(PlayerFixture::PLAYER_ADMIN, PartnerFinderFixture::LISTING_REGULAR_PAIR, PartnerKind::Pair, 'I sort fast!', $requestId);

        $request = $this->connection->fetchAssociative('SELECT listing_id, from_player_id, kind, note FROM partner_request WHERE id = :id', ['id' => $requestId]);
        self::assertSame([
            'listing_id' => PartnerFinderFixture::LISTING_REGULAR_PAIR,
            'from_player_id' => PlayerFixture::PLAYER_ADMIN,
            'kind' => 'pair',
            'note' => 'I sort fast!',
        ], $request);

        $notification = $this->connection->fetchAssociative(
            'SELECT player_id, type, actor_player_id FROM notification WHERE target_partner_request_id = :id',
            ['id' => $requestId],
        );
        self::assertSame([
            'player_id' => PlayerFixture::PLAYER_REGULAR,
            'type' => 'PartnerRequest',
            'actor_player_id' => PlayerFixture::PLAYER_ADMIN,
        ], $notification);

        self::assertSame([$requestId], $this->queuedEmails());
        self::assertCount(1, $this->connections(PlayerFixture::PLAYER_ADMIN), 'Asking marks the sender going (plain event)');

        // The listed puzzler's notifications list shows it, naming who asks and the event
        $rows = array_values(array_filter(
            self::getContainer()->get(GetNotifications::class)->forPlayer(PlayerFixture::PLAYER_REGULAR, 20),
            static fn ($row): bool => $row->isPartnerRequestNotification(),
        ));
        self::assertCount(1, $rows);
        self::assertSame($requestId, $rows[0]->partnerRequestId);
        self::assertSame(PlayerFixture::PLAYER_ADMIN, $rows[0]->targetPlayerId);
        self::assertSame(PartnerKind::Pair, $rows[0]->partnerRequestKind);
        self::assertSame('I sort fast!', $rows[0]->partnerRequestNote);
        self::assertSame('Partner Finder Open', $rows[0]->partnerCompetitionName);
        self::assertSame(PartnerFinderFixture::COMPETITION_PARTNER_FINDER_OPEN_SLUG, $rows[0]->partnerCompetitionSlug);
        self::assertFalse($rows[0]->partnerRequestReplied);
    }

    public function testAskingAgainChangesNothing(): void
    {
        // PLAYER_WITH_STRIPE asked LISTING_REGULAR_PAIR already
        $this->send(PlayerFixture::PLAYER_WITH_STRIPE, PartnerFinderFixture::LISTING_REGULAR_PAIR, PartnerKind::Pair, 'Again!');

        self::assertSame(1, $this->requestCount(PlayerFixture::PLAYER_WITH_STRIPE));
        self::assertSame(0, $this->notificationCount());
        self::assertSame([], $this->queuedEmails());
        self::assertSame([], $this->connections(PlayerFixture::PLAYER_WITH_STRIPE), 'Not made going either');
    }

    public function testOwnListingCannotBeAsked(): void
    {
        $this->expectException(PartnerRequestNotPossible::class);

        $this->send(PlayerFixture::PLAYER_REGULAR, PartnerFinderFixture::LISTING_REGULAR_PAIR, PartnerKind::Pair);
    }

    public function testClosedListingCannotBeAsked(): void
    {
        try {
            $this->send(PlayerFixture::PLAYER_ADMIN, PartnerFinderFixture::LISTING_STRIPE_CLOSED, PartnerKind::Pair);
            self::fail('A closed listing must refuse the request');
        } catch (PartnerRequestNotPossible) {
        }

        self::assertSame(0, $this->requestCount(PlayerFixture::PLAYER_ADMIN));
        self::assertSame([], $this->connections(PlayerFixture::PLAYER_ADMIN));
    }

    public function testKindTheListingDoesNotLookForIsRefused(): void
    {
        $this->expectException(PartnerRequestNotPossible::class);

        // LISTING_REGULAR_PAIR looks for a pair only
        $this->send(PlayerFixture::PLAYER_ADMIN, PartnerFinderFixture::LISTING_REGULAR_PAIR, PartnerKind::Team);
    }

    public function testListedPuzzlerBlockingTheSenderMeansNothingIsSentAndNothingIsSaid(): void
    {
        // PLAYER_REGULAR blocks PLAYER_PRIVATE (UserBlockFixture)
        $this->send(PlayerFixture::PLAYER_PRIVATE, PartnerFinderFixture::LISTING_REGULAR_PAIR, PartnerKind::Pair, 'Hi!');

        self::assertSame(0, $this->requestCount(PlayerFixture::PLAYER_PRIVATE));
        self::assertSame(0, $this->notificationCount());
        self::assertSame([], $this->queuedEmails());
        self::assertSame([], $this->connections(PlayerFixture::PLAYER_PRIVATE));
    }

    public function testSenderBlockingTheListedPuzzlerMeansNothingIsSent(): void
    {
        $players = self::getContainer()->get(PlayerRepository::class);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(new UserBlock(
            Uuid::uuid7(),
            $players->get(PlayerFixture::PLAYER_ADMIN),
            $players->get(PlayerFixture::PLAYER_REGULAR),
            new \DateTimeImmutable(),
        ));
        $entityManager->flush();

        $this->send(PlayerFixture::PLAYER_ADMIN, PartnerFinderFixture::LISTING_REGULAR_PAIR, PartnerKind::Pair);

        self::assertSame(0, $this->requestCount(PlayerFixture::PLAYER_ADMIN));
        self::assertSame(0, $this->notificationCount());
        self::assertSame([], $this->queuedEmails());
    }

    public function testSenderOverTheDailyLimitIsStopped(): void
    {
        /** @var RateLimiterFactoryInterface $limiter */
        $limiter = self::getContainer()->get('limiter.partner_request');

        try {
            // What is left of today's budget - other tests of this run may have used some of it
            $limiter->create(PlayerFixture::PLAYER_ADMIN)->consume($limiter->create(PlayerFixture::PLAYER_ADMIN)->consume(0)->getRemainingTokens());

            try {
                $this->send(PlayerFixture::PLAYER_ADMIN, PartnerFinderFixture::LISTING_REGULAR_PAIR, PartnerKind::Pair);
                self::fail('Over the limit the request must be refused');
            } catch (PartnerRequestLimitReached) {
            }

            self::assertSame(0, $this->requestCount(PlayerFixture::PLAYER_ADMIN));
            self::assertSame(0, $this->notificationCount());
        } finally {
            $limiter->create(PlayerFixture::PLAYER_ADMIN)->reset();
        }
    }

    private function send(string $senderId, string $listingId, PartnerKind $kind, null|string $note = null, null|string $requestId = null): void
    {
        $this->messageBus->dispatch(new SendPartnerRequest(
            requestId: $requestId ?? Uuid::uuid7()->toString(),
            listingId: $listingId,
            competitionId: PartnerFinderFixture::COMPETITION_PARTNER_FINDER_OPEN,
            fromPlayerId: $senderId,
            kind: $kind,
            note: $note,
        ));
    }

    private function requestCount(string $senderId): int
    {
        $count = $this->connection->fetchOne('SELECT COUNT(*) FROM partner_request WHERE from_player_id = :id', ['id' => $senderId]);
        assert(is_int($count));

        return $count;
    }

    private function notificationCount(): int
    {
        $count = $this->connection->fetchOne("SELECT COUNT(*) FROM notification WHERE type = 'PartnerRequest'");
        assert(is_int($count));

        return $count;
    }

    /**
     * @return array<string>
     */
    private function connections(string $playerId): array
    {
        return self::getContainer()->get(GetCompetitionParticipants::class)->getPlayerConnections(PartnerFinderFixture::COMPETITION_PARTNER_FINDER_OPEN, $playerId);
    }

    /**
     * @return list<string> request ids of the queued e-mail messages
     */
    private function queuedEmails(): array
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        $ids = [];

        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();

            if ($message instanceof SendPartnerRequestEmail) {
                $ids[] = $message->requestId;
            }
        }

        return $ids;
    }
}
