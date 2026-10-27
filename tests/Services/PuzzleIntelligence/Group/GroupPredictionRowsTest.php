<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\PuzzleIntelligence\Group;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\ImageEstimateEvaluation;
use SpeedPuzzling\Web\Message\EvaluatePredictionCandidate;
use SpeedPuzzling\Web\Message\FitGroupPredictionModel;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Group\GroupPredictionModel;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PuzzleIntelligenceRecalculator;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Tests\Services\PuzzleIntelligence\Evaluation\RecordPath;
use SpeedPuzzling\Web\Tests\Services\PuzzleIntelligence\FitsThePredictionModel;
use SpeedPuzzling\Web\Value\PiecesGroup;
use SpeedPuzzling\Web\Value\PredictorKind;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * The pair/team model's daily job (FitGroupPredictionModel - docs/features/puzzle-intelligence/README.md "Pair and team
 * predictions") writes only its own fit and its own predictors' rows: the shown predictions, every other predictor's
 * rows, the coordination constants and the shown fit stay exactly as they were. The fixtures' two pair results
 * (TIME_12, TIME_41).
 */
final class GroupPredictionRowsTest extends KernelTestCase
{
    use FitsThePredictionModel;

    private Connection $database;
    private MessageBusInterface $messageBus;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->database = $container->get(Connection::class);
        $this->messageBus = $container->get(MessageBusInterface::class);

        /** @var PuzzleIntelligenceRecalculator $recalculator */
        $recalculator = $container->get(PuzzleIntelligenceRecalculator::class);
        $recalculator->recalculate();
        $this->fitPredictionModel();
    }

    /**
     * The guard: nothing shown changes when the pair/team model is fitted and its rows are written.
     */
    public function testTheDailyJobWritesOnlyItsOwnFitAndRows(): void
    {
        $before = $this->everythingShown();

        $summary = $this->runJob(new FitGroupPredictionModel(writeRows: true));

        self::assertSame($before, $this->everythingShown());
        $groupResults = $this->groupResults();
        self::assertGreaterThanOrEqual(2, $groupResults);
        self::assertSame($groupResults, $summary['times_written'], 'Every pair/team result, on the first fill');
        self::assertSame(1, $this->number('SELECT COUNT(*) FROM group_prediction_fit WHERE model_version = :version', ['version' => GroupPredictionModel::MODEL_VERSION]));

        /** @var list<array{time_id: string, predictor: string, model_version: int, source: string, puzzling_type: string}> $rows */
        $rows = $this->database->fetchAllAssociative("
            SELECT r.time_id, r.predictor, r.model_version, r.source, pst.puzzling_type
            FROM solving_time_prediction r JOIN puzzle_solving_time pst ON pst.id = r.time_id
            WHERE r.predictor IN ('group', 'group_baseline') ORDER BY r.time_id, r.predictor
        ");
        self::assertCount(2 * $groupResults, $rows, 'Both predictors of every pair/team result');
        $pairs = array_map(static fn (array $row): string => $row['time_id'] . ' ' . $row['predictor'], $rows);
        self::assertContains(PuzzleSolvingTimeFixture::TIME_12 . ' group', $pairs);
        self::assertContains(PuzzleSolvingTimeFixture::TIME_12 . ' group_baseline', $pairs);
        self::assertContains(PuzzleSolvingTimeFixture::TIME_41 . ' group', $pairs);

        foreach ($rows as $row) {
            self::assertSame(GroupPredictionModel::MODEL_VERSION, (int) $row['model_version']);
            self::assertSame('reconstructed', $row['source'], 'The first fill: the fit has seen them');
            self::assertNotSame('solo', $row['puzzling_type']);
        }

        self::assertSame($groupResults, $this->number('SELECT COUNT(*) FROM group_prediction_input'));

        // The next day: nothing changed, nothing written
        self::assertSame(0, $this->runJob(new FitGroupPredictionModel(writeRows: true))['times_written']);

        // Another day for one of them: only that one is predicted again
        $this->database->executeStatement("UPDATE puzzle_solving_time SET finished_at = COALESCE(finished_at, tracked_at) - INTERVAL '3 days' WHERE id = :id", ['id' => PuzzleSolvingTimeFixture::TIME_12]);
        self::assertSame(1, $this->runJob(new FitGroupPredictionModel(writeRows: true))['times_written']);

        // A time that is no pair result any more loses its rows
        $this->database->executeStatement('UPDATE puzzle_solving_time SET suspicious = true WHERE id = :id', ['id' => PuzzleSolvingTimeFixture::TIME_41]);
        self::assertSame(1, $this->runJob(new FitGroupPredictionModel(writeRows: true))['times_forgotten']);
        self::assertSame(0, $this->number('SELECT COUNT(*) FROM solving_time_prediction WHERE time_id = :id AND predictor IN (\'group\', \'group_baseline\')', ['id' => PuzzleSolvingTimeFixture::TIME_41]));
    }

    public function testWithTheSwitchOffOnlyTheFitIsStored(): void
    {
        $summary = $this->runJob(new FitGroupPredictionModel());

        self::assertSame(0, $summary['times_written']);
        self::assertSame(1, $this->number('SELECT COUNT(*) FROM group_prediction_fit'));
        self::assertSame(0, $this->number("SELECT COUNT(*) FROM solving_time_prediction WHERE predictor IN ('group', 'group_baseline')"));
        self::assertSame(0, $this->number('SELECT COUNT(*) FROM group_prediction_input'));
    }

    /**
     * The candidate gate judges the pair/team rows like any other stored candidate - on pair/team results, sliced by
     * size, experience and unknown members.
     */
    public function testTheCandidateGateReadsThePairAndTeamRows(): void
    {
        $this->runJob(new FitGroupPredictionModel(writeRows: true));
        // Two results are too few to fit windows: give them some (the gate reads what is stored)
        $this->database->executeStatement("
            UPDATE solving_time_prediction r SET predictable = true, method = 'statistical',
                predicted_seconds = pst.seconds_to_solve, range50_low = pst.seconds_to_solve - 60, range50_high = pst.seconds_to_solve + 60,
                range80_low = pst.seconds_to_solve - 120, range80_high = pst.seconds_to_solve + 120, k_indices = 7,
                window_bucket = CASE WHEN r.predictor = 'group' THEN 'first|e1-2|u0|solves|2' ELSE 'baseline|2' END
            FROM puzzle_solving_time pst
            WHERE pst.id = r.time_id AND r.predictor IN ('group', 'group_baseline')
        ");

        $evaluation = $this->messageBus->dispatch(new EvaluatePredictionCandidate(PredictorKind::Group, GroupPredictionModel::MODEL_VERSION, PredictorKind::GroupBaseline, GroupPredictionModel::MODEL_VERSION))->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(ImageEstimateEvaluation::class, $evaluation);
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        self::assertSame('group@1 vs group_baseline@1', $evaluation->subject);
        self::assertSame($this->groupResults(), $evaluation->overall['rows'], 'Every pair/team result - and no solo result');
        $groups = array_column($evaluation->groups, null, 'sizeGroup');
        self::assertArrayHasKey(PiecesGroup::Thousand->value, $groups);
        self::assertArrayHasKey('size 2', RecordPath::array($groups, PiecesGroup::Thousand->value, 'slices'));
        self::assertArrayHasKey('experience 1-2', RecordPath::array($groups, PiecesGroup::Thousand->value, 'slices'));
    }

    /**
     * @param array<string, mixed> $params
     */
    private function number(string $sql, array $params = []): int
    {
        $value = $this->database->fetchOne($sql, $params);
        self::assertTrue(is_int($value) || is_string($value));

        return (int) $value;
    }

    private function groupResults(): int
    {
        return $this->number("SELECT COUNT(*) FROM puzzle_solving_time WHERE puzzling_type IN ('duo', 'team') AND suspicious = false AND seconds_to_solve > 0 AND puzzling_team_id IS NOT NULL");
    }

    /**
     * @return array<string, int|float|string>
     */
    private function runJob(FitGroupPredictionModel $message): array
    {
        /** @var array<string, int|float|string> $summary */
        $summary = $this->messageBus->dispatch($message)->last(HandledStamp::class)?->getResult();
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        return $summary;
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function everythingShown(): array
    {
        return [
            'shown predictions' => $this->database->fetchAllAssociative('
                SELECT id, predictable, prediction_method, predicted_seconds, predicted_range_low_seconds, predicted_range_high_seconds,
                    predicted_range80_low_seconds, predicted_range80_high_seconds, prediction_source, prediction_model_version
                FROM puzzle_solving_time ORDER BY id
            '),
            'other predictors' => $this->database->fetchAllAssociative("SELECT * FROM solving_time_prediction WHERE predictor NOT IN ('group', 'group_baseline') ORDER BY time_id, predictor, model_version"),
            'coordination constants' => $this->database->fetchAllAssociative('SELECT group_size, first_attempt, coordination, sample_size, fitted FROM group_coordination_constant ORDER BY 1, 2'),
            'model fits' => $this->database->fetchAllAssociative('SELECT model_version, fitted_at, parameters::text AS parameters FROM prediction_model_fit ORDER BY 1'),
            'difficulty' => $this->database->fetchAllAssociative('SELECT * FROM puzzle_difficulty ORDER BY puzzle_id'),
            'pace history' => $this->database->fetchAllAssociative('SELECT * FROM player_pace_history ORDER BY player_id, pieces_count, valid_from, qualifying_count'),
        ];
    }
}
