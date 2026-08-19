<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use League\Flysystem\Filesystem;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\CompetitionRoundNotFound;
use SpeedPuzzling\Web\Exceptions\SuspiciousPpm;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Services\Storage\UploadSpool;
use SpeedPuzzling\Web\Services\Storage\UploadSpoolProcessor;
use SpeedPuzzling\Web\Services\UploadFailureCollector;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestDouble\ToggleableFailingFilesystemAdapter;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

final class AddPuzzleSolvingTimeHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testAddSoloTimePersistsRowWithRequestedAttributes(): void
    {
        $timeId = Uuid::uuid7();

        $this->messageBus->dispatch(new AddPuzzleSolvingTime(
            timeId: $timeId,
            userId: PlayerFixture::PLAYER_REGULAR_USER_ID,
            puzzleId: PuzzleFixture::PUZZLE_1500_01,
            competitionId: null,
            time: '01:00:00',
            comment: 'Relaxed Sunday session',
            finishedPuzzlesPhoto: null,
            groupPlayers: [],
            finishedAt: null,
            firstAttempt: true,
            unboxed: false,
        ));

        /** @var array{seconds_to_solve: int, comment: null|string, first_attempt: bool, unboxed: bool, player_id: string, puzzle_id: string, team: null|string}|false $row */
        $row = $this->database->fetchAssociative(
            'SELECT seconds_to_solve, comment, first_attempt, unboxed, player_id, puzzle_id, team FROM puzzle_solving_time WHERE id = :id',
            ['id' => $timeId->toString()],
        );

        self::assertNotFalse($row);
        self::assertSame(3600, $row['seconds_to_solve']);
        self::assertSame('Relaxed Sunday session', $row['comment']);
        self::assertTrue((bool) $row['first_attempt']);
        self::assertFalse((bool) $row['unboxed']);
        self::assertSame(PlayerFixture::PLAYER_REGULAR, $row['player_id']);
        self::assertSame(PuzzleFixture::PUZZLE_1500_01, $row['puzzle_id']);
        self::assertNull($row['team']);
    }

    public function testMistypedFinishedAtYearIsNormalized(): void
    {
        // A user typing "16.06.26" is parsed as the year 0026; it must be stored as 2026.
        $timeId = Uuid::uuid7();

        $this->messageBus->dispatch(new AddPuzzleSolvingTime(
            timeId: $timeId,
            userId: PlayerFixture::PLAYER_REGULAR_USER_ID,
            puzzleId: PuzzleFixture::PUZZLE_1500_01,
            competitionId: null,
            time: '01:00:00',
            comment: null,
            finishedPuzzlesPhoto: null,
            groupPlayers: [],
            finishedAt: new \DateTimeImmutable('0026-06-16 00:00:00'),
            firstAttempt: true,
            unboxed: false,
        ));

        /** @var false|string $finishedAt */
        $finishedAt = $this->database->fetchOne(
            'SELECT finished_at FROM puzzle_solving_time WHERE id = :id',
            ['id' => $timeId->toString()],
        );

        self::assertNotFalse($finishedAt);
        self::assertStringStartsWith('2026-06-16', $finishedAt);
    }

    public function testAddSuspiciouslyFastTimeIsRejected(): void
    {
        // 500 pieces solved in 1 minute = 500 PPM, well above the 100 PPM threshold.
        $this->expectException(HandlerFailedException::class);

        try {
            $this->messageBus->dispatch(new AddPuzzleSolvingTime(
                timeId: Uuid::uuid7(),
                userId: PlayerFixture::PLAYER_REGULAR_USER_ID,
                puzzleId: PuzzleFixture::PUZZLE_500_01,
                competitionId: null,
                time: '00:01:00',
                comment: null,
                finishedPuzzlesPhoto: null,
                groupPlayers: [],
                finishedAt: null,
                firstAttempt: false,
                unboxed: false,
            ));
        } catch (HandlerFailedException $exception) {
            self::assertInstanceOf(SuspiciousPpm::class, $exception->getPrevious());
            throw $exception;
        }
    }

    public function testUnknownCompetitionIdIsSilentlyDroppedWithoutFailing(): void
    {
        // The form validates the competition id against the picker's set, so an unknown id only reaches
        // the handler when the competition vanished between render and submit. The time is still saved
        // (without the link) and a warning is logged - a stale value must not crash the whole save.
        $timeId = Uuid::uuid7();

        $this->messageBus->dispatch(new AddPuzzleSolvingTime(
            timeId: $timeId,
            userId: PlayerFixture::PLAYER_REGULAR_USER_ID,
            puzzleId: PuzzleFixture::PUZZLE_1500_01,
            competitionId: '00000000-0000-0000-0000-000000000000',
            time: '01:05:00',
            comment: null,
            finishedPuzzlesPhoto: null,
            groupPlayers: [],
            finishedAt: null,
            firstAttempt: false,
            unboxed: false,
        ));

        /** @var null|string|false $competitionId */
        $competitionId = $this->database->fetchOne(
            'SELECT competition_id FROM puzzle_solving_time WHERE id = :id',
            ['id' => $timeId->toString()],
        );

        self::assertNull($competitionId);
    }

    public function testTimeIsAttachedToCompetitionRound(): void
    {
        // When a round is supplied, the time links to that round and (derived) to its competition.
        $timeId = Uuid::uuid7();

        $this->messageBus->dispatch(new AddPuzzleSolvingTime(
            timeId: $timeId,
            userId: PlayerFixture::PLAYER_REGULAR_USER_ID,
            puzzleId: PuzzleFixture::PUZZLE_500_01,
            competitionId: null,
            time: '00:45:00',
            comment: null,
            finishedPuzzlesPhoto: null,
            groupPlayers: [],
            finishedAt: null,
            firstAttempt: false,
            unboxed: false,
            roundId: CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION,
        ));

        /** @var array{competition_round_id: null|string, competition_id: null|string}|false $row */
        $row = $this->database->fetchAssociative(
            'SELECT competition_round_id, competition_id FROM puzzle_solving_time WHERE id = :id',
            ['id' => $timeId->toString()],
        );

        self::assertNotFalse($row);
        self::assertSame(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION, $row['competition_round_id']);
        self::assertSame(CompetitionFixture::COMPETITION_WJPC_2024, $row['competition_id']);
    }

    public function testS3OutageSpoolsPhotoAndTimeIsStillSaved(): void
    {
        $timeId = Uuid::uuid7();
        $container = self::getContainer();

        /** @var ToggleableFailingFilesystemAdapter $s3Adapter */
        $s3Adapter = $container->get('app.storage.s3_adapter');
        $s3Adapter->setFailing(true);

        $imagePath = tempnam(sys_get_temp_dir(), 'puzzle_test_') . '.jpg';
        $image = imagecreatetruecolor(10, 10);
        assert($image !== false);
        imagejpeg($image, $imagePath);

        $this->messageBus->dispatch(new AddPuzzleSolvingTime(
            timeId: $timeId,
            userId: PlayerFixture::PLAYER_REGULAR_USER_ID,
            puzzleId: PuzzleFixture::PUZZLE_1500_01,
            competitionId: null,
            time: '01:00:00',
            comment: null,
            finishedPuzzlesPhoto: new UploadedFile($imagePath, 'finished.jpg', 'image/jpeg', null, true),
            groupPlayers: [],
            finishedAt: null,
            firstAttempt: true,
            unboxed: false,
        ));

        // The time is saved despite the outage, with the photo path recorded
        /** @var false|null|string $photoPath */
        $photoPath = $this->database->fetchOne(
            'SELECT finished_puzzle_photo FROM puzzle_solving_time WHERE id = :id',
            ['id' => $timeId->toString()],
        );
        self::assertIsString($photoPath);

        // The photo landed in the spool and the user gets notified
        /** @var UploadSpool $spool */
        $spool = $container->get(UploadSpool::class);
        self::assertTrue($spool->hasPayload($photoPath));

        /** @var UploadFailureCollector $collector */
        $collector = $container->get(UploadFailureCollector::class);
        self::assertTrue($collector->hasFailures());

        // Storage recovers - the retry drains the spool to S3 under the same key
        $s3Adapter->setFailing(false);

        /** @var UploadSpoolProcessor $processor */
        $processor = $container->get(UploadSpoolProcessor::class);
        $result = $processor->process();

        self::assertSame(1, $result['uploaded']);
        self::assertFalse($spool->hasPayload($photoPath));

        /** @var Filesystem $filesystem */
        $filesystem = $container->get(Filesystem::class);
        self::assertTrue($filesystem->fileExists($photoPath));
    }

    public function testUnknownRoundIdThrows(): void
    {
        // The API processor guards this before it reaches the handler; here we document
        // the handler-level behavior: an unknown round id bubbles up as CompetitionRoundNotFound.
        // CompetitionRoundNotFound extends NotFoundHttpException, so it arrives
        // unwrapped (UnwrapHttpExceptionMiddleware) rather than inside a
        // HandlerFailedException.
        $this->expectException(CompetitionRoundNotFound::class);

        $this->messageBus->dispatch(new AddPuzzleSolvingTime(
            timeId: Uuid::uuid7(),
            userId: PlayerFixture::PLAYER_REGULAR_USER_ID,
            puzzleId: PuzzleFixture::PUZZLE_500_01,
            competitionId: null,
            time: '00:45:00',
            comment: null,
            finishedPuzzlesPhoto: null,
            groupPlayers: [],
            finishedAt: null,
            firstAttempt: false,
            unboxed: false,
            roundId: '00000000-0000-0000-0000-000000000000',
        ));
    }
}
