<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Auth0\Symfony\Models\User;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Query\GetLendBorrowHistory;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class LendBorrowHistoryController extends AbstractController
{
    public function __construct(
        readonly private GetPlayerProfile $getPlayerProfile,
        readonly private GetLendBorrowHistory $getLendBorrowHistory,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    /**
     * @throws PlayerNotFound
     */
    #[Route(
        path: [
            'cs' => '/historie-vypujcek/{playerId}',
            'en' => '/en/lend-borrow-history/{playerId}',
            'es' => '/es/historial-prestamos/{playerId}',
            'ja' => '/ja/貸し借り履歴/{playerId}',
            'fr' => '/fr/historique-prets/{playerId}',
            'de' => '/de/leih-verlauf/{playerId}',
        ],
        name: 'lend_borrow_history',
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
        $items = $this->getLendBorrowHistory->byPlayerId($playerId);

        return $this->render('lend-borrow/history.html.twig', [
            'items' => $items,
            'player' => $player,
        ]);
    }
}
