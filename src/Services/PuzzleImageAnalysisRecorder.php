<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Entity\PuzzleImageAnalysis;
use SpeedPuzzling\Web\Exceptions\PuzzleImageChangedMeanwhile;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Repository\PuzzleImageAnalysisRepository;
use SpeedPuzzling\Web\Value\PuzzleImageAnalysisInput;
use SpeedPuzzling\Web\Value\PuzzleImageFingerprint;

/**
 * The one rule for storing an image analysis (RecordPuzzleImageAnalysisHandler, RecordPuzzleImageAnalysesHandler):
 * only for a puzzle whose image is shown (a hidden one - a secret competition puzzle, a placeholder before its reveal -
 * reads as unknown), only for its current picture (PuzzleImageFingerprint), and it replaces the stored analysis of the
 * same analyzer whole. Checks everything before it changes anything.
 */
readonly final class PuzzleImageAnalysisRecorder
{
    public const string NOT_FOUND_MESSAGE = 'No such puzzle with an image to analyse.';

    public function __construct(
        private PuzzleImageAnalysisRepository $puzzleImageAnalysisRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * The stored analysis - the replaced one, or the new one.
     *
     * @throws PuzzleNotFound unknown puzzle (null) or its image hidden now
     * @throws PuzzleImageChangedMeanwhile the analysis describes another picture, or the puzzle has none
     */
    public function record(
        PuzzleImageAnalysisInput $input,
        null|Puzzle $puzzle,
        null|PuzzleImageAnalysis $stored,
    ): PuzzleImageAnalysis {
        $now = $this->clock->now();

        if ($puzzle === null || $puzzle->isImageHiddenAt($now)) {
            throw new PuzzleNotFound(self::NOT_FOUND_MESSAGE);
        }

        $currentFingerprint = $puzzle->image !== null ? PuzzleImageFingerprint::of($puzzle->image) : null;

        if ($currentFingerprint !== $input->imageFingerprint) {
            throw new PuzzleImageChangedMeanwhile($currentFingerprint);
        }

        if ($stored !== null) {
            $stored->replace(
                analyzerVersion: $input->analyzerVersion,
                imageFingerprint: $input->imageFingerprint,
                result: $input->result,
                analyzedAt: $input->analyzedAt,
                receivedAt: $now,
            );

            return $stored;
        }

        $analysis = new PuzzleImageAnalysis(
            id: Uuid::uuid7(),
            puzzle: $puzzle,
            analyzer: $input->analyzer,
            analyzerVersion: $input->analyzerVersion,
            imageFingerprint: $input->imageFingerprint,
            result: $input->result,
            analyzedAt: $input->analyzedAt,
            receivedAt: $now,
        );
        $this->puzzleImageAnalysisRepository->save($analysis);

        return $analysis;
    }
}
