<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\ConsoleCommands;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\ConsoleCommands\ReportPredictionCalibrationConsoleCommand;
use SpeedPuzzling\Web\Query\GetPredictionCalibration;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\TimePredictionCalculator;
use SpeedPuzzling\Web\Tests\TestDouble\InMemoryLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The daily calibration monitor: hit rates per bucket from the stored live predictions of the current model, a
 * warning (a Sentry issue) for a bucket with enough of them that drifted from its target.
 */
final class ReportPredictionCalibrationConsoleCommandTest extends KernelTestCase
{
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testABucketFarFromItsTargetIsAWarning(): void
    {
        /** @var list<array{id: string, seconds_to_solve: int}> $times */
        $times = $this->database->fetchAllAssociative("
            SELECT id, seconds_to_solve FROM puzzle_solving_time
            WHERE puzzling_type = 'solo' AND seconds_to_solve > 0 AND suspicious = false AND unboxed = false
            ORDER BY id LIMIT 14
        ");
        self::assertCount(14, $times);

        // Bucket A: every actual time inside both windows (100 % - far from 50 / 80); bucket B: 5 of 10 inside the
        // 50 % window, 8 of 10 inside the 80 % one - on target
        foreach ($times as $index => $time) {
            $seconds = $time['seconds_to_solve'];
            [$bucket, $inside50, $inside80] = $index < 4
                ? ['f|c1|k20|pieces_500', true, true]
                : ['r|c1|pieces_500', $index < 9, $index < 12];

            $this->database->executeStatement("
                INSERT INTO solving_time_prediction (id, time_id, predictor, model_version, source, predictable, method, predicted_seconds,
                    range50_low, range50_high, range80_low, range80_high, window_bucket, computed_at)
                VALUES (gen_random_uuid(), :time, 'statistical', :version, 'live', true, 'statistical', :seconds,
                    :low50, :high50, :low80, :high80, :bucket, NOW())
            ", [
                'time' => $time['id'],
                'version' => TimePredictionCalculator::MODEL_VERSION,
                'seconds' => $seconds,
                'low50' => $inside50 ? $seconds - 10 : $seconds + 10,
                'high50' => $inside50 ? $seconds + 10 : $seconds + 20,
                'low80' => $inside80 ? $seconds - 20 : $seconds + 10,
                'high80' => $seconds + 30,
                'bucket' => $bucket,
            ]);
        }

        /** @var ClockInterface $clock */
        $clock = self::getContainer()->get(ClockInterface::class);
        $rows = self::getContainer()->get(GetPredictionCalibration::class)->since($clock->now()->modify('-1 day'));
        self::assertSame([
            ['bucket' => 'f|c1|k20|pieces_500', 'n' => 4, 'hit50' => 4, 'hit80' => 4],
            ['bucket' => 'r|c1|pieces_500', 'n' => 10, 'hit50' => 5, 'hit80' => 8],
        ], $rows);

        $logger = new InMemoryLogger();
        $command = new ReportPredictionCalibrationConsoleCommand(self::getContainer()->get(GetPredictionCalibration::class), $clock, $logger);
        $tester = new CommandTester($command);
        $tester->execute(['--min-samples' => '4']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('DRIFTED', $tester->getDisplay());

        $drifted = array_map(static fn (array $record): mixed => $record['context']['bucket'] ?? null, $logger->records);
        // The first-try bucket (100 % / 100 %) and so all first tries; the repeats are on target
        self::assertContains('f|c1|k20|pieces_500', $drifted);
        self::assertContains('all first tries', $drifted);
        self::assertNotContains('r|c1|pieces_500', $drifted);
        self::assertNotContains('all repeats', $drifted);
    }
}
