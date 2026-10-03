<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetPlayerProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The player card on the Players page (docs/features/players-page/README.md, stream S1): fetched into a Turbo Frame
 * when a person is tapped. Hidden players 404 through GetPlayerProfile::byId().
 */
final class PlayerCardController extends AbstractController
{
    public function __construct(
        readonly private GetPlayerProfile $getPlayerProfile,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/karta-puzzlera/{playerId}',
            'en' => '/en/puzzler-card/{playerId}',
            'es' => '/es/tarjeta-jugador/{playerId}',
            'ja' => '/ja/プレイヤーカード/{playerId}',
            'fr' => '/fr/carte-joueur/{playerId}',
            'de' => '/de/puzzler-karte/{playerId}',
        ],
        name: 'player_card',
    )]
    public function __invoke(string $playerId): Response
    {
        return $this->render('players/card.html.twig', [
            'player' => $this->getPlayerProfile->byId($playerId),
        ]);
    }
}
