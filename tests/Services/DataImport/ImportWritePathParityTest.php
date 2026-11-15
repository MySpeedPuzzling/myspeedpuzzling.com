<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\DataImport;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\DataImport;
use SpeedPuzzling\Web\Entity\DataImportRow;
use SpeedPuzzling\Web\Entity\DataImportSheet;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Entity\WishListItem;
use SpeedPuzzling\Web\Exceptions\CanNotAssembleEmptyGroup;
use SpeedPuzzling\Web\Exceptions\FirstTryAlreadyTaken;
use SpeedPuzzling\Web\Exceptions\SuspiciousPpm;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Message\AddPuzzleTracking;
use SpeedPuzzling\Web\Message\AwardXpForSolvingTime;
use SpeedPuzzling\Web\Message\BackfillSolvingTimePredictions;
use SpeedPuzzling\Web\Message\RecalculateBadgesForPlayer;
use SpeedPuzzling\Web\Message\RecalculateXpForPlayer;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\DataImport\ImportedResultsFollowUp;
use SpeedPuzzling\Web\Services\DataImport\ImportedResultWriter;
use SpeedPuzzling\Web\Services\Xp\XpRecomputer;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Value\DataImport\ImportedResult;
use SpeedPuzzling\Web\Value\DataImport\ImportedResultOutcome;
use SpeedPuzzling\Web\Value\DataImport\ImportedResultRefusal;
use SpeedPuzzling\Web\Value\DataImport\ImportedResultStatus;
use SpeedPuzzling\Web\Value\DataImportFileKind;
use SpeedPuzzling\Web\Value\DatePrecision;
use SpeedPuzzling\Web\Value\SolvingTimeSource;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * The import's writer and the add form's handlers write the same result (docs/features/data-import/writer.md).
 *
 * The same input is saved twice: through the real add path (AddPuzzleSolvingTime, or AddPuzzleTracking for a result
 * without a time) for one new player, and through ImportedResultWriter for a twin - another new player, the same in
 * everything. Then the two `puzzle_solving_time` rows are compared column by column, and the tables around them.
 *
 * **Every column of `puzzle_solving_time` is decided here.** The test reads the column list from the database: a
 * column added later is in none of the three lists below and fails the test until somebody decides whether an
 * imported result holds the same value as a typed one (SAME), a value that differs only by who the twin is (TWIN),
 * or a documented difference (DIFFERENT - with a line in writer.md's table).
 *
 * A rule changed in the add handlers and not in the writer shows up as a differing column or side table.
 */
final class ImportWritePathParityTest extends KernelTestCase
{
    /** Equal in both rows, value for value */
    private const array SAME = [
        'seconds_to_solve',
        'puzzle_id',
        'verified',
        'finished_at',
        'comment',
        'finished_puzzle_photo',
        'finished_puzzle_photo_upload_metadata',
        'first_attempt',
        'unboxed',
        'puzzlers_count',
        'puzzling_type',
        'pieces_count_snapshot',
        'suspicious',
        // Never linked to an event - by the form here (no event picked), by the import always
        'competition_id',
        'competition_round_id',
        'competition_series_id',
        'series_edition_match',
        'pieces_placed',
        'qualified',
        'finished_later_seconds',
    ];

    /** Differ only because the twins are two people: compared after the twin's own id is taken out */
    private const array TWIN = [
        'id',
        'player_id',
        'team',
        'puzzling_team_id',
    ];

    /** The documented differences (writer.md "The differences") - each asserted by name in assertDifferences() */
    private const array DIFFERENT = [
        'tracked_at',
        'created_via',
        // An imported result is never predicted live - its prediction is reconstructed
        'predictable',
        'prediction_method',
        'predicted_seconds',
        'predicted_range_low_seconds',
        'predicted_range_high_seconds',
        'predicted_range80_low_seconds',
        'predicted_range80_high_seconds',
        'predicted_attempt_number',
        'prediction_last_time_seconds',
        'prediction_source',
        'prediction_computed_at',
        'prediction_model_version',
        // How exactly the solve day is known (docs/features/data-import/date-precision.md): a typed result always
        // knows its day (NULL - the forms cannot say anything else); an imported one holds what its file knows -
        // NULL for a day, as in every case of the provider, `month` / `year` otherwise (testAMonthOrAYearIs...)
        'finished_at_precision',
    ];

    private const string PUZZLE = PuzzleFixture::PUZZLE_1000_05;
    private const string REGISTERED_PARTNER = PlayerFixture::PLAYER_WITH_FAVORITES;
    private const string REGISTERED_PARTNER_CODE = '#player3';
    private const string FOLLOWER = PlayerFixture::PLAYER_ADMIN;
    private const string BEFORE_OWN_RESULT = 'the twin solved the puzzle before';
    private const string BEFORE_PARTNERS_RESULT = 'the partner solved the puzzle before, as a first try';

    private MessageBusInterface $bus;
    private Connection $database;
    private EntityManagerInterface $entityManager;
    private InMemoryTransport $asyncTransport;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->bus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $this->asyncTransport = $transport;
    }

    /**
     * Today is TestClock's 14 October 2026, noon.
     *
     * @return iterable<string, array{seconds: null|int, day: string, groupPlayers: list<string>, firstTry: bool, comment: null|string, before: null|string, zone: null|string, importPeople: null|list<string>}>
     */
    public static function inputs(): iterable
    {
        $base = ['seconds' => 5400, 'day' => '2026-06-01', 'groupPlayers' => [], 'firstTry' => false, 'comment' => null, 'before' => null, 'zone' => null, 'importPeople' => null];

        yield 'solo with a time' => $base;
        yield 'solo without a time' => ['seconds' => null] + $base;
        yield 'a pair with a registered partner' => ['groupPlayers' => [self::REGISTERED_PARTNER_CODE]] + $base;
        yield 'a team with a guest' => ['groupPlayers' => [self::REGISTERED_PARTNER_CODE, 'Anna the Guest']] + $base;
        yield 'a pair of guests without a time' => ['seconds' => null, 'groupPlayers' => ['Ben the Guest']] + $base;
        yield 'a first try' => ['firstTry' => true] + $base;
        yield 'a first try of a pair with a guest' => ['firstTry' => true, 'groupPlayers' => ['Ben the Guest']] + $base;
        yield 'with a comment' => ['comment' => 'Rainy Sunday, a missing piece under the sofa'] + $base;
        yield 'back-dated a year' => ['day' => '2025-10-14'] + $base;
        yield 'solved yesterday' => ['day' => '2026-10-13'] + $base;
        yield 'solved today' => ['day' => '2026-10-14'] + $base;
        yield 'a puzzle the player already solved' => ['before' => self::BEFORE_OWN_RESULT] + $base;
        yield 'a first try after an earlier result without the tag' => ['before' => self::BEFORE_OWN_RESULT, 'firstTry' => true] + $base;
        yield 'a partner who holds an earlier result' => ['before' => self::BEFORE_PARTNERS_RESULT, 'groupPlayers' => [self::REGISTERED_PARTNER_CODE]] + $base;
        yield 'a player with a time zone' => ['zone' => 'America/Los_Angeles'] + $base;
        // The form's guest box holds "Smith, John" as two people; the import names its people one per entry
        yield 'two guests typed with a comma' => ['groupPlayers' => ['Smith, John'], 'importPeople' => ['Smith', 'John']] + $base;
        yield 'the longest time the form takes' => ['seconds' => ImportedResult::MAX_SECONDS] + $base;
    }

    /**
     * @param list<string> $groupPlayers
     * @param null|list<string> $importPeople
     */
    #[DataProvider('inputs')]
    public function testTheImportWritesWhatTheAddFormWrites(null|int $seconds, string $day, array $groupPlayers, bool $firstTry, null|string $comment, null|string $before, null|string $zone, null|array $importPeople): void
    {
        $typist = $this->newPlayer('typist', $zone);
        $importer = $this->newPlayer('importer', $zone);

        if ($before === self::BEFORE_OWN_RESULT) {
            $this->saveThroughTheAddForm($typist, 7000, '2026-07-01', [], false, null);
            $this->saveThroughTheAddForm($importer, 7000, '2026-07-01', [], false, null);
        }

        if ($before === self::BEFORE_PARTNERS_RESULT) {
            $this->saveThroughTheAddForm(self::REGISTERED_PARTNER, 7000, '2026-05-01', [], true, null);
        }

        $this->bothAreFollowedAndWishForThePuzzle($typist, $importer);
        $statisticsBefore = $this->solvedTimesCount();

        // The add form's path
        $this->asyncTransport->reset();
        $typedId = $this->saveThroughTheAddForm($typist, $seconds, $day, $groupPlayers, $firstTry, $comment);
        $typedQueue = $this->queued();
        $statisticsAfterTyped = $this->solvedTimesCount();

        // The import's path
        $this->asyncTransport->reset();
        $outcome = $this->saveThroughTheImport($importer, $seconds, $day, $importPeople ?? $groupPlayers, $firstTry, $comment);
        self::assertTrue($outcome->isAdded(), 'Refused: ' . ($outcome->refusal->value ?? '-'));
        self::assertNotNull($outcome->solvingTimeId);
        $importedId = $outcome->solvingTimeId;

        $typed = $this->row($typedId);
        $imported = $this->row($importedId);

        $this->assertEveryColumnIsDecided(array_keys($typed));

        foreach (self::SAME as $column) {
            self::assertSame($typed[$column], $imported[$column], sprintf('Column "%s" differs between a typed and an imported result.', $column));
        }

        $this->assertTwinColumns($typed, $imported, $typist, $importer, $groupPlayers !== []);
        $this->assertDifferences($typed, $imported, $day, $zone ?? 'UTC', isLivePredicted: $seconds !== null && $groupPlayers === [] && $day >= '2026-10-13');

        // The pair/team: another exact set of people (the twin instead of the twin), the same shape
        self::assertSame($this->teamShape($typed['puzzling_team_id'], $typist), $this->teamShape($imported['puzzling_team_id'], $importer));

        // --- What happens around the row ---

        // Followers: told about a typed result, never about an imported one
        self::assertSame(1, $this->notificationsAbout($typedId));
        self::assertSame(0, $this->notificationsAbout($importedId));

        // "Remove from my wish list when solved": honoured both ways - by the event's handler, by the writer itself
        self::assertSame(0, $this->wishListItemsOf($typist));
        self::assertSame(0, $this->wishListItemsOf($importer));
        self::assertNotNull($outcome->removedWishListItem);

        // Nothing is deleted by either
        self::assertSame(0, $this->number('SELECT COUNT(*) FROM solving_time_tombstone WHERE solving_time_id IN (:a, :b)', ['a' => $typedId, 'b' => $importedId]));

        // The jobs: per result by the form, none by the writer - the batch's follow-up queues them
        $members = $groupPlayers !== [] && in_array(self::REGISTERED_PARTNER_CODE, $groupPlayers, true) ? [self::REGISTERED_PARTNER] : [];
        $isBackDated = $day < '2026-10-13';
        $awaitsPrediction = $seconds !== null && $groupPlayers === [];

        self::assertSame([$typedId], $this->awardedTimeIds($typedQueue));
        self::assertEqualsCanonicalizing([$typist, ...$members], $this->playerIds($typedQueue[RecalculateBadgesForPlayer::class] ?? []));
        self::assertSame($awaitsPrediction && $isBackDated ? [$typist] : [], $this->playerIds($typedQueue[BackfillSolvingTimePredictions::class] ?? []));
        self::assertSame([], $typedQueue[RecalculateXpForPlayer::class] ?? []);

        self::assertSame([], $this->queued(), 'The writer queues nothing per result.');
        self::assertSame($awaitsPrediction, $outcome->predictionPending);
        self::assertEqualsCanonicalizing([$importer, ...$members], $outcome->memberPlayerIds);

        // The puzzle's statistics: at once for a typed result, with the follow-up for an imported one - then the same step
        self::assertSame($statisticsAfterTyped, $this->solvedTimesCount());

        $this->entityManager->wrapInTransaction(function () use ($outcome): void {
            self::getContainer()->get(ImportedResultsFollowUp::class)->afterResultsAdded([$outcome]);
        });

        self::assertSame($statisticsAfterTyped - $statisticsBefore, $this->solvedTimesCount() - $statisticsAfterTyped);

        // XP by the very message the form queues, for the result; achievements once per person; never a full rebuild
        $followUpQueue = $this->queued();
        self::assertSame([$importedId], $this->awardedTimeIds($followUpQueue));
        self::assertEqualsCanonicalizing([$importer, ...$members], $this->playerIds($followUpQueue[RecalculateBadgesForPlayer::class] ?? []));
        self::assertSame($awaitsPrediction ? [$importer] : [], $this->playerIds($followUpQueue[BackfillSolvingTimePredictions::class] ?? []));
        self::assertSame([], $followUpQueue[RecalculateXpForPlayer::class] ?? []);

        // XP: what an imported result earns is what a typed result logged at the same moment earns. The one input of
        // the formula the two rows differ in is `tracked_at` (a documented difference: an import's old solve is
        // history, a typed one is logged today) - with it made equal, the two ledgers are the same, entry for entry
        $this->database->executeStatement('UPDATE puzzle_solving_time SET tracked_at = :trackedAt WHERE id = :id', ['trackedAt' => $imported['tracked_at'], 'id' => $typedId]);
        $this->entityManager->clear();

        self::assertSame($this->ledgerAfterRecompute($typist), $this->ledgerAfterRecompute($importer));
    }

    /**
     * What the add form refuses, the import refuses - and neither leaves anything behind.
     *
     * @return iterable<string, array{string, class-string, ImportedResultRefusal}>
     */
    public static function refusedInputs(): iterable
    {
        yield '1000 pieces in five minutes' => ['too-fast', SuspiciousPpm::class, ImportedResultRefusal::TooFast];
        yield 'a second first try' => ['first-try', FirstTryAlreadyTaken::class, ImportedResultRefusal::FirstTryTaken];
        yield 'with nobody but oneself' => ['empty-group', CanNotAssembleEmptyGroup::class, ImportedResultRefusal::EmptyGroup];
    }

    /**
     * @param class-string $formsException
     */
    #[DataProvider('refusedInputs')]
    public function testTheImportRefusesWhatTheAddFormRefuses(string $case, string $formsException, ImportedResultRefusal $importsRefusal): void
    {
        $typist = $this->newPlayer('typist');
        $importer = $this->newPlayer('importer');

        if ($case === 'first-try') {
            $this->saveThroughTheAddForm($typist, 7000, '2026-05-01', [], true, null);
            $this->saveThroughTheAddForm($importer, 7000, '2026-05-01', [], true, null);
        }

        $before = [$this->footprintOf($typist), $this->footprintOf($importer)];
        $seconds = $case === 'too-fast' ? 300 : 5400;
        $people = static fn (string $code): array => $case === 'empty-group' ? ['#' . $code] : [];
        $code = function (string $playerId): string {
            $code = $this->database->fetchOne('SELECT code FROM player WHERE id = :id', ['id' => $playerId]);
            self::assertIsString($code);

            return $code;
        };

        $thrown = null;

        try {
            $this->saveThroughTheAddForm($typist, $seconds, '2026-06-01', $people($code($typist)), $case === 'first-try', null);
        } catch (HandlerFailedException $e) {
            $thrown = $e->getPrevious();
        }

        self::assertInstanceOf($formsException, $thrown);

        $outcome = $this->saveThroughTheImport($importer, $seconds, '2026-06-01', $people($code($importer)), $case === 'first-try', null);

        self::assertSame(ImportedResultStatus::Refused, $outcome->status);
        self::assertSame($importsRefusal, $outcome->refusal);
        self::assertSame(0, $this->number('SELECT COUNT(*) FROM data_import_row WHERE solving_time_id IS NOT NULL AND id = :id', ['id' => $outcome->importRowId]));

        // The form's refused handler leaves its changes in the entity manager (a known trait of a refused handler);
        // what counts is what is saved
        $this->entityManager->clear();
        self::assertSame($before, [$this->footprintOf($typist), $this->footprintOf($importer)]);
    }

    /**
     * The one difference in how people are read (writer.md "The differences"): the form's guest box splits what was
     * typed at commas, the import takes an entry as one person.
     */
    public function testACommaInAnEntryIsTwoPeopleForTheFormAndOneForTheImport(): void
    {
        $typist = $this->newPlayer('typist');
        $importer = $this->newPlayer('importer');

        $typed = $this->row($this->saveThroughTheAddForm($typist, 5400, '2026-06-01', ['Smith, John'], false, null));
        $outcome = $this->saveThroughTheImport($importer, 5400, '2026-06-01', ['Smith, John'], false, null);
        self::assertNotNull($outcome->solvingTimeId);
        $imported = $this->row($outcome->solvingTimeId);

        self::assertSame(3, $typed['puzzlers_count']);
        self::assertSame(['John', 'Smith'], $this->guestsOf($typed['puzzling_team_id']));
        self::assertSame(2, $imported['puzzlers_count']);
        self::assertSame(['Smith, John'], $this->guestsOf($imported['puzzling_team_id']));
    }

    /**
     * The one column only an import can fill (date-precision.md): a file that knows the month or the year of a solve.
     * The result stands on the first day of that period - `finished_at` and noon of it as `tracked_at` - and says so;
     * the same day typed into the form is a day.
     */
    public function testAMonthOrAYearIsStoredAsThatByTheImportAndNeverByTheForm(): void
    {
        $typist = $this->newPlayer('typist');
        $importer = $this->newPlayer('importer');

        $typed = $this->row($this->saveThroughTheAddForm($typist, 5400, '2021-03-01', [], false, null));
        $month = $this->saveThroughTheImport($importer, 5400, '2021-03-01', [], false, null, DatePrecision::Month);
        $year = $this->saveThroughTheImport($importer, 5500, '2021-01-01', [], false, null, DatePrecision::Year);
        self::assertNotNull($month->solvingTimeId);
        self::assertNotNull($year->solvingTimeId);

        self::assertNull($typed['finished_at_precision']);
        self::assertSame('month', $this->row($month->solvingTimeId)['finished_at_precision']);
        self::assertSame('2021-03-01 00:00:00', $this->row($month->solvingTimeId)['finished_at']);
        self::assertSame($typed['finished_at'], $this->row($month->solvingTimeId)['finished_at']);
        self::assertSame('year', $this->row($year->solvingTimeId)['finished_at_precision']);
        self::assertSame('2021-01-01 00:00:00', $this->row($year->solvingTimeId)['finished_at']);
    }

    /**
     * @param list<string> $columns
     */
    private function assertEveryColumnIsDecided(array $columns): void
    {
        $decided = [...self::SAME, ...self::TWIN, ...self::DIFFERENT];

        self::assertSame(
            [],
            array_values(array_diff($columns, $decided)),
            'puzzle_solving_time has a column this test does not know. Decide what an imported result holds in it: add it to SAME, TWIN or DIFFERENT (and to writer.md), and make ImportedResultWriter write it.',
        );
        self::assertSame([], array_values(array_diff($decided, $columns)), 'A column this test compares is gone from puzzle_solving_time.');
        self::assertCount(count($decided), array_unique($decided), 'A column is in two lists.');
    }

    /**
     * @param array<string, mixed> $typed
     * @param array<string, mixed> $imported
     */
    private function assertTwinColumns(array $typed, array $imported, string $typist, string $importer, bool $isGroup): void
    {
        self::assertNotSame($typed['id'], $imported['id']);
        self::assertSame($typist, $typed['player_id']);
        self::assertSame($importer, $imported['player_id']);

        if ($isGroup === false) {
            self::assertNull($typed['team']);
            self::assertNull($imported['team']);
            self::assertNull($typed['puzzling_team_id']);
            self::assertNull($imported['puzzling_team_id']);

            return;
        }

        self::assertIsString($typed['team']);
        self::assertIsString($imported['team']);
        self::assertSame(
            json_decode(str_replace($typist, 'the-twin', $typed['team']), true, flags: JSON_THROW_ON_ERROR),
            json_decode(str_replace($importer, 'the-twin', $imported['team']), true, flags: JSON_THROW_ON_ERROR),
        );
        self::assertNotNull($typed['puzzling_team_id']);
        self::assertNotNull($imported['puzzling_team_id']);
        self::assertNotSame($typed['puzzling_team_id'], $imported['puzzling_team_id']);
    }

    /**
     * @param array<string, mixed> $typed
     * @param array<string, mixed> $imported
     */
    private function assertDifferences(array $typed, array $imported, string $day, string $zone, bool $isLivePredicted): void
    {
        // created_via: where it was saved from
        self::assertSame(SolvingTimeSource::Form->value, $typed['created_via']);
        self::assertSame(SolvingTimeSource::SpreadsheetImport->value, $imported['created_via']);

        // tracked_at: now for a typed result. For an imported one: noon of the solve day in the player's zone, plus
        // the row's position (7) in steps of 11 seconds - and never later than the second before now
        $noon = new DateTimeImmutable($day . ' 12:00:00', new DateTimeZone($zone))->modify('+77 seconds')->setTimezone(new DateTimeZone('UTC'));
        $latest = TestClock::now()->modify('-1 second');

        self::assertSame(TestClock::FROZEN_NOW, $typed['tracked_at']);
        self::assertSame(min($noon, $latest)->format('Y-m-d H:i:s'), $imported['tracked_at']);

        // The prediction: stored live for a typed solo result of today or yesterday, never for an imported one -
        // that one stays "not evaluated" until the backfill reconstructs it, like a back-dated typed result
        // finished_at_precision: the rows of this comparison know their day - nothing stored on either side
        self::assertNull($typed['finished_at_precision'], 'A typed result always knows its day.');
        self::assertNull($imported['finished_at_precision'], 'An imported result of an exact day stores no precision.');

        $predictionColumns = array_values(array_diff(self::DIFFERENT, ['tracked_at', 'created_via', 'finished_at_precision']));

        foreach ($predictionColumns as $column) {
            self::assertNull($imported[$column], sprintf('An imported result is never predicted live ("%s").', $column));
        }

        if ($isLivePredicted) {
            self::assertNotNull($typed['predictable']);
            self::assertSame('live', $typed['prediction_source']);

            return;
        }

        foreach ($predictionColumns as $column) {
            self::assertNull($typed[$column], sprintf('A typed result that is not predicted live has no "%s".', $column));
        }
    }

    private function newPlayer(string $name, null|string $zone = null): string
    {
        $player = self::getContainer()->get(PlayerRepository::class)->getByUserIdCreateIfNotExists('parity|' . $name . '-' . Uuid::uuid7()->toString());
        $player->changeTimezone($zone);
        $this->entityManager->flush();

        return $player->id->toString();
    }

    private function bothAreFollowedAndWishForThePuzzle(string ...$playerIds): void
    {
        $follower = $this->entityManager->find(Player::class, self::FOLLOWER);
        $puzzle = $this->entityManager->find(Puzzle::class, self::PUZZLE);
        self::assertNotNull($follower);
        self::assertNotNull($puzzle);

        foreach ($playerIds as $playerId) {
            $player = $this->entityManager->find(Player::class, $playerId);
            self::assertNotNull($player);

            $follower->addFavoritePlayer($player);
            $this->entityManager->persist(new WishListItem(Uuid::uuid7(), $player, $puzzle, true, TestClock::now()));
        }

        $this->entityManager->flush();
    }

    /**
     * @param list<string> $groupPlayers
     */
    private function saveThroughTheAddForm(string $playerId, null|int $seconds, string $day, array $groupPlayers, bool $firstTry, null|string $comment): string
    {
        $id = Uuid::uuid7();
        $userId = $this->database->fetchOne('SELECT user_id FROM player WHERE id = :id', ['id' => $playerId]);
        self::assertIsString($userId);
        // The form's date field: the picked day at midnight
        $finishedAt = new DateTimeImmutable($day . ' 00:00:00');

        if ($seconds === null) {
            $this->bus->dispatch(new AddPuzzleTracking(
                trackingId: $id,
                userId: $userId,
                puzzleId: self::PUZZLE,
                comment: $comment,
                finishedPuzzlesPhoto: null,
                groupPlayers: $groupPlayers,
                finishedAt: $finishedAt,
                unboxed: false,
                createdVia: SolvingTimeSource::Form,
            ));

            return $id->toString();
        }

        $this->bus->dispatch(new AddPuzzleSolvingTime(
            timeId: $id,
            userId: $userId,
            puzzleId: self::PUZZLE,
            competitionId: null,
            time: sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60),
            comment: $comment,
            finishedPuzzlesPhoto: null,
            groupPlayers: $groupPlayers,
            finishedAt: $finishedAt,
            firstAttempt: $firstTry,
            unboxed: false,
            createdVia: SolvingTimeSource::Form,
        ));

        return $id->toString();
    }

    /**
     * @param list<string> $groupPlayers
     */
    private function saveThroughTheImport(string $playerId, null|int $seconds, string $day, array $groupPlayers, bool $firstTry, null|string $comment, DatePrecision $precision = DatePrecision::Day): ImportedResultOutcome
    {
        $player = $this->entityManager->find(Player::class, $playerId);
        self::assertNotNull($player);

        $import = DataImport::uploadOfFile(Uuid::uuid7(), $player, 'results.xlsx', DataImportFileKind::Xlsx, 1000, 'imports/' . Uuid::uuid7()->toString(), TestClock::now());
        $sheet = new DataImportSheet(Uuid::uuid7(), $import, 0, 'Results');
        $row = new DataImportRow(Uuid::uuid7(), $import, $sheet, 7, 1, 1);
        $sourceKey = '1:' . hash('sha256', Uuid::uuid7()->toString());
        $row->sourceKey = $sourceKey;
        $this->entityManager->persist($import);
        $this->entityManager->persist($sheet);
        $this->entityManager->persist($row);
        $this->entityManager->flush();

        $result = new ImportedResult(
            importRowId: $row->id->toString(),
            sourceKey: $sourceKey,
            playerId: $playerId,
            puzzleId: self::PUZZLE,
            seconds: $seconds,
            solvedDay: $day,
            timezone: ImportedResult::zoneOf($player),
            firstTry: $firstTry,
            groupPlayers: $groupPlayers,
            comment: $comment,
            position: $row->rowNumber,
            precision: $precision,
        );

        // As stage 6b's handler will: inside its transaction, flushed by the transaction's end
        return $this->entityManager->wrapInTransaction(
            static fn (): ImportedResultOutcome => self::getContainer()->get(ImportedResultWriter::class)->write($result),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $timeId): array
    {
        $row = $this->database->fetchAssociative('SELECT * FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId]);
        self::assertIsArray($row);

        return $row;
    }

    /**
     * The people of the pair/team without the twin: who they are and where they stand.
     *
     * @return list<array<string, mixed>>
     */
    private function teamShape(mixed $puzzlingTeamId, string $twinId): array
    {
        if ($puzzlingTeamId === null) {
            return [];
        }

        return $this->database->fetchAllAssociative(
            "SELECT CASE WHEN player_id = :twin THEN 'the-twin' ELSE player_id::text END AS player, guest_name, position
            FROM puzzling_team_member WHERE team_id = :team ORDER BY position, guest_name",
            ['team' => $puzzlingTeamId, 'twin' => $twinId],
        );
    }

    private function notificationsAbout(string $timeId): int
    {
        return $this->number('SELECT COUNT(*) FROM notification WHERE target_solving_time_id = :id', ['id' => $timeId]);
    }

    private function wishListItemsOf(string $playerId): int
    {
        return $this->number('SELECT COUNT(*) FROM wish_list_item WHERE player_id = :id', ['id' => $playerId]);
    }

    private function solvedTimesCount(): int
    {
        return $this->number('SELECT COALESCE((SELECT solved_times_count FROM puzzle_statistics WHERE puzzle_id = :id), 0)', ['id' => self::PUZZLE]);
    }

    /**
     * @return array<class-string, list<object>> what was put on the async queue since the last reset, by message class
     */
    private function queued(): array
    {
        $messages = [];

        foreach ($this->asyncTransport->getSent() as $envelope) {
            $messages[$envelope->getMessage()::class][] = $envelope->getMessage();
        }

        $this->asyncTransport->reset();

        return $messages;
    }

    /**
     * @param array<class-string, list<object>> $queue
     *
     * @return list<string>
     */
    private function awardedTimeIds(array $queue): array
    {
        $ids = [];

        foreach ($queue[AwardXpForSolvingTime::class] ?? [] as $message) {
            if ($message instanceof AwardXpForSolvingTime) {
                $ids[] = $message->solvingTimeId;
            }
        }

        return $ids;
    }

    /**
     * @return list<string>
     */
    private function guestsOf(mixed $puzzlingTeamId): array
    {
        /** @var list<string> $names */
        $names = $this->database->fetchFirstColumn('SELECT guest_name FROM puzzling_team_member WHERE team_id = :team AND guest_name IS NOT NULL ORDER BY guest_name', ['team' => $puzzlingTeamId]);

        return $names;
    }

    /**
     * @return array{results: int, teams: int} what is saved of the player
     */
    private function footprintOf(string $playerId): array
    {
        return [
            'results' => $this->number('SELECT COUNT(*) FROM puzzle_solving_time WHERE player_id = :id', ['id' => $playerId]),
            'teams' => $this->number('SELECT COUNT(*) FROM puzzling_team_member WHERE player_id = :id', ['id' => $playerId]),
        ];
    }

    /**
     * @param list<object> $messages
     *
     * @return list<string>
     */
    private function playerIds(array $messages): array
    {
        $ids = [];

        foreach ($messages as $message) {
            if ($message instanceof RecalculateXpForPlayer || $message instanceof RecalculateBadgesForPlayer || $message instanceof BackfillSolvingTimePredictions) {
                $ids[] = $message->playerId;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return list<array<string, mixed>> the player's XP entries after a full rebuild, without what names the entry
     */
    private function ledgerAfterRecompute(string $playerId): array
    {
        $this->entityManager->wrapInTransaction(static function () use ($playerId): void {
            self::getContainer()->get(XpRecomputer::class)->recomputeForPlayer($playerId);
        });

        $ledger = $this->database->fetchAllAssociative(
            'SELECT reason, amount, in_weekly_delta, earned_at FROM xp_entry WHERE player_id = :id ORDER BY reason, amount',
            ['id' => $playerId],
        );
        self::assertNotSame([], $ledger, 'A result earns XP.');

        return $ledger;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function number(string $sql, array $parameters): int
    {
        $value = $this->database->fetchOne($sql, $parameters);
        self::assertIsNumeric($value);

        return (int) $value;
    }
}
