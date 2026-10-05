<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\Stopwatch;
use SpeedPuzzling\Web\Value\BrandCodeList;
use SpeedPuzzling\Web\Value\EanList;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Exceptions\CanNotModifyOtherPlayersTime;
use SpeedPuzzling\Web\Exceptions\PuzzleIdTaken;
use SpeedPuzzling\Web\Exceptions\SolvingTimeAlreadySaved;
use SpeedPuzzling\Web\Exceptions\SolvingTimeIdReused;
use SpeedPuzzling\Web\Exceptions\SolvingTimeIdTaken;
use SpeedPuzzling\Web\Exceptions\StopwatchCouldNotBeFinished;
use SpeedPuzzling\Web\Message\AddPuzzle;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Message\AddPuzzleTracking;
use SpeedPuzzling\Web\Message\RecordDuplicatePrevention;
use SpeedPuzzling\Web\Query\GetRecentIdenticalSolvingTime;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\StopwatchFixture;
use SpeedPuzzling\Web\Value\DuplicatePreventionKind;
use SpeedPuzzling\Web\Value\SolvingTimeSource;
use SpeedPuzzling\Web\Value\PuzzleName;
use SpeedPuzzling\Web\Value\PuzzleNames;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Layer 1 of docs/features/duplicate-results.md: a save sent again never creates a second row.
 */
final class ResultSavedOnceTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testTheSameIdOfTheSamePlayerIsAnsweredWithTheSavedResult(): void
    {
        $timeId = Uuid::uuid7();
        $this->addTime($timeId);

        // Even a changed field does not make it a new result - it is the same form sent again
        $refusal = $this->refusalOf(fn () => $this->addTime($timeId, comment: 'Edited before resending'));

        self::assertInstanceOf(SolvingTimeAlreadySaved::class, $refusal);
        self::assertSame($timeId->toString(), $refusal->timeId);
        self::assertSame(PuzzleFixture::PUZZLE_1500_02, $refusal->puzzleId);
        self::assertSame(1, $this->countTimes($timeId));
    }

    public function testTheSameIdWithAnotherTimeOrDayIsAnotherResult(): void
    {
        $timeId = Uuid::uuid7();
        $this->addTime($timeId, finishedAt: new \DateTimeImmutable('2026-09-20'));

        self::assertInstanceOf(SolvingTimeIdReused::class, $this->refusalOf(fn () => $this->addTime($timeId, time: '01:00:01', finishedAt: new \DateTimeImmutable('2026-09-20'))));
        self::assertInstanceOf(SolvingTimeIdReused::class, $this->refusalOf(fn () => $this->addTime($timeId, finishedAt: new \DateTimeImmutable('2026-09-21'))));
        self::assertInstanceOf(SolvingTimeIdReused::class, $this->refusalOf(fn () => $this->addTime($timeId, puzzleId: PuzzleFixture::PUZZLE_1500_01, finishedAt: new \DateTimeImmutable('2026-09-20'))));

        // The same puzzle, time and day - another time of that day is still the same entry
        self::assertInstanceOf(SolvingTimeAlreadySaved::class, $this->refusalOf(fn () => $this->addTime($timeId, finishedAt: new \DateTimeImmutable('2026-09-20 18:00'))));
        self::assertSame(1, $this->countTimes($timeId));
    }

    public function testEverySaveLocksItsIdBeforeAnythingElse(): void
    {
        /** @var DebugDataHolder $debugDataHolder */
        $debugDataHolder = self::getContainer()->get('doctrine.debug_data_holder');

        $saves = [
            'time' => fn (UuidInterface $id) => $this->addTime($id),
            'tracking' => fn (UuidInterface $id) => $this->addTracking($id, PlayerFixture::PLAYER_REGULAR_USER_ID),
            'puzzle' => fn (UuidInterface $id) => $this->addPuzzle($id, PlayerFixture::PLAYER_REGULAR_USER_ID),
        ];

        foreach ($saves as $save => $dispatch) {
            $id = Uuid::uuid7();
            $debugDataHolder->reset();

            $dispatch($id);

            /** @var list<array{sql: string, params: array<mixed>}> $executed */
            $executed = $debugDataHolder->getData()['default'] ?? [];
            $statements = array_values(array_filter(
                $executed,
                static fn (array $query): bool => preg_match('/^"?(SAVEPOINT|RELEASE|START|BEGIN|COMMIT)\b/i', $query['sql']) !== 1,
            ));

            self::assertNotEmpty($statements, $save);
            self::assertStringContainsString('pg_advisory_xact_lock', $statements[0]['sql'], $save);
            self::assertContains($id->toString(), $statements[0]['params'], $save);
        }
    }

    public function testAStopwatchThatNeverRanRefusesTheSaveBeforeAnythingIsCreated(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $stopwatchId = Uuid::uuid7();
        $player = $entityManager->find(Player::class, PlayerFixture::PLAYER_REGULAR);
        self::assertNotNull($player);
        $entityManager->persist(new Stopwatch($stopwatchId, $player, null));
        $entityManager->flush();

        $timeId = Uuid::uuid7();

        self::assertInstanceOf(StopwatchCouldNotBeFinished::class, $this->refusalOf(fn () => $this->addTime($timeId, stopwatchId: $stopwatchId->toString())));

        // Nothing waits in the entity manager for a later flush either
        $entityManager->flush();

        self::assertSame(0, $this->countTimes($timeId));
        self::assertSame('not_started', $this->stopwatchStatus($stopwatchId->toString()));
    }

    public function testTheIdOfAnotherPlayersResultIsRefused(): void
    {
        $timeId = Uuid::uuid7();
        $this->addTime($timeId);

        $refusal = $this->refusalOf(fn () => $this->addTime($timeId, userId: PlayerFixture::PLAYER_WITH_FAVORITES_USER_ID));

        self::assertInstanceOf(SolvingTimeIdTaken::class, $refusal);
        self::assertSame(PlayerFixture::PLAYER_REGULAR, $this->database->fetchOne(
            'SELECT player_id FROM puzzle_solving_time WHERE id = :id',
            ['id' => $timeId->toString()],
        ));
    }

    public function testAnIdenticalResultSavedMomentsAgoIsAnsweredWithTheSavedOne(): void
    {
        $before = $this->countPlayerTimesOfPuzzle();
        $firstId = Uuid::uuid7();
        $this->addTime($firstId, comment: 'Sunday', finishedAt: new \DateTimeImmutable('2026-09-20'));

        // A new id (no JavaScript, an old open form): the safety net recognises the same result
        $refusal = $this->refusalOf(fn () => $this->addTime(Uuid::uuid7(), comment: 'Sunday', finishedAt: new \DateTimeImmutable('2026-09-20')));

        self::assertInstanceOf(SolvingTimeAlreadySaved::class, $refusal);
        self::assertSame($firstId->toString(), $refusal->timeId);
        self::assertSame($before + 1, $this->countPlayerTimesOfPuzzle());
    }

    public function testAnIdenticalGroupResultSavedMomentsAgoIsAnsweredWithTheSavedOne(): void
    {
        $firstId = Uuid::uuid7();
        $this->addTime($firstId, groupPlayers: ['#player3', 'Guest Jana']);

        // The same people entered in another order are the same group
        $refusal = $this->refusalOf(fn () => $this->addTime(Uuid::uuid7(), groupPlayers: ['Guest Jana', '#player3']));

        self::assertInstanceOf(SolvingTimeAlreadySaved::class, $refusal);
        self::assertSame($firstId->toString(), $refusal->timeId);
    }

    public function testASoloResultIsNotTheSameAsAGroupResult(): void
    {
        $before = $this->countPlayerTimesOfPuzzle();
        $this->addTime(Uuid::uuid7(), groupPlayers: ['#player3']);
        $this->addTime(Uuid::uuid7());

        self::assertSame($before + 2, $this->countPlayerTimesOfPuzzle());
    }

    public function testAnIdenticalResultSavedElevenSecondsAgoIsSaved(): void
    {
        $before = $this->countPlayerTimesOfPuzzle();
        $firstId = Uuid::uuid7();
        $this->addTime($firstId);

        $this->database->executeStatement(
            "UPDATE puzzle_solving_time SET tracked_at = tracked_at - INTERVAL '11 seconds' WHERE id = :id",
            ['id' => $firstId->toString()],
        );

        $this->addTime(Uuid::uuid7());

        self::assertSame($before + 2, $this->countPlayerTimesOfPuzzle());
    }

    public function testAResultDifferingInAnyFieldIsSaved(): void
    {
        $before = $this->countPlayerTimesOfPuzzle();
        $this->addTime(Uuid::uuid7(), comment: 'First attempt today');
        $this->addTime(Uuid::uuid7(), comment: 'Second attempt today');
        $this->addTime(Uuid::uuid7(), time: '01:00:01');
        $this->addTime(Uuid::uuid7(), finishedAt: new \DateTimeImmutable('2026-09-19'));
        $this->addTime(Uuid::uuid7(), unboxed: true);

        self::assertSame($before + 5, $this->countPlayerTimesOfPuzzle());
    }

    public function testSafetyNetLooksUpOnlyTheTrackersRecentResults(): void
    {
        $firstId = Uuid::uuid7();
        $this->addTime($firstId, comment: null);

        $query = self::getContainer()->get(GetRecentIdenticalSolvingTime::class);

        $lookup = fn (string $playerId, bool $hasPhoto) => $query->savedBy(
            playerId: $playerId,
            puzzleId: PuzzleFixture::PUZZLE_1500_02,
            secondsToSolve: 3600,
            finishedAt: null,
            teamCompositionKey: null,
            competitionId: null,
            roundId: null,
            firstAttempt: false,
            unboxed: false,
            comment: null,
            hasPhoto: $hasPhoto,
        );

        self::assertSame($firstId->toString(), $lookup(PlayerFixture::PLAYER_REGULAR, false));
        self::assertNull($lookup(PlayerFixture::PLAYER_REGULAR, true), 'a photo makes it a different result');
        self::assertNull($lookup(PlayerFixture::PLAYER_WITH_FAVORITES, false));
    }

    public function testResultIsRecordedWithWhereItCameFrom(): void
    {
        $timeId = Uuid::uuid7();
        $this->addTime($timeId, createdVia: SolvingTimeSource::Api);

        self::assertSame('api', $this->database->fetchOne(
            'SELECT created_via FROM puzzle_solving_time WHERE id = :id',
            ['id' => $timeId->toString()],
        ));
    }

    public function testSavingTheResultFinishesItsStopwatchInTheSameSave(): void
    {
        $timeId = Uuid::uuid7();
        $this->addTime($timeId, puzzleId: PuzzleFixture::PUZZLE_500_01, time: '00:30:00', stopwatchId: StopwatchFixture::STOPWATCH_PAUSED, createdVia: SolvingTimeSource::Stopwatch);

        self::assertSame('finished', $this->stopwatchStatus(StopwatchFixture::STOPWATCH_PAUSED));
        self::assertSame('stopwatch', $this->database->fetchOne(
            'SELECT created_via FROM puzzle_solving_time WHERE id = :id',
            ['id' => $timeId->toString()],
        ));
    }

    public function testAnAlreadyFinishedStopwatchDoesNotStopTheSave(): void
    {
        $this->addTime(Uuid::uuid7(), puzzleId: PuzzleFixture::PUZZLE_500_01, time: '00:30:00', stopwatchId: StopwatchFixture::STOPWATCH_PAUSED);

        $secondId = Uuid::uuid7();
        $this->addTime($secondId, puzzleId: PuzzleFixture::PUZZLE_500_01, time: '00:31:00', stopwatchId: StopwatchFixture::STOPWATCH_PAUSED);

        self::assertSame(1, $this->countTimes($secondId));
        self::assertSame('finished', $this->stopwatchStatus(StopwatchFixture::STOPWATCH_PAUSED));
    }

    public function testARunningStopwatchStopsWhenItsResultIsSaved(): void
    {
        $this->addTime(Uuid::uuid7(), puzzleId: PuzzleFixture::PUZZLE_500_01, time: '00:30:00', stopwatchId: StopwatchFixture::STOPWATCH_RUNNING);

        self::assertSame('finished', $this->stopwatchStatus(StopwatchFixture::STOPWATCH_RUNNING));
    }

    public function testAnotherPlayersStopwatchRefusesTheSave(): void
    {
        $timeId = Uuid::uuid7();

        try {
            $this->addTime($timeId, userId: PlayerFixture::PLAYER_WITH_FAVORITES_USER_ID, stopwatchId: StopwatchFixture::STOPWATCH_PAUSED);
            self::fail('Saving with another player\'s stopwatch must be refused');
        } catch (CanNotModifyOtherPlayersTime) {
        }

        self::assertSame(0, $this->countTimes($timeId));
        self::assertSame('paused', $this->stopwatchStatus(StopwatchFixture::STOPWATCH_PAUSED));
    }

    public function testTheSameTrackingIdIsAnsweredWithTheSavedTracking(): void
    {
        $trackingId = Uuid::uuid7();
        $this->addTracking($trackingId, PlayerFixture::PLAYER_REGULAR_USER_ID);

        $refusal = $this->refusalOf(fn () => $this->addTracking($trackingId, PlayerFixture::PLAYER_REGULAR_USER_ID));
        self::assertInstanceOf(SolvingTimeAlreadySaved::class, $refusal);
        self::assertSame($trackingId->toString(), $refusal->timeId);

        $refusal = $this->refusalOf(fn () => $this->addTracking($trackingId, PlayerFixture::PLAYER_WITH_FAVORITES_USER_ID));
        self::assertInstanceOf(SolvingTimeIdTaken::class, $refusal);

        self::assertSame(1, $this->countTimes($trackingId));
        self::assertSame('form', $this->database->fetchOne(
            'SELECT created_via FROM puzzle_solving_time WHERE id = :id',
            ['id' => $trackingId->toString()],
        ));
    }

    public function testTheSameNewPuzzleIdOfTheSamePlayerCreatesNothingMore(): void
    {
        $puzzleId = Uuid::uuid7();
        $this->addPuzzle($puzzleId, PlayerFixture::PLAYER_REGULAR_USER_ID);
        $this->addPuzzle($puzzleId, PlayerFixture::PLAYER_REGULAR_USER_ID);

        /** @var int|string $count */
        $count = $this->database->fetchOne('SELECT COUNT(*) FROM puzzle WHERE id = :id', ['id' => $puzzleId->toString()]);
        self::assertSame(1, (int) $count);

        $refusal = $this->refusalOf(fn () => $this->addPuzzle($puzzleId, PlayerFixture::PLAYER_WITH_FAVORITES_USER_ID));
        self::assertInstanceOf(PuzzleIdTaken::class, $refusal);
    }

    public function testTheNewPuzzleIsCorrectedByItsAuthorUntilSomethingUsesIt(): void
    {
        $puzzleId = Uuid::uuid7();
        $this->addPuzzle($puzzleId, PlayerFixture::PLAYER_REGULAR_USER_ID, piecesCount: 5000);

        self::assertSame("\nsent twice\n", $this->database->fetchOne('SELECT search_names FROM puzzle WHERE id = :id', ['id' => $puzzleId->toString()]));

        // The result saved with it was refused, the form came back corrected
        $this->addPuzzle(
            $puzzleId,
            PlayerFixture::PLAYER_REGULAR_USER_ID,
            name: 'Sent twice, corrected',
            piecesCount: 500,
            // A barcode typed with its GTIN-14 zero - stored without it
            ean: '04005556123452',
            alternativeNames: new PuzzleNames([new PuzzleName('Poslané dvakrát', 'cs')]),
        );

        /** @var array{name: string, pieces_count: int, ean: string, image: string, alternative_names: string, search_names: string, search_codes: string} $puzzle */
        $puzzle = $this->database->fetchAssociative('SELECT name, pieces_count, ean, image, alternative_names, search_names, search_codes FROM puzzle WHERE id = :id', ['id' => $puzzleId->toString()]);
        self::assertSame('Sent twice, corrected', $puzzle['name']);
        self::assertSame(500, $puzzle['pieces_count']);
        self::assertSame('4005556123452', $puzzle['ean']);
        self::assertStringContainsString('sent-twice-corrected-500', $puzzle['image']);
        self::assertSame([['name' => 'Poslané dvakrát', 'language' => 'cs']], PuzzleNames::fromJson($puzzle['alternative_names'])->toArray());
        self::assertSame("\nsent twice, corrected\nposlane dvakrat\n", $puzzle['search_names']);
        self::assertSame("\ne:4005556123452\n", $puzzle['search_codes']);

        // Once a result uses it, the puzzle stays as it is
        $this->addTime(Uuid::uuid7(), puzzleId: $puzzleId->toString(), time: '00:41:00');
        $this->addPuzzle($puzzleId, PlayerFixture::PLAYER_REGULAR_USER_ID, name: 'Changed afterwards', piecesCount: 1000);

        self::assertSame('Sent twice, corrected', $this->database->fetchOne('SELECT name FROM puzzle WHERE id = :id', ['id' => $puzzleId->toString()]));
    }

    public function testANewBrandTypedAgainIsNotASecondBrand(): void
    {
        $puzzleId = Uuid::uuid7();
        $this->addPuzzle($puzzleId, PlayerFixture::PLAYER_REGULAR_USER_ID, piecesCount: 5000, brand: 'Brand New Brand');
        $this->addPuzzle($puzzleId, PlayerFixture::PLAYER_REGULAR_USER_ID, piecesCount: 500, brand: 'Brand New Brand');

        self::assertSame(1, $this->database->fetchOne("SELECT COUNT(*) FROM manufacturer WHERE name = 'Brand New Brand'"));
        self::assertSame(500, $this->database->fetchOne('SELECT pieces_count FROM puzzle WHERE id = :id', ['id' => $puzzleId->toString()]));
    }

    public function testPreventedSaveIsRecorded(): void
    {
        $timeId = Uuid::uuid7();
        $this->addTime($timeId);

        $this->messageBus->dispatch(new RecordDuplicatePrevention(
            playerId: PlayerFixture::PLAYER_REGULAR,
            kind: DuplicatePreventionKind::ResendCaught,
            timeId: $timeId->toString(),
            puzzleId: PuzzleFixture::PUZZLE_1500_02,
            via: SolvingTimeSource::Form,
        ));

        /** @var array{kind: string, puzzle_id: string, via: string}|false $row */
        $row = $this->database->fetchAssociative(
            'SELECT kind, puzzle_id, via FROM result_duplicate_prevention WHERE player_id = :playerId AND time_id = :timeId',
            ['playerId' => PlayerFixture::PLAYER_REGULAR, 'timeId' => $timeId->toString()],
        );

        self::assertNotFalse($row);
        self::assertSame(['kind' => 'resend_caught', 'puzzle_id' => PuzzleFixture::PUZZLE_1500_02, 'via' => 'form'], $row);
    }

    /**
     * @param list<string> $groupPlayers
     */
    private function addTime(
        UuidInterface $timeId,
        string $userId = PlayerFixture::PLAYER_REGULAR_USER_ID,
        string $puzzleId = PuzzleFixture::PUZZLE_1500_02,
        string $time = '01:00:00',
        null|string $comment = null,
        array $groupPlayers = [],
        null|\DateTimeImmutable $finishedAt = null,
        bool $unboxed = false,
        null|string $stopwatchId = null,
        null|SolvingTimeSource $createdVia = SolvingTimeSource::Form,
    ): void {
        $this->messageBus->dispatch(new AddPuzzleSolvingTime(
            timeId: $timeId,
            userId: $userId,
            puzzleId: $puzzleId,
            competitionId: null,
            time: $time,
            comment: $comment,
            finishedPuzzlesPhoto: null,
            groupPlayers: $groupPlayers,
            finishedAt: $finishedAt,
            firstAttempt: false,
            unboxed: $unboxed,
            createdVia: $createdVia,
            stopwatchId: $stopwatchId,
        ));
    }

    private function addTracking(UuidInterface $trackingId, string $userId): void
    {
        $this->messageBus->dispatch(new AddPuzzleTracking(
            trackingId: $trackingId,
            userId: $userId,
            puzzleId: PuzzleFixture::PUZZLE_1500_02,
            comment: null,
            finishedPuzzlesPhoto: null,
            groupPlayers: [],
            finishedAt: null,
            createdVia: SolvingTimeSource::Form,
        ));
    }

    private function addPuzzle(
        UuidInterface $puzzleId,
        string $userId,
        string $name = 'Sent twice',
        int $piecesCount = 1000,
        null|string $ean = null,
        string $brand = ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
        PuzzleNames $alternativeNames = new PuzzleNames(),
    ): void {
        $imagePath = tempnam(sys_get_temp_dir(), 'puzzle_test_') . '.jpg';
        $image = imagecreatetruecolor(10, 10);
        assert($image !== false);
        imagejpeg($image, $imagePath);

        $this->messageBus->dispatch(new AddPuzzle(
            puzzleId: $puzzleId,
            userId: $userId,
            puzzleName: $name,
            brand: $brand,
            piecesCount: $piecesCount,
            puzzlePhoto: new UploadedFile($imagePath, 'box.jpg', 'image/jpeg', null, true),
            eans: EanList::fromInputs([$ean]),
            brandCodes: BrandCodeList::fromStored(null),
            alternativeNames: $alternativeNames,
        ));
    }

    private function refusalOf(\Closure $dispatch): null|\Throwable
    {
        try {
            $dispatch();
        } catch (HandlerFailedException $exception) {
            return $exception->getPrevious();
        }

        self::fail('The save was expected to be refused');
    }

    private function countTimes(UuidInterface $timeId): int
    {
        /** @var int|string $count */
        $count = $this->database->fetchOne('SELECT COUNT(*) FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId->toString()]);

        return (int) $count;
    }

    private function countPlayerTimesOfPuzzle(): int
    {
        /** @var int|string $count */
        $count = $this->database->fetchOne(
            'SELECT COUNT(*) FROM puzzle_solving_time WHERE player_id = :playerId AND puzzle_id = :puzzleId',
            ['playerId' => PlayerFixture::PLAYER_REGULAR, 'puzzleId' => PuzzleFixture::PUZZLE_1500_02],
        );

        return (int) $count;
    }

    private function stopwatchStatus(string $stopwatchId): mixed
    {
        return $this->database->fetchOne('SELECT status FROM stopwatch WHERE id = :id', ['id' => $stopwatchId]);
    }
}
