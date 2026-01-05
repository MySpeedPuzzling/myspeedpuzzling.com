<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\PuzzleReport;

use SpeedPuzzling\Web\Query\GetPendingPuzzleProposals;
use SpeedPuzzling\Web\Query\GetPuzzleOverview;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PendingProposalsModalController extends AbstractController
{
    public function __construct(
        readonly private GetPuzzleOverview $getPuzzleOverview,
        readonly private GetPendingPuzzleProposals $getPendingPuzzleProposals,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/puzzle/{puzzleId}/cekajici-navrhy',
            'en' => '/en/puzzle/{puzzleId}/pending-proposals',
            'es' => '/es/puzzle/{puzzleId}/propuestas-pendientes',
            'ja' => '/ja/puzzle/{puzzleId}/pending-proposals',
            'fr' => '/fr/puzzle/{puzzleId}/propositions-en-attente',
            'de' => '/de/puzzle/{puzzleId}/ausstehende-vorschlaege',
        ],
        name: 'puzzle_pending_proposals',
        methods: ['GET'],
    )]
    public function __invoke(
        Request $request,
        string $puzzleId,
    ): Response {
        $puzzle = $this->getPuzzleOverview->byId($puzzleId);
        $proposals = $this->getPendingPuzzleProposals->forPuzzle($puzzleId);

        $templateParams = [
            'puzzle' => $puzzle,
            'proposals' => $proposals,
        ];

        // Turbo Frame request - return frame content only
        if ($request->headers->get('Turbo-Frame') === 'modal-frame') {
            return $this->render('puzzle-report/pending_proposals_modal.html.twig', $templateParams);
        }

        // Non-Turbo request: return full page for progressive enhancement
        return $this->render('puzzle-report/pending_proposals.html.twig', $templateParams);
    }
}
