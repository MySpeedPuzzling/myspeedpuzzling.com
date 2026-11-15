<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler\DataImport;

use SpeedPuzzling\Web\Message\ApplyImportRow;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Results that know only their month or year (docs/features/data-import/date-precision.md): added with what the file
 * knows, on the first day of the period, and compared within the period - never by that first day.
 */
final class ImportDatePrecisionTest extends KernelTestCase
{
    use ImportAddingScenario;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testAMonthAndAYearAreAddedAsThatOnTheFirstDayOfThePeriod(): void
    {
        $player = $this->seedPairingPlayer('precision-added');
        $brand = $this->seedBrand('Zqprecision');
        $this->seedCataloguePuzzle('Zq Precision Month', 500, $brand);
        $this->seedCataloguePuzzle('Zq Precision Year', 500, $brand);
        $this->seedCataloguePuzzle('Zq Precision Day', 500, $brand);

        [$import, $sheet] = $this->importOf($player);
        $month = $this->resultRow($import, $sheet, 'Zq Precision Month', 500, 'Zqprecision', '2021-03', 3600);
        $year = $this->resultRow($import, $sheet, 'Zq Precision Year', 500, 'Zqprecision', '2019', 3700);
        $day = $this->resultRow($import, $sheet, 'Zq Precision Day', 500, 'Zqprecision', '2021-03-15', 3800);
        $this->pair($import);

        $outcome = $this->addAndWork($import, $player);

        self::assertSame([], $outcome->skipped);
        self::assertSame(['added', 'added', 'added'], [$this->rowNow($month)['state'], $this->rowNow($year)['state'], $this->rowNow($day)['state']]);

        $stored = [];

        foreach ($this->resultsOf($player) as $result) {
            self::assertIsInt($result['seconds_to_solve']);
            self::assertIsString($result['tracked_at']);
            $stored[$result['seconds_to_solve']] = [$result['finished_at'], $result['finished_at_precision'], substr($result['tracked_at'], 0, 10)];
        }

        // The first day of the period, and the precision says that it is no day. `tracked_at` is noon of that day
        self::assertSame(['2021-03-01 00:00:00', 'month', '2021-03-01'], $stored[3600]);
        self::assertSame(['2019-01-01 00:00:00', 'year', '2019-01-01'], $stored[3700]);
        // A day is the default: nothing is stored for it
        self::assertSame(['2021-03-15 00:00:00', null, '2021-03-15'], $stored[3800]);
    }

    public function testTheSameMonthRowsImportedAgainAreAlreadyYours(): void
    {
        $player = $this->seedPairingPlayer('precision-again');
        $brand = $this->seedBrand('Zqagain');
        $this->seedCataloguePuzzle('Zq Again Month', 500, $brand);
        $this->seedCataloguePuzzle('Zq Again Year', 500, $brand);

        [$first, $firstSheet] = $this->importOf($player);
        $this->resultRow($first, $firstSheet, 'Zq Again Month', 500, 'Zqagain', '2021-03', 3600);
        $this->resultRow($first, $firstSheet, 'Zq Again Year', 500, 'Zqagain', '2019', 3700);
        $this->pair($first);
        $this->addAndWork($first, $player);
        self::assertCount(2, $this->resultsOf($player));

        // The same data in another file: the keys are the same - a month is keyed as its month, never as a day
        [$second, $secondSheet] = $this->importOf($player);
        $month = $this->resultRow($second, $secondSheet, 'Zq Again Month', 500, 'Zqagain', '2021-03', 3600);
        $year = $this->resultRow($second, $secondSheet, 'Zq Again Year', 500, 'Zqagain', '2019', 3700);
        $this->pair($second);
        $this->addAndWork($second, $player);

        self::assertCount(2, $this->resultsOf($player));
        self::assertSame(['already_yours', 'earlier_import'], [$this->rowNow($month)['state'], $this->rowNow($month)['already_yours_how']]);
        self::assertSame(['already_yours', 'earlier_import'], [$this->rowNow($year)['state'], $this->rowNow($year)['already_yours_how']]);
    }

    public function testAMonthIsComparedWithinTheMonthAndNeverByItsFirstDay(): void
    {
        $player = $this->seedPairingPlayer('precision-within');
        $brand = $this->seedBrand('Zqwithin');
        $within = $this->seedCataloguePuzzle('Zq Within Month', 500, $brand);
        $elsewhere = $this->seedCataloguePuzzle('Zq Within Elsewhere', 500, $brand);
        $timeless = $this->seedCataloguePuzzle('Zq Within Timeless', 500, $brand);
        $this->seedCataloguePuzzle('Zq Within Anchor', 500, $brand);
        $otherYear = $this->seedCataloguePuzzle('Zq Within Other Year', 500, $brand);

        // Typed by hand: the 14th of May, a day of June, and a result of a puzzle the file only says "solved, 2021" of
        $typedWithin = $this->typedResult($player, $within, '2024-05-14', 3600);
        $this->typedResult($player, $elsewhere, '2024-06-14', 3700);
        $typedTimeless = $this->typedResult($player, $timeless, '2021-08-08', 4100);
        $this->typedResult($player, $otherYear, '2022-02-02', 4200);

        [$import, $sheet] = $this->importOf($player);
        // "May 2024", the same time: the result of 14 May is that row
        $sameMonth = $this->resultRow($import, $sheet, 'Zq Within Month', 500, 'Zqwithin', '2024-05', 3600);
        // "May 2024", the profile has that time in June: may be the same written differently - asked, never added quietly
        $otherMonth = $this->resultRow($import, $sheet, 'Zq Within Elsewhere', 500, 'Zqwithin', '2024-05', 3700);
        // "Solved, 2021" without a time: any result of the puzzle the player has
        $noTime = $this->resultRow($import, $sheet, 'Zq Within Timeless', 500, 'Zqwithin', '2021', null);
        // "Solved, 2020" without a time, the profile has a result of 2022: another solve - never "already yours"
        $noTimeOtherYear = $this->resultRow($import, $sheet, 'Zq Within Other Year', 500, 'Zqwithin', '2020', null);
        // A month row that is added: the result stands on 1 March 2023
        $anchor = $this->resultRow($import, $sheet, 'Zq Within Anchor', 500, 'Zqwithin', '2023-03', 4000);
        $this->pair($import);
        $this->addAndWork($import, $player);

        self::assertSame(['already_yours', 'profile', $typedWithin], [$this->rowNow($sameMonth)['state'], $this->rowNow($sameMonth)['already_yours_how'], $this->rowNow($sameMonth)['already_yours_solving_time_id']]);
        self::assertSame(['waiting', 'similar_result_exists'], [$this->rowNow($otherMonth)['state'], $this->rowNow($otherMonth)['failure']]);
        self::assertSame(['already_yours', $typedTimeless], [$this->rowNow($noTime)['state'], $this->rowNow($noTime)['already_yours_solving_time_id']]);
        self::assertSame(['added', null], [$this->rowNow($noTimeOtherYear)['state'], $this->rowNow($noTimeOtherYear)['already_yours_solving_time_id']]);
        self::assertSame('added', $this->rowNow($anchor)['state']);
        self::assertCount(6, $this->resultsOf($player));

        // Another file says 1 March 2023 with that time: an exact day is never "already yours" through the result that
        // only knows "March 2023" and merely stands on 1 March. (That result is spoken for by its own row, so - like
        // any two files that date one solve differently - the row is a result of its own.)
        [$second, $secondSheet] = $this->importOf($player);
        $firstOfMonth = $this->resultRow($second, $secondSheet, 'Zq Within Anchor', 500, 'Zqwithin', '2023-03-01', 4000);
        $this->pair($second);
        $this->addAndWork($second, $player);

        self::assertSame(['added', null], [$this->rowNow($firstOfMonth)['state'], $this->rowNow($firstOfMonth)['already_yours_how']]);
        self::assertCount(7, $this->resultsOf($player));
    }

    public function testACoarserRowIsTheResultOfTheFinerRowOfTheSameFile(): void
    {
        $player = $this->seedPairingPlayer('precision-finer');
        $brand = $this->seedBrand('Zqfiner');
        $this->seedCataloguePuzzle('Zq Finer Puzzle', 500, $brand);

        // "Sept 2022" on one sheet, 14 Sep 2022 on another - the month row first in the file
        [$import, $sheet] = $this->importOf($player);
        $month = $this->resultRow($import, $sheet, 'Zq Finer Puzzle', 500, 'Zqfiner', '2022-09', 5000);
        $day = $this->resultRow($import, $sheet, 'Zq Finer Puzzle', 500, 'Zqfiner', '2022-09-14', 5000);
        $this->pair($import);
        $outcome = $this->addAndWork($import, $player);

        // The batch loop reaches the finer row after the month row added it on the way: the row is not queued any
        // more, so its own turn does nothing - each row ends exactly once, and the batch counts each once
        $batch = $this->batchNow($outcome->id);
        self::assertSame([2, 1, 1, 0, 1], [$batch['rows_requested'], $batch['rows_added'], $batch['rows_already_yours'], $batch['rows_refused'], $batch['results_added']]);
        self::assertSame('finished', $batch['state']);
        self::assertNotNull($outcome->id);

        // Its turn delivered once more (a second delivery of the job): skipped - still one result, the same rows
        $again = self::getContainer()->get(MessageBusInterface::class)->dispatch(new ApplyImportRow($day, $outcome->id, $player->id->toString()))->last(HandledStamp::class)?->getResult();
        self::assertIsArray($again);
        self::assertSame('skipped', $again['status']);

        // One result, the finer one: 14 September - whatever the order of the two rows
        $results = $this->resultsOf($player);
        self::assertCount(1, $results);
        self::assertSame(['2022-09-14 00:00:00', null], [$results[0]['finished_at'], $results[0]['finished_at_precision']]);
        self::assertSame('added', $this->rowNow($day)['state']);
        self::assertSame(['already_yours', 'equal_row', $results[0]['id']], [$this->rowNow($month)['state'], $this->rowNow($month)['already_yours_how'], $this->rowNow($month)['already_yours_solving_time_id']]);
    }
}
