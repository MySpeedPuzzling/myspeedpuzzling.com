<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\DuplicatePuzzleSignal;
use SpeedPuzzling\Web\Message\DetectDuplicatePuzzleSignals;
use SpeedPuzzling\Web\Query\GetDuplicatePuzzleSignalCandidates;
use SpeedPuzzling\Web\Repository\DuplicatePuzzleSignalRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Results\DuplicatePuzzleSignalDetectionSummary;
use SpeedPuzzling\Web\Services\DuplicateResults\DuplicatePuzzleSignalScoring;
use SpeedPuzzling\Web\Value\DuplicatePuzzleSignalStatus;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Stores every new catalogue signal and keeps the open ones current, scores included (docs/features/duplicate-results.md,
 * Layer 4).
 * An open signal whose pair no longer matches (a result moved to the right puzzle, edited or deleted) holds no
 * decision and is removed; a proposed or dismissed one stays, so a decision sticks and the pair is never raised
 * again. Merged puzzles take their signals along (ON DELETE CASCADE).
 */
#[AsMessageHandler]
readonly final class DetectDuplicatePuzzleSignalsHandler
{
    public function __construct(
        private GetDuplicatePuzzleSignalCandidates $getDuplicatePuzzleSignalCandidates,
        private DuplicatePuzzleSignalRepository $signalRepository,
        private PuzzleRepository $puzzleRepository,
        private DuplicatePuzzleSignalScoring $scoring,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(DetectDuplicatePuzzleSignals $message): DuplicatePuzzleSignalDetectionSummary
    {
        $now = $this->clock->now();
        $candidates = $this->getDuplicatePuzzleSignalCandidates->all();
        $signals = $this->signalRepository->allByPair();
        $matchingKeys = [];
        $newSignals = 0;
        $strongPairs = 0;

        foreach ($candidates as $candidate) {
            $key = DuplicatePuzzleSignal::key($candidate->puzzleAId, $candidate->puzzleBId);
            $matchingKeys[$key] = true;
            $examplePlayerId = Uuid::fromString($candidate->examplePlayerId);
            $score = $this->scoring->score($candidate);

            if (!$score->isWeak()) {
                $strongPairs++;
            }

            if (isset($signals[$key])) {
                $signals[$key]->refresh(
                    matchingResults: $candidate->matchingResults,
                    matchingPeople: $candidate->matchingPeople,
                    examplePlayerId: $examplePlayerId,
                    exampleSeconds: $candidate->exampleSeconds,
                    exampleDay: $candidate->exampleDay,
                    score: $score->score,
                    reasons: $score->reasonValues(),
                    nameSimilarity: $score->nameSimilarity,
                    weak: $score->isWeak(),
                );

                continue;
            }

            $this->signalRepository->save(new DuplicatePuzzleSignal(
                id: Uuid::uuid7(),
                puzzleA: $this->puzzleRepository->get($candidate->puzzleAId),
                puzzleB: $this->puzzleRepository->get($candidate->puzzleBId),
                detectedAt: $now,
                matchingResults: $candidate->matchingResults,
                matchingPeople: $candidate->matchingPeople,
                examplePlayerId: $examplePlayerId,
                exampleSeconds: $candidate->exampleSeconds,
                exampleDay: $candidate->exampleDay,
                score: $score->score,
                reasons: $score->reasonValues(),
                nameSimilarity: $score->nameSimilarity,
                weak: $score->isWeak(),
            ));

            $newSignals++;
        }

        $removedSignals = 0;

        foreach ($signals as $key => $signal) {
            if ($signal->status === DuplicatePuzzleSignalStatus::Open && !isset($matchingKeys[$key])) {
                $this->signalRepository->delete($signal);
                $removedSignals++;
            }
        }

        return new DuplicatePuzzleSignalDetectionSummary(
            pairs: count($candidates),
            strongPairs: $strongPairs,
            newSignals: $newSignals,
            removedSignals: $removedSignals,
        );
    }
}
