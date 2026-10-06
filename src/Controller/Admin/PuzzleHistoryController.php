<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Admin;

use SpeedPuzzling\Web\Services\SecretPuzzleAccess;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Query\GetPuzzleHistory;
use SpeedPuzzling\Web\Query\GetPuzzleRecord;
use SpeedPuzzling\Web\Security\PuzzleModerationVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Who changed, approved or merged what on a puzzle, and when - read only, straight from the append-only
 * decision log. Answers for a puzzle a merge deleted too, as long as its history knows it.
 */
final class PuzzleHistoryController extends AbstractController
{
    public function __construct(
        private readonly GetPuzzleRecord $getPuzzleRecord,
        private readonly GetPuzzleHistory $getPuzzleHistory,
        private readonly SecretPuzzleAccess $secretPuzzleAccess,
    ) {
    }

    #[Route(
        path: '/admin/puzzles/{puzzleId}/history',
        name: 'admin_puzzle_history',
        methods: ['GET'],
    )]
    #[IsGranted(PuzzleModerationVoter::PUZZLE_MODERATION_ACCESS)]
    public function __invoke(string $puzzleId): Response
    {
        // A puzzle a competition keeps secret answers 404 to everybody but its organisers (SecretPuzzleAccess)
        $this->secretPuzzleAccess->assertVisible($puzzleId, alsoWhileImageHidden: true);

        if (Uuid::isValid($puzzleId) === false) {
            throw new PuzzleNotFound();
        }

        $puzzle = $this->getPuzzleRecord->byId($puzzleId);
        $entries = $this->getPuzzleHistory->forPuzzle($puzzleId);

        if ($puzzle === null && $entries === []) {
            throw new PuzzleNotFound();
        }

        return $this->render('admin/puzzle_history.html.twig', [
            'puzzle_id' => $puzzleId,
            'puzzle' => $puzzle,
            // A deleted puzzle is named as its history last saw it
            'puzzle_name' => $puzzle->name ?? ($this->getPuzzleHistory->knownNames([$puzzleId])[$puzzleId] ?? null),
            'entries' => $entries,
        ]);
    }
}
