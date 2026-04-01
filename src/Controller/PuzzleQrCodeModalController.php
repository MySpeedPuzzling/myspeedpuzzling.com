<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetPuzzleOverview;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PuzzleQrCodeModalController extends AbstractController
{
    public function __construct(
        private readonly GetPuzzleOverview $getPuzzleOverview,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/puzzle/{puzzleId}/qr-kod',
            'en' => '/en/puzzle/{puzzleId}/qr-code',
            'es' => '/es/puzzle/{puzzleId}/codigo-qr',
            'ja' => '/ja/puzzle/{puzzleId}/qr-code',
            'fr' => '/fr/puzzle/{puzzleId}/code-qr',
            'de' => '/de/puzzle/{puzzleId}/qr-code',
        ],
        name: 'puzzle_qr_code_modal',
    )]
    public function __invoke(Request $request, string $puzzleId): Response
    {
        $puzzle = $this->getPuzzleOverview->byId($puzzleId);

        $qrImageUrl = $this->generateUrl('puzzle_qr_code_image', ['puzzleId' => $puzzleId]);

        if ($request->headers->get('Turbo-Frame') === 'modal-frame') {
            return $this->render('puzzle/qr_code_modal.html.twig', [
                'puzzle' => $puzzle,
                'qr_image_url' => $qrImageUrl,
            ]);
        }

        return $this->redirectToRoute('puzzle_detail', ['puzzleId' => $puzzleId]);
    }
}
