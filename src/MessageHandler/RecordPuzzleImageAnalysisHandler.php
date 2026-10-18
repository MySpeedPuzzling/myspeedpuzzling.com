<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PuzzleImageChangedMeanwhile;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Message\RecordPuzzleImageAnalysis;
use SpeedPuzzling\Web\Repository\PuzzleImageAnalysisClaimRepository;
use SpeedPuzzling\Web\Repository\PuzzleImageAnalysisRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Services\PuzzleImageAnalysisRecorder;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class RecordPuzzleImageAnalysisHandler
{
    public function __construct(
        private PuzzleRepository $puzzleRepository,
        private PuzzleImageAnalysisRepository $puzzleImageAnalysisRepository,
        private PuzzleImageAnalysisClaimRepository $puzzleImageAnalysisClaimRepository,
        private PuzzleImageAnalysisRecorder $puzzleImageAnalysisRecorder,
    ) {
    }

    /**
     * @throws PuzzleNotFound
     * @throws PuzzleImageChangedMeanwhile
     */
    public function __invoke(RecordPuzzleImageAnalysis $message): void
    {
        $input = $message->analysis;
        $puzzle = Uuid::isValid($input->puzzleId) ? $this->puzzleRepository->findById(Uuid::fromString($input->puzzleId)) : null;

        $stored = null;
        $claim = null;

        if ($puzzle !== null) {
            $stored = $this->puzzleImageAnalysisRepository->findOne($input->puzzleId, $input->analyzer);
            $claimKey = PuzzleImageAnalysisClaimRepository::key($input->puzzleId, $input->analyzer);
            $claim = $this->puzzleImageAnalysisClaimRepository->findForPuzzles([$input->puzzleId])[$claimKey] ?? null;
        }

        $this->puzzleImageAnalysisRecorder->record($input, $puzzle, $stored, $claim);
    }
}
