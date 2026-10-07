<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\SuspiciousTimes;

use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetPlayerPaces;
use SpeedPuzzling\Web\Query\GetPlayerPrediction;
use SpeedPuzzling\Web\Query\GetSuspicionEntryFacts;
use SpeedPuzzling\Web\Query\GetSuspiciousTimeEvidence;
use SpeedPuzzling\Web\Query\GetSuspiciousTimeReferences;
use SpeedPuzzling\Web\Value\PaceRequest;
use SpeedPuzzling\Web\Value\PuzzlingType;
use SpeedPuzzling\Web\Value\SolveMoment;
use SpeedPuzzling\Web\Value\SuspicionAssessment;
use SpeedPuzzling\Web\Value\SuspicionEvidenceRequest;
use SpeedPuzzling\Web\Value\SuspicionInput;
use Throwable;

/**
 * The scan's classifier for one time being entered (docs/features/suspicious-time-review.md, "Catch it while typing"
 * and "When the player edits a marked time"): the add/edit form's live check and the edit handler's re-check.
 *
 * The expectation is the live prediction (GetPlayerPrediction::forPuzzle() as of the solve, the edited time itself
 * left out), then the player's baseline × difficulty, then the pace on their other solo results; pair/team entries
 * are judged by the community's slow floor only - like the scan. A personal prediction built on a far too slow attempt
 * is not trusted, like in the scan: the live prediction's last attempt and whether an earlier attempt has a slow case
 * go into the classifier's input. Explanations are looked up only for a raised entry.
 * Measured on a copy of production: ~1 ms for a typical check; a raised one 2-5 ms p50, 5-7 ms p95 without the edition
 * lookup (the form), 18-22 / 40-47 ms with it.
 *
 * Fails open: whatever goes wrong, the answer is null (no judgement) and a warning is logged - a check must never cost
 * a player the save.
 */
readonly final class SingleTimeSuspicionCheck
{
    private const string KEY = 'entry';

    public function __construct(
        private GetPlayerPrediction $getPlayerPrediction,
        private GetSuspicionEntryFacts $getEntryFacts,
        private GetSuspiciousTimeReferences $getReferences,
        private GetPlayerPaces $getPlayerPaces,
        private GetSuspiciousTimeEvidence $getEvidence,
        private SuspiciousTimeClassifier $classifier,
        private LoggerInterface $logger,
    ) {
    }

    public function forEntry(
        string $playerId,
        string $puzzleId,
        int $seconds,
        PuzzlingType $puzzlingType,
        int $puzzlersCount,
        null|string $excludeTimeId,
        SolveMoment $moment,
        // false = no "another edition" explanation: the add/edit form (the lookup costs 20-40 ms, everything else ~1 ms)
        bool $withOtherEditions = true,
    ): null|SuspicionAssessment {
        try {
            return $this->check($playerId, $puzzleId, $seconds, $puzzlingType, $excludeTimeId, $moment, $withOtherEditions);
        } catch (Throwable $e) {
            $this->logger->warning('Time verification: the check of one entry failed - the entry was not judged', [
                'playerId' => $playerId,
                'puzzleId' => $puzzleId,
                'seconds' => $seconds,
                'puzzlingType' => $puzzlingType->value,
                'puzzlersCount' => $puzzlersCount,
                'exception' => $e,
            ]);

            return null;
        }
    }

    private function check(
        string $playerId,
        string $puzzleId,
        int $seconds,
        PuzzlingType $puzzlingType,
        null|string $excludeTimeId,
        SolveMoment $moment,
        bool $withOtherEditions,
    ): null|SuspicionAssessment {
        if (!Uuid::isValid($playerId) || !Uuid::isValid($puzzleId) || ($excludeTimeId !== null && !Uuid::isValid($excludeTimeId))) {
            return null;
        }

        $facts = $this->getEntryFacts->forEntry($playerId, $puzzleId);

        if ($facts === null) {
            return null;
        }

        $references = $this->getReferences->stored();
        $solo = $puzzlingType === PuzzlingType::Solo;
        $predictedSeconds = null;
        $previousAttemptSeconds = null;
        $previousAttemptRaisedSlow = false;
        $paceFactor = null;

        if ($solo) {
            $prediction = $this->getPlayerPrediction->forPuzzle($playerId, $puzzleId, $excludeTimeId, $moment);
            $predictedSeconds = $prediction?->predictedSeconds;
            // What a stored prediction keeps as prediction_last_time_seconds: the attempt a personal prediction came from
            $previousAttemptSeconds = $prediction?->isPersonalized === true ? $prediction->lastTimeSeconds : null;

            // Asked only when it can matter, like the scan: a fast raise against a personal prediction
            if ($previousAttemptSeconds !== null && $predictedSeconds !== null && $predictedSeconds >= SuspiciousTimeClassifier::PREDICTION_RAISE_RATIO * $seconds) {
                $previousAttemptRaisedSlow = $this->getEntryFacts->earlierAttemptRaisedSlow($playerId, $puzzleId, $excludeTimeId, $moment);
            }

            if (SuspiciousTimeClassifier::needsPace($predictedSeconds, $facts['baseline_seconds'], $seconds, $previousAttemptSeconds)) {
                $paceFactor = $this->getPlayerPaces->forTimes(
                    [PaceRequest::at(self::KEY, $playerId, $excludeTimeId, $moment->solvedAt)],
                    $references,
                )[self::KEY] ?? null;
            }
        }

        $input = new SuspicionInput(
            piecesCount: $facts['pieces_count'],
            seconds: $seconds,
            puzzlingType: $puzzlingType,
            predictedSeconds: $predictedSeconds,
            baselineSeconds: $solo ? $facts['baseline_seconds'] : null,
            difficultyScore: $solo ? $facts['difficulty_score'] : null,
            paceFactor: $paceFactor,
            references: $references,
            previousAttemptSeconds: $previousAttemptSeconds,
            previousAttemptRaisedSlow: $previousAttemptRaisedSlow,
        );

        $assessment = $this->classifier->classify($input);

        if ($assessment->isRaised() === false) {
            return $assessment;
        }

        $evidence = $this->getEvidence->forTimes([
            new SuspicionEvidenceRequest(
                key: self::KEY,
                timeId: $excludeTimeId,
                playerId: $playerId,
                puzzleId: $puzzleId,
                solvedDay: $moment->solvedAt->format('Y-m-d'),
            ),
        ], $withOtherEditions)[self::KEY] ?? null;

        return $evidence === null ? $assessment : $this->classifier->classify($input->withEvidence($evidence));
    }
}
