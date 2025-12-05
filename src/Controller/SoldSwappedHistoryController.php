<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Auth0\Symfony\Models\User;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Query\GetSoldSwappedHistory;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class SoldSwappedHistoryController extends AbstractController
{
    public function __construct(
        readonly private GetPlayerProfile $getPlayerProfile,
        readonly private GetSoldSwappedHistory $getSoldSwappedHistory,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    /**
     * @throws PlayerNotFound
     */
    #[Route(
        path: [
            'cs' => '/historie-prodeje-vymeny/{playerId}',
            'en' => '/en/sold-swapped-history/{playerId}',
            'es' => '/es/historial-ventas-intercambios/{playerId}',
            'ja' => '/ja/売買履歴/{playerId}',
            'fr' => '/fr/historique-ventes-echanges/{playerId}',
            'de' => '/de/verkaufs-tausch-verlauf/{playerId}',
        ],
        name: 'sold_swapped_history',
    )]
    public function __invoke(string $playerId, #[CurrentUser] User $user): Response
    {
        $loggedPlayer = $this->retrieveLoggedUserProfile->getProfile();

        if ($loggedPlayer === null) {
            return $this->redirectToRoute('my_profile');
        }

        // Only owner can view their history
        if ($loggedPlayer->playerId !== $playerId) {
            throw new PlayerNotFound();
        }

        $player = $this->getPlayerProfile->byId($playerId);
        $items = $this->getSoldSwappedHistory->byPlayerId($playerId);

        return $this->render('sell-swap/history.html.twig', [
            'items' => $items,
            'player' => $player,
        ]);
    }
}
