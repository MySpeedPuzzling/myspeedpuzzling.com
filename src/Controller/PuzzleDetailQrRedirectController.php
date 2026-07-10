<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Legacy URL set encoded in printed QR codes - permanently redirects
 * to the canonical puzzle detail so no duplicate content is served.
 */
final class PuzzleDetailQrRedirectController extends AbstractController
{
    #[Route(
        path: [
            'cs' => '/skladam-puzzle/{puzzleId}',
            'en' => '/solving-puzzle/{puzzleId}',
            'es' => '/es/resolviendo-puzzle/{puzzleId}',
            'ja' => '/ja/パズル解決中/{puzzleId}',
            'fr' => '/fr/resoudre-puzzle/{puzzleId}',
            'de' => '/de/puzzle-loesen/{puzzleId}',
        ],
        name: 'puzzle_detail_qr',
    )]
    public function __invoke(string $puzzleId): Response
    {
        return $this->redirectToRoute('puzzle_detail', [
            'puzzleId' => $puzzleId,
        ], Response::HTTP_MOVED_PERMANENTLY);
    }
}
