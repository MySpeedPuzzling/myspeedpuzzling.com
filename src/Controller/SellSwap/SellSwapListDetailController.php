<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\SellSwap;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Query\GetSellSwapListItems;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Contracts\Translation\TranslatorInterface;

final class SellSwapListDetailController extends AbstractController
{
    public function __construct(
        readonly private GetPlayerProfile $getPlayerProfile,
        readonly private GetSellSwapListItems $getSellSwapListItems,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private TranslatorInterface $translator,
    ) {
    }

    /**
     * @throws PlayerNotFound
     */
    #[Route(
        path: [
            'cs' => '/prodej-vymena/{playerId}',
            'en' => '/en/sell-swap-list/{playerId}',
            'es' => '/es/lista-venta-intercambio/{playerId}',
            'ja' => '/ja/売買リスト/{playerId}',
            'fr' => '/fr/liste-vente-echange/{playerId}',
            'de' => '/de/verkaufs-tausch-liste/{playerId}',
        ],
        name: 'sell_swap_list_detail',
    )]
    public function __invoke(string $playerId, #[CurrentUser] null|UserInterface $user): Response
    {
        try {
            $player = $this->getPlayerProfile->byId($playerId);
        } catch (PlayerNotFound) {
            $this->addFlash('primary', $this->translator->trans('flashes.player_not_found'));
            return $this->redirectToRoute('ladder');
        }

        $loggedPlayerProfile = $this->retrieveLoggedUserProfile->getProfile();
        $items = $this->getSellSwapListItems->byPlayerId($player->playerId);
        $isOwnProfile = $playerId === $loggedPlayerProfile?->playerId;

        return $this->render('sell-swap/detail.html.twig', [
            'items' => $items,
            'player' => $player,
            'isOwnProfile' => $isOwnProfile,
            'settings' => $player->sellSwapListSettings,
        ]);
    }
}
