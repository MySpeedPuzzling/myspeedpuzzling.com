<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Entity\PuzzleImageAnalysis;
use SpeedPuzzling\Web\Exceptions\PuzzleImageChangedMeanwhile;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Message\RecordPuzzleImageAnalyses;
use SpeedPuzzling\Web\Repository\PuzzleImageAnalysisClaimRepository;
use SpeedPuzzling\Web\Repository\PuzzleImageAnalysisRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Services\PuzzleImageAnalysisRecorder;
use SpeedPuzzling\Web\Value\PuzzleImageAnalysisOutcome;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Every item through PuzzleImageAnalysisRecorder, in one transaction: the puzzles, their stored analyses and their
 * claims (storing deletes the claim) are read in three statements; a refused item is only its outcome - the recorder
 * checks before it changes anything, so a refusal leaves nothing half done. The same puzzle and analyzer twice in one
 * batch: the later item replaces the earlier one.
 */
#[AsMessageHandler]
readonly final class RecordPuzzleImageAnalysesHandler
{
    public function __construct(
        private PuzzleRepository $puzzleRepository,
        private PuzzleImageAnalysisRepository $puzzleImageAnalysisRepository,
        private PuzzleImageAnalysisClaimRepository $puzzleImageAnalysisClaimRepository,
        private PuzzleImageAnalysisRecorder $puzzleImageAnalysisRecorder,
    ) {
    }

    /**
     * @return array<int, PuzzleImageAnalysisOutcome> keyed like the message's analyses
     */
    public function __invoke(RecordPuzzleImageAnalyses $message): array
    {
        $puzzleIds = array_values(array_unique(array_map(
            static fn ($input): string => $input->puzzleId,
            $message->analyses,
        )));
        $puzzles = $this->puzzleRepository->findByIds($puzzleIds);

        /** @var array<string, PuzzleImageAnalysis> $stored */
        $stored = [];

        foreach ($this->puzzleImageAnalysisRepository->findForPuzzles(array_keys($puzzles)) as $analysis) {
            $stored[self::key(strtolower($analysis->puzzle->id->toString()), $analysis->analyzer->value)] = $analysis;
        }

        $claims = $this->puzzleImageAnalysisClaimRepository->findForPuzzles(array_keys($puzzles));
        $outcomes = [];

        foreach ($message->analyses as $index => $input) {
            $key = self::key($input->puzzleId, $input->analyzer->value);

            try {
                $claimKey = PuzzleImageAnalysisClaimRepository::key($input->puzzleId, $input->analyzer);
                $stored[$key] = $this->puzzleImageAnalysisRecorder->record($input, $puzzles[$input->puzzleId] ?? null, $stored[$key] ?? null, $claims[$claimKey] ?? null);
                unset($claims[$claimKey]);
                $outcomes[$index] = PuzzleImageAnalysisOutcome::stored();
            } catch (PuzzleNotFound $exception) {
                $outcomes[$index] = PuzzleImageAnalysisOutcome::notFound($exception->getMessage());
            } catch (PuzzleImageChangedMeanwhile $exception) {
                $outcomes[$index] = PuzzleImageAnalysisOutcome::staleFingerprint($exception->getMessage(), $exception->currentFingerprint);
            }
        }

        return $outcomes;
    }

    private static function key(string $puzzleId, string $analyzer): string
    {
        return $puzzleId . '|' . $analyzer;
    }
}
