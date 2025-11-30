<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

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
        $params = [];
        if ($puzzleId !== null) {
            $params['puzzleId'] = $puzzleId;
        }

        return $this->redirectToRoute('puzzle_add', $params, Response::HTTP_MOVED_PERMANENTLY);
    }
}
