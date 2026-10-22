<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use SpeedPuzzling\Web\Tests\TestClock;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\OnlineEntryMedia;
use SpeedPuzzling\Web\Entity\OnlineRound;
use SpeedPuzzling\Web\Exceptions\OnlineParticipantNotEntered;
use SpeedPuzzling\Web\Exceptions\OnlineRoundNotOnline;
use SpeedPuzzling\Web\Message\RegisterOnlineMedia;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OnlineRoundsFixture;
use SpeedPuzzling\Web\Value\OnlineMediaKind;
use SpeedPuzzling\Web\Value\OnlineMediaStore;
use SpeedPuzzling\Web\Value\RoundCategory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * An evidence object a phone is about to upload (RegisterOnlineMediaHandler): its row, its key, its store.
 */
final class RegisterOnlineMediaHandlerTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
    }

    public function testTheRowOfAPhotoOnThePhonesFirstContact(): void
    {
        $mediaId = Uuid::uuid7()->toString();
        $eventId = Uuid::uuid7()->toString();

        $media = $this->register($mediaId, OnlineRoundsFixture::PARTICIPANT_ANONYMOUS, OnlineMediaKind::Button, 'image/jpeg', $eventId);

        self::assertSame($mediaId, $media->id->toString());
        // No second store configured: the primary
        self::assertSame(OnlineMediaStore::Primary, $media->store);
        self::assertSame(
            sprintf('online/%s/%s/%s/%s.jpg', OnlineRoundsFixture::COMPETITION_ONLINE_TEST_CUP, OnlineRoundsFixture::ROUND_ONLINE_SOLO, OnlineRoundsFixture::PARTICIPANT_ANONYMOUS, $mediaId),
            $media->objectKey,
        );

        $row = $this->connection->fetchAssociative(
            'SELECT m.kind, m.taken_ms, m.declared_bytes, m.content_type, m.related_event_id, m.uploaded_at_ms, o.entry_id
             FROM online_entry_media m JOIN online_entry o ON o.id = m.online_entry_id WHERE m.id = :id',
            ['id' => $mediaId],
        );
        self::assertSame([
            'kind' => 'button',
            'taken_ms' => 1_792_002_832_345,
            'declared_bytes' => 250_000,
            'content_type' => 'image/jpeg',
            'related_event_id' => $eventId,
            'uploaded_at_ms' => null,
            // The anonymous participant's first contact made their online entry
            'entry_id' => OnlineRoundsFixture::ENTRY_ANONYMOUS,
        ], $row);
    }

    public function testABurstIsABinaryObject(): void
    {
        $media = $this->register(Uuid::uuid7()->toString(), OnlineRoundsFixture::PARTICIPANT_CONNECTED, OnlineMediaKind::Burst, 'application/octet-stream');

        self::assertStringEndsWith('.bin', $media->objectKey);
        self::assertSame(OnlineRoundsFixture::ONLINE_ENTRY_CONNECTED, $media->onlineEntry->id->toString());
    }

    public function testARoundOfAnotherCompetitionIsNotOnline(): void
    {
        $this->expectException(OnlineRoundNotOnline::class);

        $this->register(Uuid::uuid7()->toString(), OnlineRoundsFixture::PARTICIPANT_CONNECTED, OnlineMediaKind::Start, 'image/jpeg', roundId: OfficialResultsFixture::ROUND_GROUP_A);
    }

    public function testARoundTheParticipantIsNotEnteredIn(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $competition = $entityManager->find(Competition::class, OnlineRoundsFixture::COMPETITION_ONLINE_TEST_CUP);
        self::assertNotNull($competition);
        $round = new CompetitionRound(id: Uuid::uuid7(), competition: $competition, name: 'Online Final', minutesLimit: 45, startsAt: TestClock::now()->modify('+30 days'), category: RoundCategory::Solo);
        $entityManager->persist($round);
        $entityManager->persist(new OnlineRound(Uuid::uuid7(), $round, TestClock::now(), null));
        $entityManager->flush();

        $this->expectException(OnlineParticipantNotEntered::class);

        $this->register(Uuid::uuid7()->toString(), OnlineRoundsFixture::PARTICIPANT_CONNECTED, OnlineMediaKind::Start, 'image/jpeg', roundId: $round->id->toString());
    }

    private function register(
        string $mediaId,
        string $participantId,
        OnlineMediaKind $kind,
        string $contentType,
        null|string $relatedEventId = null,
        string $roundId = OnlineRoundsFixture::ROUND_ONLINE_SOLO,
    ): OnlineEntryMedia {
        $envelope = self::getContainer()->get(MessageBusInterface::class)->dispatch(new RegisterOnlineMedia(
            mediaId: $mediaId,
            participantId: $participantId,
            roundId: $roundId,
            deviceId: 'phone-a',
            kind: $kind,
            takenMs: 1_792_002_832_345,
            bytes: 250_000,
            contentType: $contentType,
            relatedEventId: $relatedEventId,
        ));

        $media = $envelope->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(OnlineEntryMedia::class, $media);

        return $media;
    }
}
