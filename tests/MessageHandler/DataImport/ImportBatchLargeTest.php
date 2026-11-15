<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler\DataImport;

use Psr\Log\NullLogger;
use SpeedPuzzling\Web\MessageHandler\ProcessImportBatchHandler;
use SpeedPuzzling\Web\Repository\DataImportRowRepository;
use SpeedPuzzling\Web\Repository\DataImportSettingsRepository;
use SpeedPuzzling\Web\Services\DataImport\ImportProgress;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The largest "Add" of a day - 2,000 rows (DataImportLimits::MAX_RESULTS_ADDED_PER_DAY) - goes through in turns without
 * keeping the rows it went through in memory, and with a bounded number of statements per row (README "Performance": adding a row
 * ≤ 15 statements of its own; counted here with everything around a row - the transaction's savepoint, the lock,
 * the first pass).
 */
final class ImportBatchLargeTest extends KernelTestCase
{
    use ImportAddingScenario;

    private const int PUZZLES = 20;
    private const int ROWS_PER_PUZZLE = 100;
    /** Statements per row, the first pass and the transaction's own statements included */
    private const int STATEMENTS_PER_ROW = 45;
    /**
     * What the worker may hold more at the end than after its second turn. Measured 2026-11-15: 8-19 MB over the 2,000
     * rows (4-9 KB a row, in the first pass and in adding alike - what a debug kernel keeps per handled message); a
     * worker that keeps the rows' objects (no fresh entity manager per row, no reset between turns) grows by 200 MB.
     */
    private const int MEMORY_GROWTH_BYTES = 40 * 1024 * 1024;

    public function testTwoThousandRowsAreAddedWithFlatMemoryAndABoundedNumberOfStatementsPerRow(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $player = $this->seedPairingPlayer('large');
        $brand = $this->seedBrand('Zqlarge');
        [$import, $sheet] = $this->importOf($player);

        for ($puzzle = 1; $puzzle <= self::PUZZLES; $puzzle++) {
            $name = sprintf('Zq Large Puzzle %02d', $puzzle);
            $this->seedCataloguePuzzle($name, 500, $brand);
            $this->resultRow($import, $sheet, $name, 500, 'Zqlarge', '2015-01-01', 3000 + $puzzle);
        }

        $this->pair($import);
        // 99 more results of each puzzle, a day apart - rows as the parser leaves them, copied set-based
        $this->pairingDatabase()->executeStatement(<<<SQL
INSERT INTO data_import_row (id, import_id, sheet_id, row_number, first_seen_version, last_seen_version, kind, parsed, parsed_version, source_key, equal_key, puzzle_key, import_puzzle_id, state, add_as_separate, result_made)
SELECT
    gen_random_uuid(), base.import_id, base.sheet_id, base.row_number * 1000 + n, 1, 1, 'result',
    jsonb_build_object('date', to_char(DATE '2015-01-01' + n, 'YYYY-MM-DD'), 'datePrecision', 'day', 'seconds', 3000 + n, 'type', 'solo'),
    1,
    '1:' || encode(sha256(convert_to(base.id::text || ':' || n::text, 'UTF8')), 'hex'),
    '1:' || encode(sha256(convert_to(base.id::text || ':' || n::text, 'UTF8')), 'hex'),
    base.puzzle_key, base.import_puzzle_id, 'waiting', false, false
FROM data_import_row base
CROSS JOIN generate_series(1, :more) AS n
WHERE base.import_id = :importId
SQL, ['importId' => $import->id->toString(), 'more' => self::ROWS_PER_PUZZLE - 1]);

        $rows = self::PUZZLES * self::ROWS_PER_PUZZLE;
        $outcome = $this->add($import, $player);
        self::assertSame([$rows, []], [$outcome->rows, $outcome->skipped]);
        self::assertNotNull($outcome->id);

        /** @var DebugDataHolder $statements */
        $statements = $container->get('doctrine.debug_data_holder');
        $handler = new ProcessImportBatchHandler(
            $container->get(MessageBusInterface::class),
            $container->get(DataImportRowRepository::class),
            $container->get(DataImportSettingsRepository::class),
            $this->pairingEntityManager(),
            $container->get(ImportProgress::class),
            new NullLogger(),
            rowsPerTurn: 250,
            secondsPerTurn: 600.0,
        );

        /** @var \Symfony\Contracts\Service\ResetInterface $resetter */
        $resetter = $container->get('services_resetter');
        $statements->reset();
        $counted = 0;
        $memory = [];

        // 8 turns of the first pass, 8 of adding, 4 of the follow-up (500 results a turn) and the end - a few to spare
        for ($turn = 0; $turn < 32; $turn++) {
            if ($this->batchNow($outcome->id)['state'] !== 'running') {
                break;
            }

            $handler($this->currentTurnOf($outcome->id));

            $ran = $statements->getData()['default'] ?? [];
            $counted += is_array($ran) ? count($ran) : 0;
            // What the messenger worker does after every job it consumed (ResetServicesListener): the services that
            // remember things for one job forget them - in a test also what the debug kernel collects (the traceable
            // bus, the statements, the log)
            $resetter->reset();
            $this->pairingEntityManager()->clear();
            gc_collect_cycles();
            $memory[] = memory_get_usage();
        }

        $batch = $this->batchNow($outcome->id);
        self::assertSame(['finished', $rows, $rows, 0, 0], [$batch['state'], $batch['rows_added'], $batch['results_added'], $batch['rows_refused'], $batch['rows_failed']]);
        self::assertSame($rows, $this->pairingCount('SELECT COUNT(*) FROM puzzle_solving_time WHERE player_id = :id', ['id' => $player->id->toString()]));
        self::assertSame(self::ROWS_PER_PUZZLE, $this->pairingCount('SELECT MAX(solved_times_count) FROM puzzle_statistics ps INNER JOIN puzzle ON puzzle.id = ps.puzzle_id WHERE puzzle.name LIKE :name', ['name' => 'Zq Large Puzzle %']));

        self::assertLessThanOrEqual(
            self::STATEMENTS_PER_ROW,
            $counted / $rows,
            sprintf('%d statements for %d rows.', $counted, $rows),
        );
        // After the second turn the worker holds what it will ever hold: the turns that follow add nothing to it
        self::assertGreaterThan(6, count($memory));
        $later = array_slice($memory, 2, -1);
        self::assertNotEmpty($later);
        $growth = max($later) - $memory[1];
        self::assertLessThan(self::MEMORY_GROWTH_BYTES, $growth, sprintf('Memory grew by %.1f MB between the second turn and the last.', $growth / 1024 / 1024));
    }
}
