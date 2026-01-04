<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Admin;

use Auth0\Symfony\Models\User;
use SpeedPuzzling\Web\Exceptions\PuzzleMergeRequestNotFound;
use SpeedPuzzling\Web\Query\GetPuzzleMergeRequests;
use SpeedPuzzling\Web\Query\GetPuzzleOverview;
use SpeedPuzzling\Web\Query\GetManufacturers;
use SpeedPuzzling\Web\Results\PuzzleOverview;
use SpeedPuzzling\Web\Security\AdminAccessVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class PuzzleMergeRequestDetailController extends AbstractController
{
    public function __construct(
        private readonly GetPuzzleMergeRequests $getPuzzleMergeRequests,
        private readonly GetPuzzleOverview $getPuzzleOverview,
        private readonly GetManufacturers $getManufacturers,
    ) {
    }

    #[Route(
        path: '/admin/puzzle-merge-requests/{id}',
        name: 'admin_puzzle_merge_request_detail',
    )]
    #[IsGranted(AdminAccessVoter::ADMIN_ACCESS)]
    public function __invoke(
        #[CurrentUser] User $user,
        string $id,
    ): Response {
        $request = $this->getPuzzleMergeRequests->byId($id);

        if ($request === null) {
            throw new PuzzleMergeRequestNotFound();
        }

        // Fetch all duplicate puzzle details
        $puzzles = [];
        foreach ($request->reportedDuplicatePuzzleIds as $puzzleId) {
            try {
                $puzzle = $this->getPuzzleOverview->byId($puzzleId);
                $puzzles[] = $puzzle;
            } catch (\Throwable) {
                // Puzzle might have been deleted, skip it
            }
        }

        // Collect merged values for the form
        $mergedData = $this->collectMergedData($puzzles);

        return $this->render('admin/puzzle_merge_request_detail.html.twig', [
            'request' => $request,
            'puzzles' => $puzzles,
            'merged_data' => $mergedData,
            'manufacturers' => $this->getManufacturers->onlyApprovedOrAddedByPlayer(),
        ]);
    }

    /**
     * @param array<PuzzleOverview> $puzzles
     * @return array<string, mixed>
     */
    private function collectMergedData(array $puzzles): array
    {
        $eans = [];
        $identificationNumbers = [];
        $pieceCounts = [];
        $images = [];
        $manufacturers = [];

        // First pass: find the survivor puzzle (the one with most solving times)
        $survivorPuzzle = null;
        $maxSolvedTimes = -1;

        foreach ($puzzles as $puzzle) {
            if ($puzzle->solvedTimes > $maxSolvedTimes) {
                $maxSolvedTimes = $puzzle->solvedTimes;
                $survivorPuzzle = $puzzle;
            }
        }

        // Second pass: collect all values for merging
        foreach ($puzzles as $puzzle) {
            if ($puzzle->puzzleEan !== null && $puzzle->puzzleEan !== '') {
                $eans[] = $puzzle->puzzleEan;
            }
            if ($puzzle->puzzleIdentificationNumber !== null && $puzzle->puzzleIdentificationNumber !== '') {
                $identificationNumbers[] = $puzzle->puzzleIdentificationNumber;
            }
            $pieceCounts[$puzzle->piecesCount] = $puzzle->piecesCount;
            if ($puzzle->puzzleImage !== null) {
                $images[$puzzle->puzzleId] = $puzzle->puzzleImage;
            }
            $manufacturers[$puzzle->manufacturerId] = $puzzle->manufacturerName;
        }

        return [
            'name' => $survivorPuzzle !== null ? $survivorPuzzle->puzzleName : '',
            'ean' => implode(', ', array_unique($eans)),
            'identification_number' => implode(', ', array_unique($identificationNumbers)),
            'pieces_counts' => array_values($pieceCounts),
            'pieces_count' => $survivorPuzzle?->piecesCount,
            'images' => $images,
            'manufacturers' => $manufacturers,
            'manufacturer_id' => $survivorPuzzle?->manufacturerId,
            'survivor_puzzle_id' => $survivorPuzzle?->puzzleId,
        ];
    }
}
