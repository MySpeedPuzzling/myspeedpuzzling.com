<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Services\SecretPuzzleAccess;
use SpeedPuzzling\Web\Query\GetPuzzleOverview;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PuzzleQrCodeModalController extends AbstractController
{
    public function __construct(
        private readonly GetPuzzleOverview $getPuzzleOverview,
        private readonly SecretPuzzleAccess $secretPuzzleAccess,
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
        // A puzzle a competition keeps secret answers 404 to everybody but its organisers (SecretPuzzleAccess)
        $this->secretPuzzleAccess->assertVisible($puzzleId);

        $puzzle = $this->getPuzzleOverview->byId($puzzleId);

        $qrImageUrl = $this->generateUrl('puzzle_qr_code_image', ['puzzleId' => $puzzleId]);

        if ($request->headers->get('Turbo-Frame') === 'modal-frame') {
            $response = $this->render('puzzle/qr_code_modal.html.twig', [
                'puzzle' => $puzzle,
                'qr_image_url' => $qrImageUrl,
            ]);

            // A fragment of the puzzle page, never a page of its own (robots.txt disallows the path too)
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

            return $response;
        }

        // Only the puzzle dropdown asks for the modal (inside the modal frame). Anything else - a crawler
        // following the link, a link opened in a new tab - belongs on the puzzle page itself. Permanent,
        // so crawlers stop coming back: this URL used to be ~7% of all Googlebot requests, as 302s.
        return $this->redirectToRoute('puzzle_detail', ['puzzleId' => $puzzleId], Response::HTTP_MOVED_PERMANENTLY);
    }
}
