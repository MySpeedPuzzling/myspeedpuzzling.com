<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class LegacyPlayerCollectionsRedirectController extends AbstractController
{
    #[Route(
        path: [
            'cs' => '/kolekce-hrace/{playerId}',
            'en' => '/en/player-collections/{playerId}',
            'es' => '/es/colecciones-jugador/{playerId}',
            'ja' => '/ja/プレイヤーのコレクション/{playerId}',
            'fr' => '/fr/collections-joueur/{playerId}',
            'de' => '/de/spieler-sammlungen/{playerId}',
        ],
        name: 'legacy_player_collections',
    )]
    public function __invoke(string $playerId): Response
    {
        return $this->redirectToRoute('puzzle_library', ['playerId' => $playerId], Response::HTTP_MOVED_PERMANENTLY);
    }
}
