<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Services\GeneratePuzzleQrCode;
use SpeedPuzzling\Web\Value\RavensburgerPuzzleMonthEditions;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Print-ready QR code (same style as the per-puzzle one) that encodes the
 * human-readable /ravensburger-puzzle-month/{edition} link rather than the
 * puzzle id, so the printed box never depends on the placeholder's uuid.
 */
final class RavensburgerPuzzleMonthQrCodeImageController extends AbstractController
{
    public function __construct(
        private readonly GeneratePuzzleQrCode $generatePuzzleQrCode,
    ) {
    }

    #[Route(
        path: '/ravensburger-puzzle-month/{edition}/qr-code.png',
        name: 'ravensburger_puzzle_month_qr_code_image',
        requirements: ['edition' => '\d+'],
    )]
    public function __invoke(string $edition): Response
    {
        if (RavensburgerPuzzleMonthEditions::puzzleId((int) $edition) === null) {
            throw $this->createNotFoundException();
        }

        $url = $this->generateUrl(
            'ravensburger_puzzle_month',
            ['edition' => (int) $edition],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        return new Response($this->generatePuzzleQrCode->generateForUrl($url), Response::HTTP_OK, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
