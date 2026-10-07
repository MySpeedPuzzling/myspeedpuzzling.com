<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Entity\SuspiciousTimeCase;
use SpeedPuzzling\Web\Services\SuspiciousTimes\SuspicionFingerprint;
use SpeedPuzzling\Web\Services\SuspiciousTimes\SuspiciousTimeClassifier;
use SpeedPuzzling\Web\Value\ExpectedTimeSource;
use SpeedPuzzling\Web\Value\SuspicionAssessment;
use SpeedPuzzling\Web\Value\SuspicionCheckOutcome;
use SpeedPuzzling\Web\Value\SuspiciousTimeReason;
use SpeedPuzzling\Web\Value\SuspiciousTimeReasonCode;
use SpeedPuzzling\Web\Value\SuspiciousTimeTier;

/**
 * More pending cases for the time verification queue tests than SuspiciousTimesFixture holds: a copy of a fixture
 * time (ClonesSolvingTimes), raised the way the scan would.
 */
trait SuspiciousTimeQueueCases
{
    use ClonesSolvingTimes;

    /**
     * @param array<string, int|bool|string> $changes column => value of the copied time
     * @return array{caseId: string, timeId: string}
     */
    private function raiseCopyOf(
        string $sourceTimeId,
        array $changes,
        SuspiciousTimeTier $tier = SuspiciousTimeTier::Strong,
        SuspiciousTimeReasonCode $trigger = SuspiciousTimeReasonCode::FasterThanUsual,
        float $score = 3.0,
        int $expectedSeconds = 27000,
    ): array {
        $timeId = $this->cloneSolvingTime($sourceTimeId, $changes);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $time = $entityManager->find(PuzzleSolvingTime::class, $timeId);
        assert($time instanceof PuzzleSolvingTime);

        $caseId = Uuid::uuid7();
        $entityManager->persist(SuspiciousTimeCase::detected(
            $caseId,
            $time,
            new SuspicionAssessment(
                outcome: SuspicionCheckOutcome::Raised,
                tier: $tier,
                ratio: $score,
                expectedSeconds: $expectedSeconds,
                expectedSource: ExpectedTimeSource::Baseline,
                reasons: [new SuspiciousTimeReason($trigger, ['expected' => $expectedSeconds, 'entered' => $time->secondsToSolve, 'ratio' => $score, 'pieces' => $time->puzzle->piecesCount, 'source' => 'baseline'])],
                score: $score,
            ),
            SuspicionFingerprint::ofTime($time),
            SuspiciousTimeClassifier::VERSION,
            self::getContainer()->get(ClockInterface::class)->now(),
        ));
        $entityManager->flush();

        return ['caseId' => $caseId->toString(), 'timeId' => $timeId];
    }
}
