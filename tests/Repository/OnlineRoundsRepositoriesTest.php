<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\OnlineEntry;
use SpeedPuzzling\Web\Entity\OnlineEntryEvent;
use SpeedPuzzling\Web\Exceptions\OnlineEntryEventNotFound;
use SpeedPuzzling\Web\Exceptions\OnlineEntryMediaNotFound;
use SpeedPuzzling\Web\Exceptions\OnlineEntryNotFound;
use SpeedPuzzling\Web\Exceptions\OnlineLinkNotFound;
use SpeedPuzzling\Web\Exceptions\OnlineRoundNotFound;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRepository;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRoundRepository;
use SpeedPuzzling\Web\Repository\OnlineEntryEventRepository;
use SpeedPuzzling\Web\Repository\OnlineEntryMediaRepository;
use SpeedPuzzling\Web\Repository\OnlineEntryRepository;
use SpeedPuzzling\Web\Repository\OnlineRoundRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OnlineRoundsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\OnlineDetectionMode;
use SpeedPuzzling\Web\Value\OnlineEventType;
use SpeedPuzzling\Web\Value\OnlineMediaKind;
use SpeedPuzzling\Web\Value\OnlineMediaStore;
use SpeedPuzzling\Web\Value\OnlineMissingPieceRule;
use SpeedPuzzling\Web\Value\OnlineReviewStatus;
use SpeedPuzzling\Web\Value\OnlineUnfinishedMeasure;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The online rounds' repositories and their finders, read against OnlineRoundsFixture (which this proves loads).
 */
final class OnlineRoundsRepositoriesTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testTheTestCupIsADraftNobodyElseSees(): void
    {
        $competition = $this->entityManager->find(Competition::class, OnlineRoundsFixture::COMPETITION_ONLINE_TEST_CUP);

        self::assertNotNull($competition);
        self::assertTrue($competition->isOnline);
        self::assertTrue($competition->isDraft);
        self::assertNotNull($competition->approvedAt);
        self::assertSame(PlayerFixture::PLAYER_ADMIN, $competition->addedByPlayer?->id->toString());
        self::assertFalse($competition->isPubliclyVisible());
    }

    public function testOnlineRounds(): void
    {
        $repository = self::getContainer()->get(OnlineRoundRepository::class);

        $onlineRound = $repository->get(OnlineRoundsFixture::ONLINE_ROUND_SOLO);
        self::assertSame(OnlineRoundsFixture::ROUND_ONLINE_SOLO, $onlineRound->round->id->toString());
        self::assertSame(PlayerFixture::PLAYER_ADMIN, $onlineRound->enabledBy?->id->toString());
        self::assertSame(OnlineDetectionMode::Off, $onlineRound->detectionMode);
        self::assertFalse($onlineRound->videoEnabled);
        self::assertFalse($onlineRound->participantsSeeEachOther);
        self::assertFalse($onlineRound->studioEnabled);
        self::assertFalse($onlineRound->recordingEnabled);
        self::assertSame(OnlineUnfinishedMeasure::PiecesLeft, $onlineRound->unfinishedMeasure);
        self::assertSame(OnlineMissingPieceRule::CompleteRequired, $onlineRound->missingPieceRule);

        self::assertSame($onlineRound, $repository->ofRound(OnlineRoundsFixture::ROUND_ONLINE_SOLO));
        self::assertNull($repository->ofRound(OfficialResultsFixture::ROUND_GROUP_A));
        self::assertNull($repository->ofRound('not-a-uuid'));

        self::assertSame([$onlineRound], $repository->ofCompetition(OnlineRoundsFixture::COMPETITION_ONLINE_TEST_CUP));
        self::assertSame([], $repository->ofCompetition(OfficialResultsFixture::COMPETITION_RESULTS_CUP));
        self::assertSame([], $repository->ofCompetition('not-a-uuid'));
    }

    public function testChangingTheSettings(): void
    {
        $repository = self::getContainer()->get(OnlineRoundRepository::class);
        $repository->get(OnlineRoundsFixture::ONLINE_ROUND_SOLO)->changeSettings(
            detectionMode: OnlineDetectionMode::Shadow,
            videoEnabled: true,
            participantsSeeEachOther: true,
            studioEnabled: true,
            recordingEnabled: true,
            unfinishedMeasure: OnlineUnfinishedMeasure::PiecesPlaced,
            missingPieceRule: OnlineMissingPieceRule::PenaltyFiveSeconds,
        );
        $this->entityManager->flush();
        $this->entityManager->clear();

        $onlineRound = $repository->get(OnlineRoundsFixture::ONLINE_ROUND_SOLO);
        self::assertSame(OnlineDetectionMode::Shadow, $onlineRound->detectionMode);
        self::assertTrue($onlineRound->videoEnabled && $onlineRound->participantsSeeEachOther && $onlineRound->studioEnabled && $onlineRound->recordingEnabled);
        self::assertSame(OnlineUnfinishedMeasure::PiecesPlaced, $onlineRound->unfinishedMeasure);
        self::assertSame(OnlineMissingPieceRule::PenaltyFiveSeconds, $onlineRound->missingPieceRule);
    }

    public function testAnUnknownOnlineRoundIsNotFound(): void
    {
        $this->expectException(OnlineRoundNotFound::class);
        self::getContainer()->get(OnlineRoundRepository::class)->get(OnlineRoundsFixture::ROUND_ONLINE_SOLO);
    }

    public function testParticipantsByTheirLink(): void
    {
        $repository = self::getContainer()->get(CompetitionParticipantRepository::class);

        $connected = $repository->getByOnlineToken(OnlineRoundsFixture::TOKEN_CONNECTED);
        self::assertSame(OnlineRoundsFixture::PARTICIPANT_CONNECTED, $connected->id->toString());
        self::assertSame(PlayerFixture::PLAYER_REGULAR, $connected->player?->id->toString());
        self::assertNotNull($connected->onlineTokenCreatedAt);
        self::assertSame(OnlineRoundsFixture::PARTICIPANT_ANONYMOUS, $repository->getByOnlineToken(OnlineRoundsFixture::TOKEN_ANONYMOUS)->id->toString());

        $withoutToken = $repository->get(OnlineRoundsFixture::PARTICIPANT_WITHOUT_TOKEN);
        self::assertNull($withoutToken->onlineToken);
        self::assertNull($withoutToken->onlineTokenCreatedAt);

        $found = [];

        foreach (['NoSuchTokenAnywhere222', strtolower(OnlineRoundsFixture::TOKEN_CONNECTED), 'x'] as $token) {
            try {
                $repository->getByOnlineToken($token);
                $found[] = $token;
            } catch (OnlineLinkNotFound) {
                // expected
            }
        }

        self::assertSame([], $found);
    }

    public function testAParticipantsEntryInARound(): void
    {
        $repository = self::getContainer()->get(CompetitionParticipantRoundRepository::class);

        self::assertSame(
            OnlineRoundsFixture::ENTRY_CONNECTED,
            $repository->findOfParticipantInRound(OnlineRoundsFixture::PARTICIPANT_CONNECTED, OnlineRoundsFixture::ROUND_ONLINE_SOLO)?->id->toString(),
        );
        self::assertNull($repository->findOfParticipantInRound(OnlineRoundsFixture::PARTICIPANT_WITHOUT_TOKEN, OnlineRoundsFixture::ROUND_ONLINE_SOLO));
        self::assertNull($repository->findOfParticipantInRound('not-a-uuid', OnlineRoundsFixture::ROUND_ONLINE_SOLO));
    }

    public function testOnlineEntries(): void
    {
        $repository = self::getContainer()->get(OnlineEntryRepository::class);

        $onlineEntry = $repository->get(OnlineRoundsFixture::ONLINE_ENTRY_CONNECTED);
        self::assertSame(OnlineRoundsFixture::ENTRY_CONNECTED, $onlineEntry->entry->id->toString());
        self::assertSame(OnlineRoundsFixture::DEVICE_CONNECTED, $onlineEntry->activeDeviceId);
        self::assertNotNull($onlineEntry->readyAt);
        self::assertSame(-12.5, $onlineEntry->clockOffsetMs);
        self::assertSame(38.0, $onlineEntry->clockRttMs);
        self::assertSame(OnlineReviewStatus::Pending, $onlineEntry->reviewStatus);
        self::assertSame([], $onlineEntry->flags);

        self::assertSame($onlineEntry, $repository->ofEntry(OnlineRoundsFixture::ENTRY_CONNECTED));
        self::assertNull($repository->ofEntry(OnlineRoundsFixture::ENTRY_ANONYMOUS));
        self::assertNull($repository->ofEntry('not-a-uuid'));

        $this->expectException(OnlineEntryNotFound::class);
        $repository->get(OnlineRoundsFixture::ENTRY_CONNECTED);
    }

    public function testFlagsAreKeptOnceEach(): void
    {
        $repository = self::getContainer()->get(OnlineEntryRepository::class);
        $onlineEntry = $repository->get(OnlineRoundsFixture::ONLINE_ENTRY_CONNECTED);
        $onlineEntry->addFlag('camera_lost');
        $onlineEntry->addFlag('arrived_late');
        $onlineEntry->addFlag('camera_lost');
        $this->entityManager->flush();
        $this->entityManager->clear();

        self::assertSame(['camera_lost', 'arrived_late'], $repository->get(OnlineRoundsFixture::ONLINE_ENTRY_CONNECTED)->flags);
    }

    public function testEvents(): void
    {
        $repository = self::getContainer()->get(OnlineEntryEventRepository::class);

        $event = $repository->get(OnlineRoundsFixture::EVENT_READY_CONNECTED);
        self::assertSame(OnlineEventType::DeviceReady, $event->type);
        self::assertSame(OnlineRoundsFixture::ONLINE_ENTRY_CONNECTED, $event->onlineEntry->id->toString());
        self::assertSame($event->clientMs + 120, $event->receivedAtMs);
        // jsonb keeps its own key order
        self::assertEquals(['browser' => 'fixture', 'camera' => ['w' => 1920, 'h' => 1080, 'fps' => 30]], $event->payload);

        $existing = $repository->existingIds([
            strtoupper(OnlineRoundsFixture::EVENT_CONSENT_CONNECTED),
            Uuid::uuid7()->toString(),
            'not-a-uuid',
            OnlineRoundsFixture::EVENT_READY_CONNECTED,
            OnlineRoundsFixture::EVENT_READY_CONNECTED,
        ]);
        sort($existing);
        self::assertSame([OnlineRoundsFixture::EVENT_CONSENT_CONNECTED, OnlineRoundsFixture::EVENT_READY_CONNECTED], $existing);
        self::assertSame([], $repository->existingIds([]));
        self::assertSame([], $repository->existingIds(['not-a-uuid']));

        self::assertSame(['consent.given' => 1, 'device.ready' => 1], self::sorted($repository->typeCounts(OnlineRoundsFixture::ONLINE_ENTRY_CONNECTED, 0)));
        // From a moment on (a run's start): the consent came before it, the ready after it
        $readyMs = $repository->get(OnlineRoundsFixture::EVENT_READY_CONNECTED)->clientMs;
        self::assertSame(['device.ready' => 1], $repository->typeCounts(OnlineRoundsFixture::ONLINE_ENTRY_CONNECTED, $readyMs));
        self::assertSame([], $repository->typeCounts(OnlineRoundsFixture::ONLINE_ENTRY_CONNECTED, $readyMs + 1));
        self::assertSame([], $repository->typeCounts(Uuid::uuid7()->toString(), 0));
        self::assertSame([], $repository->typeCounts('not-a-uuid', 0));

        $this->expectException(OnlineEntryEventNotFound::class);
        $repository->get(Uuid::uuid7()->toString());
    }

    public function testAnEventKeepsItsMillisecondsAndPayload(): void
    {
        $repository = self::getContainer()->get(OnlineEntryEventRepository::class);
        $onlineEntry = $this->entityManager->find(OnlineEntry::class, OnlineRoundsFixture::ONLINE_ENTRY_CONNECTED);
        self::assertNotNull($onlineEntry);

        $id = Uuid::uuid7();
        $repository->save(new OnlineEntryEvent(
            id: $id,
            onlineEntry: $onlineEntry,
            deviceId: OnlineRoundsFixture::DEVICE_CONNECTED,
            type: OnlineEventType::FinishButton,
            clientMs: 1_792_002_832_345,
            perfMs: 2_832_345.5,
            offsetMs: -1_234.5,
            rttMs: 38.0,
            receivedAtMs: 1_792_002_832_400,
            payload: ['burstMediaId' => 'b', 'photoMediaId' => 'p'],
        ));
        $this->entityManager->flush();
        $this->entityManager->clear();

        $event = $repository->get($id->toString());
        self::assertSame(1_792_002_832_345, $event->clientMs);
        self::assertSame(1_792_002_832_400, $event->receivedAtMs);
        self::assertSame(2_832_345.5, $event->perfMs);
        self::assertSame(-1_234.5, $event->offsetMs);
        self::assertEquals(['burstMediaId' => 'b', 'photoMediaId' => 'p'], $event->payload);
        self::assertSame([$id->toString()], $repository->existingIds([$id->toString()]));
    }

    public function testMedia(): void
    {
        $repository = self::getContainer()->get(OnlineEntryMediaRepository::class);

        $media = $repository->get(OnlineRoundsFixture::MEDIA_EMPTY_MAT_CONNECTED);
        self::assertSame(OnlineMediaKind::EmptyMat, $media->kind);
        self::assertSame(OnlineMediaStore::Primary, $media->store);
        self::assertSame(OnlineRoundsFixture::ONLINE_ENTRY_CONNECTED, $media->onlineEntry->id->toString());
        self::assertSame('online/' . OnlineRoundsFixture::COMPETITION_ONLINE_TEST_CUP . '/' . OnlineRoundsFixture::ROUND_ONLINE_SOLO . '/' . OnlineRoundsFixture::PARTICIPANT_CONNECTED . '/' . OnlineRoundsFixture::MEDIA_EMPTY_MAT_CONNECTED . '.jpg', $media->objectKey);
        self::assertSame('image/jpeg', $media->contentType);
        self::assertSame(182_000, $media->declaredBytes);
        self::assertSame(182_000, $media->verifiedBytes);
        self::assertSame($media->takenMs + 1_500, $media->uploadedAtMs);
        self::assertNull($media->manifest);
        self::assertNull($media->relatedEventId);

        $this->expectException(OnlineEntryMediaNotFound::class);
        $repository->get('not-a-uuid');
    }

    public function testRemovingARoundEntryTakesItsOnlineDataAlong(): void
    {
        $entry = self::getContainer()->get(CompetitionParticipantRoundRepository::class)->get(OnlineRoundsFixture::ENTRY_CONNECTED);
        $this->entityManager->remove($entry);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $connection = $this->entityManager->getConnection();
        $parameters = ['id' => OnlineRoundsFixture::ONLINE_ENTRY_CONNECTED];
        self::assertFalse($connection->fetchOne('SELECT 1 FROM online_entry WHERE id = :id', $parameters));
        self::assertFalse($connection->fetchOne('SELECT 1 FROM online_entry_event WHERE online_entry_id = :id', $parameters));
        self::assertFalse($connection->fetchOne('SELECT 1 FROM online_entry_media WHERE online_entry_id = :id', $parameters));
    }

    /**
     * @param array<string, int> $counts
     * @return array<string, int>
     */
    private static function sorted(array $counts): array
    {
        ksort($counts);

        return $counts;
    }
}
