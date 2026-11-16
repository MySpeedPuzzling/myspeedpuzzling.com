<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\FirstTryAlreadyTaken;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Message\AddUnfinishedPuzzleSolvingTime;
use SpeedPuzzling\Web\Message\EditPuzzleSolvingTime;
use SpeedPuzzling\Web\Message\EditUnfinishedPuzzleSolvingTime;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\SeriesEditionScenario;
use SpeedPuzzling\Web\Value\RoundCategory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * The unfinished write path stays beside the finished one (the add form must never break - Jan, 2026-11-14), so what
 * both share must stay the same (docs/features/competitions-management/unfinished-results-plan.md, step 3): the same
 * input through AddPuzzleSolvingTime and AddUnfinishedPuzzleSolvingTime (and the two edits) stores the same row apart
 * from the time and the unfinished columns - event, edition and round, pair/team, first try, comment, photo, source -
 * and queues the same follow-ups (achievements, XP).
 */
final class UnfinishedWritePathParityTest extends KernelTestCase
{
    private const string USER = PlayerFixture::PLAYER_REGULAR_USER_ID;

    private const string SHARED_COLUMNS = 'player_id, puzzle_id, team, puzzling_team_id, puzzlers_count, puzzling_type, competition_id,
        competition_round_id, competition_series_id, series_edition_match, first_attempt, unboxed, comment, finished_at, created_via,
        verified, suspicious, pieces_count_snapshot, finished_puzzle_photo IS NOT NULL AS has_photo,
        finished_puzzle_photo_upload_metadata IS NOT NULL AS has_photo_metadata';

    private MessageBusInterface $messageBus;
    private Connection $database;
    private SeriesEditionScenario $scenario;
    private InMemoryTransport $async;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
        $this->scenario = new SeriesEditionScenario(self::getContainer());

        $async = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $async);
        $this->async = $async;
    }

    public function testAPairsResultOfAnExplicitEditionIsStoredAlike(): void
    {
        [, $puzzle, $edition] = $this->pairsRound();

        [$finished, $finishedQueued] = $this->addFinished($puzzle, competitionId: $edition);
        [$unfinished, $unfinishedQueued] = $this->addUnfinished($puzzle, competitionId: $edition);

        self::assertSame($this->shared($finished), $this->shared($unfinished));
        self::assertNotNull($this->shared($unfinished)['competition_round_id']);
        self::assertSame($finishedQueued, $unfinishedQueued);
    }

    public function testASeriesPickIsResolvedAlike(): void
    {
        [$series, $puzzle] = $this->pairsRound();

        [$finished] = $this->addFinished($puzzle, seriesId: $series);
        [$unfinished] = $this->addUnfinished($puzzle, seriesId: $series);

        self::assertSame($this->shared($finished), $this->shared($unfinished));
        self::assertSame('puzzle', $this->shared($unfinished)['series_edition_match']);
    }

    public function testAnEditIsStoredAlike(): void
    {
        [, $puzzle, $edition] = $this->pairsRound();

        [$finished] = $this->addFinished($puzzle, competitionId: $edition, groupPlayers: ['Ben Guest']);
        [$unfinished] = $this->addUnfinished($puzzle, competitionId: $edition, groupPlayers: ['Ben Guest']);

        $this->async->reset();
        $this->messageBus->dispatch(new EditPuzzleSolvingTime(
            currentUserId: self::USER,
            puzzleSolvingTimeId: $finished,
            competitionId: $edition,
            time: '01:50:00',
            comment: 'Edited',
            groupPlayers: ['Anna Guest'],
            finishedAt: new DateTimeImmutable('2026-03-03'),
            finishedPuzzlesPhoto: $this->photo(),
            firstAttempt: false,
            unboxed: false,
        ));
        $finishedQueued = $this->queued();

        $this->async->reset();
        $this->messageBus->dispatch(new EditUnfinishedPuzzleSolvingTime(
            currentUserId: self::USER,
            puzzleSolvingTimeId: $unfinished,
            competitionId: $edition,
            piecesPlaced: 420,
            totalTime: null,
            comment: 'Edited',
            groupPlayers: ['Anna Guest'],
            finishedAt: new DateTimeImmutable('2026-03-03'),
            finishedPuzzlesPhoto: $this->photo(),
            firstAttempt: false,
            unboxed: false,
        ));
        $unfinishedQueued = $this->queued();

        self::assertSame($this->shared($finished), $this->shared($unfinished));
        self::assertSame($finishedQueued, $unfinishedQueued);
    }

    public function testAnUnfinishedFirstTryIsTheFirstTryOfThePuzzle(): void
    {
        [, $puzzle, $edition] = $this->pairsRound();

        $this->addUnfinished($puzzle, competitionId: $edition, firstAttempt: true);

        try {
            $this->addFinished($puzzle, competitionId: $edition, firstAttempt: true);
            self::fail('Expected FirstTryAlreadyTaken');
        } catch (HandlerFailedException $exception) {
            self::assertInstanceOf(FirstTryAlreadyTaken::class, $exception->getPrevious());
        }
    }

    /**
     * @return array{string, string, string} series, puzzle, edition
     */
    private function pairsRound(): array
    {
        $series = $this->scenario->series();
        $puzzle = $this->scenario->puzzle();
        $edition = $this->scenario->edition($series, 'Jam No. 21', '2026-03-02');
        $this->scenario->round($edition, RoundCategory::Duo, '2026-03-02 19:00', puzzleIds: [$puzzle]);

        return [$series, $puzzle, $edition];
    }

    /**
     * @param array<string> $groupPlayers
     * @return array{string, list<string>}
     */
    private function addFinished(string $puzzle, null|string $competitionId = null, null|string $seriesId = null, array $groupPlayers = ['Anna Guest'], bool $firstAttempt = false): array
    {
        $this->async->reset();
        $id = Uuid::uuid7();

        $this->messageBus->dispatch(new AddPuzzleSolvingTime(
            timeId: $id,
            userId: self::USER,
            puzzleId: $puzzle,
            competitionId: $competitionId,
            time: '01:40:00',
            comment: 'Round one',
            finishedPuzzlesPhoto: $this->photo(),
            groupPlayers: $groupPlayers,
            finishedAt: new DateTimeImmutable('2026-03-02'),
            firstAttempt: $firstAttempt,
            unboxed: true,
            seriesId: $seriesId,
        ));

        return [$id->toString(), $this->queued()];
    }

    /**
     * @param array<string> $groupPlayers
     * @return array{string, list<string>}
     */
    private function addUnfinished(string $puzzle, null|string $competitionId = null, null|string $seriesId = null, array $groupPlayers = ['Anna Guest'], bool $firstAttempt = false): array
    {
        $this->async->reset();
        $id = Uuid::uuid7();

        $this->messageBus->dispatch(new AddUnfinishedPuzzleSolvingTime(
            timeId: $id,
            userId: self::USER,
            puzzleId: $puzzle,
            competitionId: $competitionId,
            piecesPlaced: 450,
            totalTime: '02:10:00',
            comment: 'Round one',
            finishedPuzzlesPhoto: $this->photo(),
            groupPlayers: $groupPlayers,
            finishedAt: new DateTimeImmutable('2026-03-02'),
            firstAttempt: $firstAttempt,
            unboxed: true,
            seriesId: $seriesId,
        ));

        return [$id->toString(), $this->queued()];
    }

    /**
     * @return array<string, mixed>
     */
    private function shared(string $timeId): array
    {
        $row = $this->database->fetchAssociative('SELECT ' . self::SHARED_COLUMNS . ' FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId]);
        self::assertIsArray($row);

        return $row;
    }

    /**
     * The follow-ups queued, by class - their ids differ, so only what kind and how many
     *
     * @return list<string>
     */
    private function queued(): array
    {
        $classes = array_map(static fn (Envelope $envelope): string => $envelope->getMessage()::class, array_values($this->async->getSent()));
        sort($classes);

        return $classes;
    }

    private function photo(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'unfinished_parity_') . '.jpg';
        $image = imagecreatetruecolor(10, 10);
        assert($image !== false);
        imagejpeg($image, $path);

        return new UploadedFile($path, 'finished.jpg', 'image/jpeg', null, true);
    }
}
