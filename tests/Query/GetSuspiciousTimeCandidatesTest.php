<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Query\GetSuspiciousTimeCandidates;
use SpeedPuzzling\Web\Results\SuspicionCandidate;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\TimePredictionCalculator;
use SpeedPuzzling\Web\Services\SuspiciousTimes\SuspiciousTimeClassifier;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The scan's expected time from a stored first-try prediction (docs/features/suspicious-time-review.md, "The expected
 * time"): only one v2 would have made - on a rated puzzle and the player's own pace. A prediction shrunk toward a
 * prior or resting on the 500-piece pace scaled to a thin size (prediction model v3) is left out.
 */
final class GetSuspiciousTimeCandidatesTest extends KernelTestCase
{
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testAFirstTryPredictionCountsOnlyOnARatedPuzzleAndTheOwnPace(): void
    {
        $timeId = PuzzleSolvingTimeFixture::TIME_06;
        $this->database->executeStatement(
            "UPDATE puzzle_solving_time SET predictable = true, prediction_method = 'statistical', predicted_seconds = 1234,
                prediction_source = 'reconstructed', prediction_model_version = :version, prediction_computed_at = NOW() WHERE id = :id",
            ['id' => $timeId, 'version' => TimePredictionCalculator::MODEL_VERSION],
        );
        $this->database->executeStatement(
            "INSERT INTO solving_time_prediction (id, time_id, predictor, model_version, source, predictable, method, predicted_seconds, difficulty_source, pace_source, computed_at)
            VALUES (gen_random_uuid(), :id, 'statistical', :version, 'reconstructed', true, 'statistical', 1234, 'solves', 'own', NOW())",
            ['id' => $timeId, 'version' => TimePredictionCalculator::MODEL_VERSION],
        );

        self::assertSame(1234, $this->candidate($timeId)->predictedSeconds, 'Rated puzzle, own pace');

        $this->database->executeStatement("UPDATE solving_time_prediction SET pace_source = 'scaled_500' WHERE time_id = :id", ['id' => $timeId]);
        self::assertNull($this->candidate($timeId)->predictedSeconds, 'The 500-piece pace scaled to a thin size');

        $this->database->executeStatement("UPDATE solving_time_prediction SET pace_source = 'interpolated', difficulty_source = 'shrunk' WHERE time_id = :id", ['id' => $timeId]);
        self::assertNull($this->candidate($timeId)->predictedSeconds, 'A difficulty shrunk toward a prior');

        $this->database->executeStatement("UPDATE solving_time_prediction SET difficulty_source = 'solves' WHERE time_id = :id", ['id' => $timeId]);
        self::assertSame(1234, $this->candidate($timeId)->predictedSeconds, 'An interpolated own pace counts, as in v2');
    }

    private function candidate(string $timeId): SuspicionCandidate
    {
        /** @var GetSuspiciousTimeCandidates $candidates */
        $candidates = self::getContainer()->get(GetSuspiciousTimeCandidates::class);
        /** @var string $puzzleId */
        $puzzleId = $this->database->fetchOne('SELECT puzzle_id FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId]);

        $this->database->beginTransaction();

        try {
            foreach ($candidates->batches(SuspiciousTimeClassifier::VERSION, new DateTimeImmutable('2000-01-01'), 1000, $puzzleId) as $batch) {
                foreach ($batch as $candidate) {
                    if ($candidate->timeId === $timeId) {
                        return $candidate;
                    }
                }
            }
        } finally {
            $this->database->rollBack();
        }

        self::fail("Time {$timeId} is no candidate");
    }
}
