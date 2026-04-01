<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Services\GeneratePuzzleQrCode;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PuzzleQrCodeImageController extends AbstractController
{
    public function __construct(
        private readonly GeneratePuzzleQrCode $generatePuzzleQrCode,
    ) {
    }

    #[Route('/puzzle/{puzzleId}/qr-code.png', name: 'puzzle_qr_code_image')]
    public function __invoke(string $puzzleId): Response
    {
        $imageContent = $this->generatePuzzleQrCode->generate($puzzleId);

        return new Response($imageContent, Response::HTTP_OK, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
