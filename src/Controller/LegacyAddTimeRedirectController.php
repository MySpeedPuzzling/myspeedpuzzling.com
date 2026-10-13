<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Value\PuzzleAddMode;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class LegacyAddTimeRedirectController extends AbstractController
{
    #[Route(
        path: [
            'cs' => '/pridat-cas/{puzzleId}',
            'en' => '/en/add-time/{puzzleId}',
            'es' => '/es/anadir-tiempo/{puzzleId}',
            'ja' => '/ja/時間追加/{puzzleId}',
            'fr' => '/fr/ajouter-temps/{puzzleId}',
            'de' => '/de/zeit-hinzufuegen/{puzzleId}',
        ],
        name: 'legacy_add_time',
        defaults: ['puzzleId' => null],
    )]
    public function __invoke(null|string $puzzleId = null): Response
    {
        // The old "Add time" URLs (bookmarks, old links) promise a time: Speed puzzling, never the player's last mode
        // (docs/features/navigation ADD-01, plan decision 42)
        $params = ['mode' => PuzzleAddMode::SpeedPuzzling->value];
        if ($puzzleId !== null) {
            $params['puzzleId'] = $puzzleId;
        }

        return $this->redirectToRoute('puzzle_add', $params, Response::HTTP_MOVED_PERMANENTLY);
    }
}
