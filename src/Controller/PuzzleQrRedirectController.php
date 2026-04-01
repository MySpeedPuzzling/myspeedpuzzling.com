<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PuzzleQrRedirectController extends AbstractController
{
    private const array SUPPORTED_LOCALES = ['cs', 'en', 'es', 'ja', 'fr', 'de'];

    #[Route('/p/{puzzleId}', name: 'puzzle_qr_redirect')]
    public function __invoke(Request $request, string $puzzleId): Response
    {
        $preferredLocale = $request->getPreferredLanguage(self::SUPPORTED_LOCALES) ?? 'en';

        return $this->redirectToRoute('puzzle_detail', [
            'puzzleId' => $puzzleId,
            '_locale' => $preferredLocale,
            'utm_source' => 'qr_code',
        ]);
    }
}
