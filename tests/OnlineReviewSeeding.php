<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionParticipant;
use SpeedPuzzling\Web\Entity\CompetitionParticipantRound;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\OnlineEntry;
use SpeedPuzzling\Web\Entity\OnlineEntryEvent;
use SpeedPuzzling\Web\Entity\OnlineEntryMedia;
use SpeedPuzzling\Web\Repository\OnlineEntryRepository;
use SpeedPuzzling\Web\Repository\OnlineRoundRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\OnlineRoundsFixture;
use SpeedPuzzling\Web\Value\OnlineDetectionMode;
use SpeedPuzzling\Web\Value\OnlineEventType;
use SpeedPuzzling\Web\Value\OnlineMediaKind;
use SpeedPuzzling\Web\Value\OnlineMediaStore;
use SpeedPuzzling\Web\Value\RoundClock;

/**
 * A round of the Online Test Cup that ran (docs/features/online-competitions/, stream C2 tests): the fixture's online
 * round started some time ago, the participants' phones' events and evidence - Finished taps, undos, bursts with their
 * manifest, photos - written through the entities, flushed. Moments are epoch ms on the real clock.
 */
trait OnlineReviewSeeding
{
    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * The fixture's online round, started $secondsAgo seconds ago (a whole second) - its start in epoch ms
     */
    private function startOnlineRound(int $secondsAgo = 1800): int
    {
        $round = $this->onlineRound();
        $round->startStopwatch(TestClock::now()->modify("-{$secondsAgo} seconds"));
        $this->entityManager()->flush();

        $startedAt = $round->stopwatchStartedAt;
        assert($startedAt !== null);

        return RoundClock::milliseconds($startedAt);
    }

    /**
     * "End now" $secondsAgo seconds ago
     */
    private function endOnlineRound(int $secondsAgo = 0): void
    {
        $this->onlineRound()->stopStopwatch(TestClock::now()->modify("-{$secondsAgo} seconds"));
        $this->entityManager()->flush();
    }

    private function onlineRound(): CompetitionRound
    {
        $round = $this->entityManager()->find(CompetitionRound::class, OnlineRoundsFixture::ROUND_ONLINE_SOLO);
        assert($round instanceof CompetitionRound);

        return $round;
    }

    /**
     * The entry's online state - the existing one, or a new one as the phone's first contact makes it
     */
    private function onlineEntryOf(string $entryId): OnlineEntry
    {
        $existing = self::getContainer()->get(OnlineEntryRepository::class)->ofEntry($entryId);

        if ($existing !== null) {
            return $existing;
        }

        $entry = $this->entityManager()->find(CompetitionParticipantRound::class, $entryId);
        assert($entry instanceof CompetitionParticipantRound);

        $onlineEntry = new OnlineEntry(Uuid::uuid7(), $entry, TestClock::now()->modify('-1 hour'));
        $this->entityManager()->persist($onlineEntry);
        $this->entityManager()->flush();

        return $onlineEntry;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function phoneEvent(
        OnlineEntry $onlineEntry,
        OnlineEventType $type,
        int $clientMs,
        array $payload = [],
        null|int $receivedAtMs = null,
        string $deviceId = OnlineRoundsFixture::DEVICE_CONNECTED,
    ): string {
        $id = Uuid::uuid7();
        $this->entityManager()->persist(new OnlineEntryEvent(
            id: $id,
            onlineEntry: $onlineEntry,
            deviceId: $deviceId,
            type: $type,
            clientMs: $clientMs,
            perfMs: 1_000.5,
            offsetMs: -12.5,
            rttMs: 38.0,
            receivedAtMs: $receivedAtMs ?? $clientMs + 300,
            payload: $payload,
        ));
        $this->entityManager()->flush();

        return $id->toString();
    }

    private function finishTap(OnlineEntry $onlineEntry, int $momentMs, null|int $receivedAtMs = null): string
    {
        return $this->phoneEvent($onlineEntry, OnlineEventType::FinishButton, $momentMs, [], $receivedAtMs);
    }

    /**
     * Raised hands the phone's detector held (Phase 2) - the payload as compete page sends it, overridable
     *
     * @param array<string, mixed> $payload
     */
    private function finishCandidate(
        OnlineEntry $onlineEntry,
        int $momentMs,
        array $payload = [],
        null|int $receivedAtMs = null,
        string $deviceId = OnlineRoundsFixture::DEVICE_CONNECTED,
    ): string {
        return $this->phoneEvent($onlineEntry, OnlineEventType::FinishCandidate, $momentMs, [
            'candidateId' => 1,
            'detectedAtMs' => $momentMs + 520,
            'windowFromMs' => $momentMs,
            'windowToMs' => $momentMs + 520,
            'heldMs' => 520,
            'score' => 0.86,
            'view' => 'angled',
            'mode' => 'on',
            'machineVersion' => 1,
            ...$payload,
        ], $receivedAtMs, $deviceId);
    }

    /**
     * The fixture's online round with this finish detection (its other settings kept)
     */
    private function detectionMode(OnlineDetectionMode $mode): void
    {
        $onlineRound = self::getContainer()->get(OnlineRoundRepository::class)->ofRound(OnlineRoundsFixture::ROUND_ONLINE_SOLO);
        assert($onlineRound !== null);
        $onlineRound->changeSettings(
            detectionMode: $mode,
            videoEnabled: $onlineRound->videoEnabled,
            participantsSeeEachOther: $onlineRound->participantsSeeEachOther,
            studioEnabled: $onlineRound->studioEnabled,
            recordingEnabled: $onlineRound->recordingEnabled,
            unfinishedMeasure: $onlineRound->unfinishedMeasure,
            missingPieceRule: $onlineRound->missingPieceRule,
        );
        $this->entityManager()->flush();
    }

    private function undoTap(OnlineEntry $onlineEntry, string $tapId, int $atMs): string
    {
        return $this->phoneEvent($onlineEntry, OnlineEventType::FinishButtonUndone, $atMs, ['eventId' => $tapId]);
    }

    /**
     * @param null|list<array{offset: int, length: int, captureMs: int|float}> $manifest
     */
    private function evidence(
        OnlineEntry $onlineEntry,
        OnlineMediaKind $kind,
        int $takenMs,
        null|string $relatedEventId = null,
        null|array $manifest = null,
        bool $uploaded = true,
    ): OnlineEntryMedia {
        $id = Uuid::uuid7();
        $entry = $onlineEntry->entry;
        $media = new OnlineEntryMedia(
            id: $id,
            onlineEntry: $onlineEntry,
            kind: $kind,
            takenMs: $takenMs,
            store: OnlineMediaStore::Primary,
            objectKey: sprintf('online/%s/%s/%s/%s.jpg', $entry->round->competition->id->toString(), $entry->round->id->toString(), $entry->participant->id->toString(), $id->toString()),
            contentType: $kind === OnlineMediaKind::Burst ? 'application/octet-stream' : 'image/jpeg',
            declaredBytes: $manifest === null ? 35_000 : array_sum(array_column($manifest, 'length')),
            createdAt: new DateTimeImmutable('@' . intdiv($takenMs, 1000)),
            relatedEventId: $relatedEventId === null ? null : Uuid::fromString($relatedEventId),
        );

        if ($uploaded) {
            $media->markUploaded($takenMs + 2_000, $manifest);
        }

        $this->entityManager()->persist($media);
        $this->entityManager()->flush();

        return $media;
    }

    /**
     * A burst's manifest as frame_buffer.js builds it: frames 100 ms apart from 5 s before to 5 s after the moment
     *
     * @return list<array{offset: int, length: int, captureMs: int|float}>
     */
    private static function burstManifest(int $momentMs, int $frameBytes = 1_000): array
    {
        $manifest = [];

        for ($step = -50; $step <= 50; $step++) {
            $manifest[] = ['offset' => ($step + 50) * $frameBytes, 'length' => $frameBytes, 'captureMs' => $momentMs + $step * 100 + 0.4];
        }

        return $manifest;
    }

    /**
     * Another participant of the cup, entered in the online round
     */
    private function enterParticipant(string $name, null|string $country = 'cz'): CompetitionParticipantRound
    {
        $round = $this->onlineRound();
        $participant = new CompetitionParticipant(Uuid::uuid7(), $name, $country, $round->competition);
        $entry = new CompetitionParticipantRound(Uuid::uuid7(), $participant, $round);
        $this->entityManager()->persist($participant);
        $this->entityManager()->persist($entry);
        $this->entityManager()->flush();

        return $entry;
    }
}
