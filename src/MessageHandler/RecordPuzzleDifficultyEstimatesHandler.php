<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\PuzzleDifficultyEstimate;
use SpeedPuzzling\Web\Message\RecordPuzzleDifficultyEstimates;
use SpeedPuzzling\Web\Repository\PuzzleDifficultyEstimateRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Value\PuzzleDifficultyEstimateInput;
use SpeedPuzzling\Web\Value\PuzzleDifficultyEstimateOutcome;
use SpeedPuzzling\Web\Value\PuzzleImageFingerprint;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Every item in one transaction: the puzzles and their stored estimates are read in two statements, then each item is
 * checked before anything of it changes - a secret puzzle or picture (`hide_until` / `hide_image_until` ahead) never
 * gets an estimate, and an estimate counts only for the picture the puzzle has now (PuzzleImageFingerprint). Stored =
 * created, or replacing the stored one whole (the current value only - a push of the same item again changes nothing
 * but `receivedAt`). The same puzzle twice in one batch: the later item wins.
 */
#[AsMessageHandler]
readonly final class RecordPuzzleDifficultyEstimatesHandler
{
    public function __construct(
        private PuzzleRepository $puzzleRepository,
        private PuzzleDifficultyEstimateRepository $estimateRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array<int, PuzzleDifficultyEstimateOutcome> keyed like the message's estimates
     */
    public function __invoke(RecordPuzzleDifficultyEstimates $message): array
    {
        $now = $this->clock->now();
        $puzzleIds = array_values(array_unique(array_map(
            static fn (PuzzleDifficultyEstimateInput $input): string => $input->puzzleId,
            $message->estimates,
        )));
        $puzzles = $this->puzzleRepository->findByIds($puzzleIds);
        $stored = $this->estimateRepository->findForPuzzles(array_keys($puzzles));
        $outcomes = [];

        foreach ($message->estimates as $index => $input) {
            $puzzle = $puzzles[$input->puzzleId] ?? null;

            if ($puzzle === null) {
                $outcomes[$index] = PuzzleDifficultyEstimateOutcome::unknownPuzzle();

                continue;
            }

            // isImageHiddenAt() covers a hidden puzzle too
            if ($puzzle->isImageHiddenAt($now)) {
                $outcomes[$index] = PuzzleDifficultyEstimateOutcome::hidden();

                continue;
            }

            $currentFingerprint = $puzzle->image !== null ? PuzzleImageFingerprint::of($puzzle->image) : null;

            if ($currentFingerprint !== $input->imageFingerprint) {
                $outcomes[$index] = PuzzleDifficultyEstimateOutcome::staleFingerprint($currentFingerprint);

                continue;
            }

            $estimate = $stored[$input->puzzleId] ?? null;

            if ($estimate !== null) {
                $estimate->replace(
                    model: $input->model,
                    modelVersion: $input->modelVersion,
                    logDifficulty: $input->logDifficulty,
                    logP10: $input->logP10,
                    logP90: $input->logP90,
                    outOfFold: $input->outOfFold,
                    trainingSize: $input->trainingSize,
                    imageFingerprint: $input->imageFingerprint,
                    computedAt: $input->computedAt ?? $now,
                    receivedAt: $now,
                );
            } else {
                $estimate = new PuzzleDifficultyEstimate(
                    puzzle: $puzzle,
                    model: $input->model,
                    modelVersion: $input->modelVersion,
                    logDifficulty: $input->logDifficulty,
                    logP10: $input->logP10,
                    logP90: $input->logP90,
                    outOfFold: $input->outOfFold,
                    trainingSize: $input->trainingSize,
                    imageFingerprint: $input->imageFingerprint,
                    computedAt: $input->computedAt ?? $now,
                    receivedAt: $now,
                );
                $this->estimateRepository->save($estimate);
                $stored[$input->puzzleId] = $estimate;
            }

            $outcomes[$index] = PuzzleDifficultyEstimateOutcome::stored();
        }

        return $outcomes;
    }
}
