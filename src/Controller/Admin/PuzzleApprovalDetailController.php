<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Admin;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Query\GetManufacturers;
use SpeedPuzzling\Web\Query\GetPuzzleApprovals;
use SpeedPuzzling\Web\Security\PuzzleModerationVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class PuzzleApprovalDetailController extends AbstractController
{
    public function __construct(
        private readonly GetPuzzleApprovals $getPuzzleApprovals,
        private readonly GetManufacturers $getManufacturers,
    ) {
    }

    #[Route(
        path: '/admin/puzzle-approvals/{puzzleId}',
        name: 'admin_puzzle_approval_detail',
    )]
    #[IsGranted(PuzzleModerationVoter::PUZZLE_MODERATION_ACCESS)]
    public function __invoke(string $puzzleId): Response
    {
        if (!Uuid::isValid($puzzleId)) {
            throw new PuzzleNotFound();
        }

        $puzzle = $this->getPuzzleApprovals->byPuzzleId($puzzleId) ?? throw new PuzzleNotFound();

        $brandSuggestions = [];

        if ($puzzle->manufacturerId !== null && $puzzle->manufacturerApproved === false) {
            $brandSuggestions = $this->getPuzzleApprovals->brandSuggestions($puzzle->manufacturerId, $puzzle->ean);
        }

        return $this->render('admin/puzzle_approval_detail.html.twig', [
            'puzzle' => $puzzle,
            'duplicates' => $this->getPuzzleApprovals->possibleDuplicates($puzzleId),
            'brand_suggestions' => $brandSuggestions,
            'approved_brands' => $this->getManufacturers->onlyApprovedOrAddedByPlayer(),
        ]);
    }
}
