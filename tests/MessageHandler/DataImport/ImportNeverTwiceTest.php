<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler\DataImport;

use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\DataImport;
use SpeedPuzzling\Web\Entity\DataImportBatch;
use SpeedPuzzling\Web\Entity\DataImportSheet;
use SpeedPuzzling\Web\Message\ApplyImportRow;
use SpeedPuzzling\Web\Message\ProcessImportBatch;
use SpeedPuzzling\Web\MessageHandler\ProcessImportBatchHandler;
use SpeedPuzzling\Web\Repository\DataImportRowRepository;
use SpeedPuzzling\Web\Repository\DataImportSettingsRepository;
use SpeedPuzzling\Web\Services\DataImport\ImportProgress;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Value\DataImport\AddImportRowsOutcome;
use SpeedPuzzling\Web\Value\DataImportRowKind;
use SpeedPuzzling\Web\Value\DataImportUndoScope;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * "Never twice" (docs/features/data-import/adding.md, README "Never twice"): whatever is sent, delivered or imported
 * a second time adds nothing a second time.
 */
final class ImportNeverTwiceTest extends KernelTestCase
{
    use ImportAddingScenario;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testTheSameFileImportedTwiceAddsNothingTheSecondTimeAndTwoMoreRowsAddExactlyTwo(): void
    {
        $player = $this->seedPairingPlayer('twice');
        $this->seedCataloguePuzzles('Zqtwice', ['Zq Twice Alpha', 'Zq Twice Beta', 'Zq Twice Gamma']);

        [$import, $sheet] = $this->importOf($player);
        $this->fileOfThree($import, $sheet);
        $this->pair($import);
        self::assertSame(4, $this->addAndWork($import, $player)->rows);
        self::assertCount(3, $this->resultsOf($player));
        self::assertSame(1, $this->pairingCount('SELECT COUNT(*) FROM collection_item WHERE player_id = :id', ['id' => $player->id->toString()]));

        // The same file again - a new import with the same rows, in another order
        [$again, $againSheet] = $this->importOf($player);
        $rows = array_reverse($this->fileOfThree($again, $againSheet));
        $this->pair($again);
        $outcome = $this->addAndWork($again, $player);

        self::assertSame(4, $outcome->rows);
        self::assertCount(3, $this->resultsOf($player));
        self::assertSame(1, $this->pairingCount('SELECT COUNT(*) FROM collection_item WHERE player_id = :id', ['id' => $player->id->toString()]));
        self::assertSame([0, 4, 0], [$this->batchNow($outcome->id)['rows_added'], $this->batchNow($outcome->id)['rows_already_yours'], $this->batchNow($outcome->id)['results_added']]);

        foreach ($rows as $rowId) {
            self::assertSame(['already_yours', false], [$this->rowNow($rowId)['state'], $this->rowNow($rowId)['result_made']]);
        }

        // The file with two more rows adds exactly two
        [$more, $moreSheet] = $this->importOf($player);
        $this->fileOfThree($more, $moreSheet);
        $newResult = $this->resultRow($more, $moreSheet, 'Zq Twice Alpha', 500, 'Zqtwice', '2024-06-06', 1700);
        $newOwned = $this->ownedRow($more, $moreSheet, 'Zq Twice Beta', 500, 'Zqtwice');
        $this->pair($more);
        $outcome = $this->addAndWork($more, $player);

        self::assertSame([2, 4, 1, 1], [$this->batchNow($outcome->id)['rows_added'], $this->batchNow($outcome->id)['rows_already_yours'], $this->batchNow($outcome->id)['results_added'], $this->batchNow($outcome->id)['collection_items_added']]);
        self::assertSame(['added', 'added'], [$this->rowNow($newResult)['state'], $this->rowNow($newOwned)['state']]);
        self::assertCount(4, $this->resultsOf($player));
    }

    public function testWhatWasAlreadyYoursCanBeAddedOnceTheResultIsGone(): void
    {
        $player = $this->seedPairingPlayer('gone');
        $this->seedCataloguePuzzles('Zqgone', ['Zq Gone Alpha']);

        [$first, $firstSheet] = $this->importOf($player);
        $this->resultRow($first, $firstSheet, 'Zq Gone Alpha', 500, 'Zqgone', '2024-01-01', 1800);
        $this->pair($first);
        $this->addAndWork($first, $player);

        [$second, $secondSheet] = $this->importOf($player);
        $row = $this->resultRow($second, $secondSheet, 'Zq Gone Alpha', 500, 'Zqgone', '2024-01-01', 1800);
        $this->pair($second);
        $this->addAndWork($second, $player);
        self::assertSame(['already_yours', 'earlier_import'], [$this->rowNow($row)['state'], $this->rowNow($row)['already_yours_how']]);
        // While the result is there, "Add" finds nothing to do
        self::assertSame(AddImportRowsOutcome::NOTHING, $this->add($second, $player)->status);

        // The earlier import is undone: what exists now decides - the row is not "already yours" any more
        $this->undo($first, $player, DataImportUndoScope::Import);
        self::assertSame([], $this->resultsOf($player));

        $outcome = $this->addAndWork($second, $player);

        self::assertSame(1, $outcome->rows);
        $now = $this->rowNow($row);
        self::assertSame(['added', true, null], [$now['state'], $now['result_made'], $now['already_yours_how']]);
        self::assertCount(1, $this->resultsOf($player));
    }

    public function testAResentAddAnswersTheSameBatchAndASecondDeliveryOfTheJobAddsNothing(): void
    {
        $player = $this->seedPairingPlayer('resent');
        $this->seedCataloguePuzzles('Zqresent', ['Zq Resent Alpha', 'Zq Resent Beta']);

        [$import, $sheet] = $this->importOf($player);
        $this->resultRow($import, $sheet, 'Zq Resent Alpha', 500, 'Zqresent', '2024-01-01', 1800);
        $this->resultRow($import, $sheet, 'Zq Resent Beta', 500, 'Zqresent', '2024-01-02', 1900);
        $this->pair($import);

        $requestId = Uuid::uuid7()->toString();
        $first = $this->add($import, $player, requestId: $requestId);
        $second = $this->add($import, $player, requestId: $requestId);

        self::assertSame([AddImportRowsOutcome::STARTED, AddImportRowsOutcome::SAME_REQUEST], [$first->status, $second->status]);
        self::assertSame([$first->id, 2], [$second->id, $second->rows]);
        self::assertSame(1, $this->pairingCount('SELECT COUNT(*) FROM data_import_batch'));

        // The job delivered twice, and once more after the batch is finished
        $handler = self::getContainer()->get(ProcessImportBatchHandler::class);
        self::assertNotNull($first->id);
        $handler(new ProcessImportBatch($first->id, 1));
        $handler(new ProcessImportBatch($first->id, 1));
        $handler(new ProcessImportBatch($first->id, 1));

        self::assertCount(2, $this->resultsOf($player));
        self::assertSame(1, $this->pairingCount('SELECT COUNT(*) FROM notification WHERE player_id = :id', ['id' => $player->id->toString()]));

        // After the batch: the request id still answers it, and "Add" finds nothing left
        self::assertSame(AddImportRowsOutcome::SAME_REQUEST, $this->add($import, $player, requestId: $requestId)->status);
        self::assertSame(AddImportRowsOutcome::NOTHING, $this->add($import, $player)->status);
    }

    public function testARowAppliedTwiceAndTwoBatchesOverTheSameRowsAddEachRowOnce(): void
    {
        $player = $this->seedPairingPlayer('race');
        $this->seedCataloguePuzzles('Zqrace', ['Zq Race Alpha']);

        [$import, $sheet] = $this->importOf($player);
        $row = $this->resultRow($import, $sheet, 'Zq Race Alpha', 500, 'Zqrace', '2024-01-01', 1800);
        $other = $this->resultRow($import, $sheet, 'Zq Race Alpha', 500, 'Zqrace', '2024-01-02', 1900);
        $this->pair($import);
        $outcome = $this->add($import, $player);
        self::assertNotNull($outcome->id);
        $bus = self::getContainer()->get(MessageBusInterface::class);

        // The same row applied by two deliveries: the second finds it no longer queued
        $first = $bus->dispatch(new ApplyImportRow($row, $outcome->id, $player->id->toString()))->last(HandledStamp::class)?->getResult();
        $second = $bus->dispatch(new ApplyImportRow($row, $outcome->id, $player->id->toString()))->last(HandledStamp::class)?->getResult();
        self::assertIsArray($first);
        self::assertIsArray($second);
        self::assertSame(['added', 'skipped'], [$first['status'], $second['status']]);

        // A second batch that claims the same rows (it can not come from "Add" - made by hand): its job finds no row
        // of its own, and the rows of the first batch are not its rows
        [$importNow] = $this->reloadImport($import);
        $rival = new DataImportBatch(Uuid::uuid7(), $importNow, Uuid::uuid7(), 2, [], TestClock::now());
        $this->pairingEntityManager()->persist($rival);
        $this->pairingEntityManager()->flush();
        $rivalApplied = $bus->dispatch(new ApplyImportRow($other, $rival->id->toString(), $player->id->toString()))->last(HandledStamp::class)?->getResult();
        self::assertIsArray($rivalApplied);
        self::assertSame('skipped', $rivalApplied['status']);
        $this->work($rival->id->toString());
        $this->work($outcome->id);

        self::assertCount(2, $this->resultsOf($player));
        self::assertSame(['added', 'added'], [$this->rowNow($row)['state'], $this->rowNow($other)['state']]);
        self::assertSame($outcome->id, $this->rowNow($other)['batch_id']);
        self::assertSame([0, 2], [$this->batchNow($rival->id->toString())['rows_added'], $this->batchNow($outcome->id)['rows_added']]);
    }

    public function testAWorkerKilledAfterSomeRowsGoesOnWhereItStoppedAndAddsEveryRowOnce(): void
    {
        $player = $this->seedPairingPlayer('killed');
        $this->seedCataloguePuzzles('Zqkilled', ['Zq Killed Alpha']);

        [$import, $sheet] = $this->importOf($player);

        for ($day = 1; $day <= 7; $day++) {
            $this->resultRow($import, $sheet, 'Zq Killed Alpha', 500, 'Zqkilled', sprintf('2024-01-%02d', $day), 1800 + $day);
        }

        $this->pair($import);
        $outcome = $this->add($import, $player);
        self::assertNotNull($outcome->id);

        // A worker that ends its turn after three rows - and is never heard of again
        $container = self::getContainer();
        $short = new ProcessImportBatchHandler(
            $container->get(MessageBusInterface::class),
            $container->get(DataImportRowRepository::class),
            $container->get(DataImportSettingsRepository::class),
            $this->pairingEntityManager(),
            $container->get(ImportProgress::class),
            new NullLogger(),
            rowsPerTurn: 3,
        );
        // Three turns: the first pass over seven rows, and the first rows added
        $short($this->currentTurnOf($outcome->id));
        // The same job delivered again - a second chain - is void: the batch is at its next turn
        $short(new ProcessImportBatch($outcome->id, 1));
        $short($this->currentTurnOf($outcome->id));
        $short($this->currentTurnOf($outcome->id));

        self::assertSame('running', $this->batchNow($outcome->id)['state']);
        $added = $this->pairingCount("SELECT COUNT(*) FROM data_import_row WHERE batch_id = :id AND state = 'added'", ['id' => $outcome->id]);
        $queued = $this->pairingCount("SELECT COUNT(*) FROM data_import_row WHERE batch_id = :id AND state = 'queued'", ['id' => $outcome->id]);
        self::assertGreaterThan(0, $added);
        self::assertGreaterThan(0, $queued);
        self::assertSame(7, $added + $queued);
        self::assertCount($added, $this->resultsOf($player));

        // The sweeper's worker runs the batch again from the start of its job
        $this->work($outcome->id);

        self::assertSame(['finished', 7, 7], [$this->batchNow($outcome->id)['state'], $this->batchNow($outcome->id)['rows_added'], $this->batchNow($outcome->id)['results_added']]);
        $results = $this->resultsOf($player);
        self::assertCount(7, $results);
        self::assertCount(7, array_unique(array_map(static fn (array $result): string => is_string($result['finished_at']) ? $result['finished_at'] : '', $results)));
        self::assertSame(1, $this->pairingCount('SELECT COUNT(*) FROM notification WHERE player_id = :id', ['id' => $player->id->toString()]));
    }

    public function testResultsThePlayerAlreadyHasPairOffOneToOneAndTheRestIsNew(): void
    {
        $player = $this->seedPairingPlayer('pair-off');
        [$puzzle] = $this->seedCataloguePuzzles('Zqpairoff', ['Zq Pair Off Speedrun']);
        // Five solves of one day typed by hand before
        $typed = [];

        foreach ([600, 610, 620, 630, 640] as $seconds) {
            $typed[] = $this->typedResult($player, $puzzle, '2024-05-05', $seconds);
        }

        // The file has thirteen of that day, the typed ones among them - written in another order - and one of the
        // typed times a second time (a log of attempts: rows with different attempt numbers are not linked)
        [$import, $sheet] = $this->importOf($player);
        $rows = [];

        foreach ([700, 630, 710, 610, 720, 600, 730, 620, 740, 640, 750, 760] as $seconds) {
            $rows[$seconds] = $this->resultRow($import, $sheet, 'Zq Pair Off Speedrun', 500, 'Zqpairoff', '2024-05-05', $seconds);
        }

        $twin = $this->resultRow($import, $sheet, 'Zq Pair Off Speedrun', 500, 'Zqpairoff', '2024-05-05', 610, ['occurrence' => 2]);
        $this->pair($import);

        $outcome = $this->addAndWork($import, $player);

        // 5 pair off, 8 are new, nothing is asked - whatever the order of the rows
        $batch = $this->batchNow($outcome->id);
        self::assertSame([13, 8, 5, 0, 8], [$batch['rows_requested'], $batch['rows_added'], $batch['rows_already_yours'], $batch['rows_refused'], $batch['results_added']]);
        self::assertCount(13, $this->resultsOf($player));

        $spokenFor = [];

        foreach ([$rows[600], $rows[610], $rows[620], $rows[630], $rows[640], $twin] as $rowId) {
            $row = $this->rowNow($rowId);

            if ($row['state'] === 'already_yours') {
                self::assertSame('profile', $row['already_yours_how']);
                $spokenFor[] = $row['already_yours_solving_time_id'];
            }
        }

        // Every typed result is spoken for by exactly one row
        sort($spokenFor);
        sort($typed);
        self::assertSame($typed, $spokenFor);
        // Two rows say 610, the profile had one: the other is new
        self::assertSame(2, $this->pairingCount('SELECT COUNT(*) FROM puzzle_solving_time WHERE player_id = :id AND seconds_to_solve = 610', ['id' => $player->id->toString()]));
    }

    public function testAResultThatMayBeTheSameWrittenDifferentlyIsNotAddedByItself(): void
    {
        $player = $this->seedPairingPlayer('similar');
        [$puzzle, $other] = $this->seedCataloguePuzzles('Zqsimilar', ['Zq Similar One', 'Zq Similar Two']);
        // Typed on the day it was entered; the file has the real date
        $this->typedResult($player, $puzzle, '2024-05-05', 3600);
        // The same day with another time
        $this->typedResult($player, $other, '2024-06-06', 4000);

        [$import, $sheet] = $this->importOf($player);
        $otherDate = $this->resultRow($import, $sheet, 'Zq Similar One', 500, 'Zqsimilar', '2024-05-01', 3600);
        $otherTime = $this->resultRow($import, $sheet, 'Zq Similar Two', 500, 'Zqsimilar', '2024-06-06', 4100);
        $unrelated = $this->resultRow($import, $sheet, 'Zq Similar Two', 500, 'Zqsimilar', '2023-01-01', 5000);
        $saidThere = $this->resultRow($import, $sheet, 'Zq Similar One', 500, 'Zqsimilar', '2022-02-02', 3333, ['alreadyOnMsp' => true]);
        $this->pair($import);

        $this->addAndWork($import, $player);

        self::assertSame(['waiting', 'similar_result_exists'], [$this->rowNow($otherDate)['state'], $this->rowNow($otherDate)['failure']]);
        self::assertSame(['waiting', 'similar_result_exists'], [$this->rowNow($otherTime)['state'], $this->rowNow($otherTime)['failure']]);
        // The file says "already on MySpeedPuzzling" and nothing was found: listed, not added quietly
        self::assertSame(['waiting', 'said_already_on_profile'], [$this->rowNow($saidThere)['state'], $this->rowNow($saidThere)['failure']]);
        self::assertSame('added', $this->rowNow($unrelated)['state']);
        self::assertCount(3, $this->resultsOf($player));

        // The player's "add it anyway", written on the row by the screens
        $this->pairingDatabase()->update('data_import_row', ['answers' => '{"addAnyway": ["similar_result_exists"]}'], ['id' => $otherDate]);
        $this->addAndWork($import, $player, [$otherDate, $otherTime]);

        self::assertSame('added', $this->rowNow($otherDate)['state']);
        self::assertSame(['waiting', 'similar_result_exists'], [$this->rowNow($otherTime)['state'], $this->rowNow($otherTime)['failure']]);
        self::assertCount(4, $this->resultsOf($player));
    }

    public function testEqualRowsAreOneResultUnlessThePlayerSaysTwo(): void
    {
        $player = $this->seedPairingPlayer('equal');
        $this->seedCataloguePuzzles('Zqequal', ['Zq Equal One', 'Zq Equal Two']);

        [$import, $sheet] = $this->importOf($player);
        // Equal rows as ImportEqualRows leaves them: numbered, each linked to the one that stands for them
        // (the row that stands behind the other is the earlier one here: its turn comes first)
        $same = $this->resultRow($import, $sheet, 'Zq Equal One', 500, 'Zqequal', '2024-01-01', 1800, ['occurrence' => 2]);
        $standing = $this->resultRow($import, $sheet, 'Zq Equal One', 500, 'Zqequal', '2024-01-01', 1800);
        $first = $this->resultRow($import, $sheet, 'Zq Equal Two', 500, 'Zqequal', '2024-02-02', 2000);
        $separate = $this->resultRow($import, $sheet, 'Zq Equal Two', 500, 'Zqequal', '2024-02-02', 2000, ['occurrence' => 2]);
        $this->pairingDatabase()->update('data_import_row', ['same_as_row_id' => $standing], ['id' => $same]);
        // "Two": the player's word
        $this->pairingDatabase()->update('data_import_row', ['same_as_row_id' => $first, 'add_as_separate' => 'true'], ['id' => $separate]);
        $this->pair($import);

        // The row that stands behind another one comes first in the selection: the row it stands behind is added on
        // the way, once
        $outcome = $this->addAndWork($import, $player);

        self::assertSame([4, 3, 1, 3], [$this->batchNow($outcome->id)['rows_requested'], $this->batchNow($outcome->id)['rows_added'], $this->batchNow($outcome->id)['rows_already_yours'], $this->batchNow($outcome->id)['results_added']]);
        self::assertSame(['already_yours', 'equal_row', $this->rowNow($standing)['solving_time_id']], [$this->rowNow($same)['state'], $this->rowNow($same)['already_yours_how'], $this->rowNow($same)['already_yours_solving_time_id']]);
        self::assertSame(['added', 'added'], [$this->rowNow($first)['state'], $this->rowNow($separate)['state']]);
        self::assertSame(2, $this->pairingCount('SELECT COUNT(*) FROM puzzle_solving_time WHERE player_id = :id AND seconds_to_solve = 2000', ['id' => $player->id->toString()]));
        self::assertSame(1, $this->pairingCount('SELECT COUNT(*) FROM puzzle_solving_time WHERE player_id = :id AND seconds_to_solve = 1800', ['id' => $player->id->toString()]));

        // Two equal results of one day confirmed as two never look like one form sent twice: more than 10 s apart,
        // and recorded as "it's another solve"
        self::assertGreaterThan(10, $this->pairingCount('SELECT CAST(EXTRACT(EPOCH FROM MAX(tracked_at) - MIN(tracked_at)) AS INT) FROM puzzle_solving_time WHERE player_id = :id AND seconds_to_solve = 2000', ['id' => $player->id->toString()]));
        self::assertSame(
            [['kind' => 'saved_anyway', 'via' => 'spreadsheet_import', 'time_id' => $this->rowNow($separate)['solving_time_id']]],
            $this->pairingDatabase()->fetchAllAssociative('SELECT kind, via, time_id FROM result_duplicate_prevention WHERE player_id = :id', ['id' => $player->id->toString()]),
        );
    }

    public function testOneTypedResultSpeaksForOneRowAlsoWhenThatRowAddedItsPuzzle(): void
    {
        $player = $this->seedPairingPlayer('one-for-one');
        [$puzzle] = $this->seedCataloguePuzzles('Zqonefor', ['Zq One For One']);
        $typed = $this->typedResult($player, $puzzle, '2024-05-05', 4000);

        // Two rows that are a result AND an owned puzzle, with the day and time of the one typed result (a log of
        // attempts: not linked as equal rows)
        [$import, $sheet] = $this->importOf($player);
        $first = $this->resultRow($import, $sheet, 'Zq One For One', 500, 'Zqonefor', '2024-05-05', 4000, kind: DataImportRowKind::Both);
        $second = $this->resultRow($import, $sheet, 'Zq One For One', 500, 'Zqonefor', '2024-05-05', 4000, ['occurrence' => 2], DataImportRowKind::Both);
        $this->pair($import);

        $this->addAndWork($import, $player);

        // The first is the typed result and adds the puzzle to the collection - and keeps speaking for that result;
        // the second finds no result left and is a new one
        $firstNow = $this->rowNow($first);
        self::assertSame(['added', false, 'profile', $typed], [$firstNow['state'], $firstNow['result_made'], $firstNow['already_yours_how'], $firstNow['already_yours_solving_time_id']]);
        self::assertNotNull($firstNow['collection_item_id']);
        self::assertSame(['added', true], [$this->rowNow($second)['state'], $this->rowNow($second)['result_made']]);
        self::assertCount(2, $this->resultsOf($player));
        self::assertSame(1, $this->pairingCount('SELECT COUNT(*) FROM collection_item WHERE player_id = :id', ['id' => $player->id->toString()]));
    }

    public function testAPairsRowSomebodyElseAlreadyLoggedIsNotAddedQuietly(): void
    {
        $player = $this->seedPairingPlayer('pair-importer');
        $partner = $this->seedPairingPlayer('pair-partner');
        [$puzzle] = $this->seedCataloguePuzzles('Zqpairs', ['Zq Pairs Evening']);
        // The partner logged the evening with a guest
        $this->typedResult($partner, $puzzle, '2024-07-07', 2700, ['Somebody']);

        [$import, $sheet] = $this->importOf($player);
        $row = $this->resultRow($import, $sheet, 'Zq Pairs Evening', 500, 'Zqpairs', '2024-07-07', 2700, ['type' => 'duo', 'people' => ['A friend']]);
        $this->pair($import);

        $this->addAndWork($import, $player);

        self::assertSame(['waiting', 'somebody_elses_result'], [$this->rowNow($row)['state'], $this->rowNow($row)['failure']]);
        self::assertSame([], $this->resultsOf($player));
    }

    /**
     * @param list<string> $names
     *
     * @return list<string> their ids
     */
    private function seedCataloguePuzzles(string $brand, array $names): array
    {
        $manufacturer = $this->seedBrand($brand);

        return array_map(fn (string $name): string => $this->seedCataloguePuzzle($name, 500, $manufacturer), $names);
    }

    /**
     * One "file": three results and an owned puzzle - the brand is the first test's.
     *
     * @return list<string> row ids
     */
    private function fileOfThree(DataImport $import, DataImportSheet $sheet): array
    {
        return [
            $this->resultRow($import, $sheet, 'Zq Twice Alpha', 500, 'Zqtwice', '2024-01-01', 1800),
            $this->resultRow($import, $sheet, 'Zq Twice Beta', 500, 'Zqtwice', '2024-01-02', 1900, ['type' => 'duo', 'people' => ['Ana']]),
            $this->resultRow($import, $sheet, 'Zq Twice Gamma', 500, 'Zqtwice', '2024-01-03', null),
            $this->ownedRow($import, $sheet, 'Zq Twice Gamma', 500, 'Zqtwice'),
        ];
    }
}
