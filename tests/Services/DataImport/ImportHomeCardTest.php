<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\DataImport;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Results\DataImportOverview;
use SpeedPuzzling\Web\Results\ImportFlowText;
use SpeedPuzzling\Web\Services\DataImport\ImportHomeCard;
use SpeedPuzzling\Web\Value\DataImportLimits;
use SpeedPuzzling\Web\Value\DataImportStatus;

/**
 * An import's card on Imports home as a table (docs/features/data-import/guided-flow.md §5.1): where the import
 * stands → its ONE status (a sentence a line), its ONE button and where it leads. Nothing here touches a database
 * or a clock.
 */
final class ImportHomeCardTest extends TestCase
{
    private const string IMPORT = '019a0000-0000-7000-8000-000000000001';
    private const string BATCH = '019a0000-0000-7000-8000-0000000000b1';
    private const string NOW = '2026-11-15 10:00:00';

    /**
     * @param null|array<string, mixed> $standing what is not given is nothing
     * @param list<string> $lines the keys of the status sentences
     */
    #[DataProvider('provideCards')]
    public function testTheCardOfAnImport(DataImportStatus $status, null|array $standing, array $lines, string $button, string $route, bool $quiet, bool $finished): void
    {
        $card = ImportHomeCard::of(self::import($status), $standing === null ? null : self::standing($standing), new DateTimeImmutable(self::NOW));

        self::assertSame($lines, array_map(static fn (ImportFlowText $line): string => $line->key, $card->lines));
        self::assertSame($button, $card->button->key);
        self::assertSame($route, $card->route);
        self::assertSame(self::IMPORT, $card->parameters['importId']);
        self::assertSame($quiet, $card->quiet);
        self::assertSame($finished, $card->finished);
        // One status: never more than the two sentences the table of §5.1 has
        self::assertLessThanOrEqual(2, count($card->lines));
        self::assertNotSame([], $card->lines);
    }

    /**
     * @return iterable<string, array{DataImportStatus, null|array<string, mixed>, list<string>, string, string, bool, bool}>
     */
    public static function provideCards(): iterable
    {
        $open = 'data_import.after.home.open';
        $continue = 'data_import.flow.continue';

        yield 'the upload did not finish' => [DataImportStatus::Uploading, null, ['data_import.flow.file.upload_not_finished'], 'data_import.after.home.finish_upload', ImportHomeCard::ROUTE_FINISH_UPLOAD, false, false];
        yield 'being read' => [DataImportStatus::Reading, null, ['data_import.flow.file.being_read'], $open, 'data_import_detail', true, false];
        yield 'being fetched' => [DataImportStatus::Fetching, null, ['data_import.flow.file.being_read'], $open, 'data_import_detail', true, false];
        yield 'could not be read' => [DataImportStatus::ReadFailed, null, ['data_import.flow.file.not_read'], 'data_import.after.home.see_why', 'data_import_detail', false, false];
        yield 'could not be fetched' => [DataImportStatus::FetchFailed, null, ['data_import.flow.file.not_read'], 'data_import.after.home.see_why', 'data_import_detail', false, false];

        yield 'its sheets are still worked out' => [DataImportStatus::SettingUp, ['working' => true], ['data_import.flow.file.being_read'], $open, 'data_import_detail', true, false];
        yield 'step 2: questions and rows' => [DataImportStatus::SettingUp, ['questions' => 2, 'look' => 45, 'ready' => 300, 'toMatch' => 9], ['data_import.flow.check.questions', 'data_import.flow.check.rows'], $continue, 'data_import_detail', false, false];
        yield 'step 2: rows only' => [DataImportStatus::SettingUp, ['look' => 45], ['data_import.flow.check.rows'], $continue, 'data_import_detail', false, false];
        yield 'step 4: something ready, puzzles to match' => [DataImportStatus::SettingUp, ['ready' => 471, 'toMatch' => 276], ['data_import.after.home.ready', 'data_import.after.home.to_match'], $continue, 'data_import_detail', false, false];
        yield 'step 4: nothing ready yet' => [DataImportStatus::SettingUp, ['toMatch' => 276], ['data_import.after.home.to_match'], $continue, 'data_import_detail', false, false];
        yield 'only step 5' => [DataImportStatus::SettingUp, ['ready' => 471], ['data_import.after.home.ready'], 'data_import.flow.primary.check_and_add', 'data_import_add', false, false];
        yield 'step 2: a sheet whose reading is a guess is a sheet to check, not a question' => [DataImportStatus::SettingUp, ['toCheck' => 1, 'ready' => 300], ['data_import.flow.check.to_check'], $continue, 'data_import_detail', false, false];
        yield 'adding now' => [DataImportStatus::SettingUp, ['runningBatchId' => self::BATCH, 'adding' => true, 'ready' => 5, 'questions' => 1], ['data_import.flow.add.running'], 'data_import.flow.primary.follow', 'data_import_batch', false, false];
        yield 'added, rows wait for an answer' => [DataImportStatus::SettingUp, ['results' => 246, 'lastAddedAt' => new DateTimeImmutable('2026-11-14'), 'waiting' => 31, 'tookRows' => true, 'ready' => 4], ['data_import.flow.add.added_on', 'data_import.flow.add.rows_wait'], 'data_import.flow.primary.answer', 'data_import_not_added', false, false];
        yield 'added, the rest waits for tomorrow' => [DataImportStatus::SettingUp, ['results' => 2000, 'resultsToday' => 2000, 'lastAddedAt' => new DateTimeImmutable(self::NOW), 'later' => 1240, 'tookRows' => true], ['data_import.after.home.added_today', 'data_import.after.home.tomorrow'], $open, 'data_import_detail', true, false];
        yield 'another import used the day up' => [DataImportStatus::SettingUp, ['later' => 300], ['data_import.after.home.tomorrow'], $open, 'data_import_detail', true, false];
        // The day is used up for results, and puzzles for the collection can still be added today
        yield 'some today, the rest tomorrow' => [DataImportStatus::SettingUp, ['ready' => 12, 'later' => 300], ['data_import.after.home.ready'], 'data_import.flow.primary.check_and_add', 'data_import_add', false, false];
        // What "Check and add" says: rows an Add would take that are on the profile already are not "ready to add"
        yield 'every ready row is on the profile already' => [DataImportStatus::SettingUp, ['onProfile' => 6], ['data_import.after.home.on_profile'], $open, 'data_import_detail', true, false];
        yield 'some rows add, the others are on the profile' => [DataImportStatus::SettingUp, ['ready' => 3, 'onProfile' => 6], ['data_import.after.home.ready'], 'data_import.flow.primary.check_and_add', 'data_import_add', false, false];
        yield 'added, and the rest is on the profile already' => [DataImportStatus::SettingUp, ['results' => 4, 'onProfile' => 6, 'tookRows' => true], ['data_import.flow.add.added', 'data_import.after.home.on_profile'], $open, 'data_import_detail', true, false];
        yield 'added, nothing left' => [DataImportStatus::SettingUp, ['results' => 900, 'collectionItems' => 18, 'lastAddedAt' => new DateTimeImmutable('2026-11-14'), 'tookRows' => true], ['data_import.flow.add.added_on'], 'data_import.flow.primary.see_added', 'data_import_added', true, false];
        yield 'everything undone' => [DataImportStatus::SettingUp, ['tookRows' => true], ['data_import.home.nothing_added_now'], $open, 'data_import_detail', true, false];
        yield 'nothing of it can be added' => [DataImportStatus::SettingUp, [], ['data_import.flow.add.nothing'], $open, 'data_import_detail', true, false];

        yield 'finished, it added something' => [DataImportStatus::Done, ['results' => 918, 'lastAddedAt' => new DateTimeImmutable('2026-11-14'), 'tookRows' => true, 'ready' => 12, 'questions' => 3], ['data_import.flow.add.added_on'], 'data_import.flow.primary.see_added', 'data_import_added', true, true];
        // Rows kept just for the player are there too: named, and what the import added can be seen and undone
        yield 'only rows kept just for the player' => [DataImportStatus::SettingUp, ['keptJustForYou' => 3, 'tookRows' => true], ['data_import.after.home.kept_just_for_you'], 'data_import.flow.primary.see_added', 'data_import_added', true, false];
        yield 'results and rows kept just for the player' => [DataImportStatus::SettingUp, ['results' => 5, 'keptJustForYou' => 3, 'tookRows' => true], ['data_import.flow.add.added', 'data_import.after.home.kept_just_for_you'], 'data_import.flow.primary.see_added', 'data_import_added', true, false];
        yield 'finished, only rows kept just for the player' => [DataImportStatus::Done, ['keptJustForYou' => 3, 'tookRows' => true], ['data_import.after.home.kept_just_for_you'], 'data_import.flow.primary.see_added', 'data_import_added', true, true];
        yield 'finished, everything undone' => [DataImportStatus::Done, ['tookRows' => true], ['data_import.home.nothing_added_now'], $open, 'data_import_detail', true, true];
    }

    public function testTheCardSaysUntilWhenTheFileIsKeptOnlyInItsLastDays(): void
    {
        $now = new DateTimeImmutable(self::NOW);
        $card = static fn (string $created, DataImportStatus $status = DataImportStatus::SettingUp): ImportHomeCard => ImportHomeCard::of(self::import($status, new DateTimeImmutable($created)), self::standing(['ready' => 3]), $now);

        // Kept for thirty days: said from seven days before the end - not earlier, not once it is gone, not on a
        // finished import
        self::assertNull($card('2026-11-01 10:00:00')->keptUntil);
        self::assertEquals(new DateTimeImmutable('2026-11-20 10:00:00'), $card('2026-10-21 10:00:00')->keptUntil);
        self::assertNull($card('2026-10-01 10:00:00')->keptUntil);
        self::assertNull($card('2026-10-21 10:00:00', DataImportStatus::Done)->keptUntil);
    }

    private static function import(DataImportStatus $status, null|DateTimeImmutable $createdAt = null): DataImportOverview
    {
        $createdAt ??= new DateTimeImmutable('2026-11-14 09:00:00');

        return new DataImportOverview(
            importId: self::IMPORT,
            fileName: 'my-puzzles.xlsx',
            fileSize: 61_000,
            status: $status,
            createdAt: $createdAt,
            fileKept: true,
            fileRemovedAt: null,
            fileKeptUntil: $createdAt->modify('+' . DataImportLimits::KEEP_FILE_DAYS . ' days'),
        );
    }

    /**
     * @param array<string, mixed> $given
     *
     * @return array{results: int, collectionItems: int, wishListItems: int, keptJustForYou: int, lastAddedAt: null|DateTimeImmutable, adding: bool, runningBatchId: null|string, resultsToday: int, ready: int, later: int, onProfile: int, waiting: int, questions: int, toCheck: int, look: int, toMatch: int, working: bool, tookRows: bool}
     */
    private static function standing(array $given): array
    {
        $int = static fn (string $name): int => is_int($given[$name] ?? null) ? $given[$name] : 0;
        $lastAddedAt = $given['lastAddedAt'] ?? null;
        $runningBatchId = $given['runningBatchId'] ?? null;

        return [
            'results' => $int('results'),
            'collectionItems' => $int('collectionItems'),
            'wishListItems' => $int('wishListItems'),
            'keptJustForYou' => $int('keptJustForYou'),
            'lastAddedAt' => $lastAddedAt instanceof DateTimeImmutable ? $lastAddedAt : null,
            'adding' => ($given['adding'] ?? false) === true,
            'runningBatchId' => is_string($runningBatchId) ? $runningBatchId : null,
            'resultsToday' => $int('resultsToday'),
            'ready' => $int('ready'),
            'later' => $int('later'),
            'onProfile' => $int('onProfile'),
            'waiting' => $int('waiting'),
            'questions' => $int('questions'),
            'toCheck' => $int('toCheck'),
            'look' => $int('look'),
            'toMatch' => $int('toMatch'),
            'working' => ($given['working'] ?? false) === true,
            'tookRows' => ($given['tookRows'] ?? false) === true,
        ];
    }
}
