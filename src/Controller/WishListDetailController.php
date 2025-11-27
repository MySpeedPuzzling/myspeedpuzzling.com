<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Query\GetWishListItems;
use SpeedPuzzling\Web\Results\CollectionOverview;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\CollectionVisibility;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Contracts\Translation\TranslatorInterface;

final class WishListDetailController extends AbstractController
{
    public function __construct(
        readonly private GetPlayerProfile $getPlayerProfile,
        readonly private GetWishListItems $getWishListItems,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private TranslatorInterface $translator,
    ) {
    }

    /**
     * @throws PlayerNotFound
     */
    #[Route(
        path: [
            'cs' => '/wish-list/{playerId}',
            'en' => '/en/wish-list/{playerId}',
            'es' => '/es/lista-de-deseos/{playerId}',
            'ja' => '/ja/ウィッシュリスト/{playerId}',
            'fr' => '/fr/liste-de-souhaits/{playerId}',
            'de' => '/de/wunschliste/{playerId}',
        ],
        name: 'wish_list_detail',
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
        $visibility = $player->wishListVisibility;
        $collectionName = $this->translator->trans('wish_list.name');

        // Check visibility permissions
        if ($visibility === CollectionVisibility::Private && $playerId !== $loggedPlayerProfile?->playerId) {
            return $this->render('wish_list/private.html.twig', [
                'player' => $player,
                'collectionName' => $collectionName,
            ]);
        }

        $items = $this->getWishListItems->byPlayerId($player->playerId);

        $collectionOverview = new CollectionOverview(
            playerId: $playerId,
            collectionId: null,
            name: $collectionName,
            description: $this->translator->trans('wish_list.description'),
            visibility: $visibility,
        );

        return $this->render('wish_list/detail.html.twig', [
            'collection' => $collectionOverview,
            'items' => $items,
            'player' => $player,
        ]);
    }
}
