<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use DateTimeImmutable;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Query\GetBorrowedPuzzles;
use SpeedPuzzling\Web\Query\GetLentPuzzles;
use SpeedPuzzling\Web\Query\GetPlayerCollectionsWithCounts;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Query\GetSellSwapListItems;
use SpeedPuzzling\Web\Query\GetUnsolvedPuzzles;
use SpeedPuzzling\Web\Query\GetWishListItems;
use SpeedPuzzling\Web\Results\CollectionOverviewWithCount;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\CollectionVisibility;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Contracts\Translation\TranslatorInterface;

final class PlayerCollectionsController extends AbstractController
{
    public function __construct(
        readonly private GetPlayerProfile $getPlayerProfile,
        readonly private GetPlayerCollectionsWithCounts $getPlayerCollectionsWithCounts,
        readonly private GetUnsolvedPuzzles $getUnsolvedPuzzles,
        readonly private GetWishListItems $getWishListItems,
        readonly private GetSellSwapListItems $getSellSwapListItems,
        readonly private GetLentPuzzles $getLentPuzzles,
        readonly private GetBorrowedPuzzles $getBorrowedPuzzles,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private TranslatorInterface $translator,
    ) {
    }

    /**
     * @throws PlayerNotFound
     */
    #[Route(
        path: [
            'cs' => '/kolekce-hrace/{playerId}',
            'en' => '/en/player-collections/{playerId}',
            'es' => '/es/colecciones-jugador/{playerId}',
            'ja' => '/ja/プレイヤーのコレクション/{playerId}',
            'fr' => '/fr/collections-joueur/{playerId}',
            'de' => '/de/spieler-sammlungen/{playerId}',
        ],
        name: 'player_collections',
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
        $isOwnProfile = $loggedPlayerProfile !== null && $loggedPlayerProfile->playerId === $player->playerId;

        $collections = $this->getPlayerCollectionsWithCounts->byPlayerId($player->playerId, $isOwnProfile);
        $systemCollectionPuzzleCount = $this->getPlayerCollectionsWithCounts->countSystemCollection($player->playerId);
        $unsolvedPuzzlesCount = $this->getUnsolvedPuzzles->countByPlayerId($player->playerId);
        $wishListCount = $this->getWishListItems->countByPlayerId($player->playerId);
        $sellSwapListCount = $this->getSellSwapListItems->countByPlayerId($player->playerId);

        // Add system collection if public or own profile
        if ($player->puzzleCollectionVisibility === CollectionVisibility::Public || $isOwnProfile === true) {
            array_unshift($collections, new CollectionOverviewWithCount(
                collectionId: null,
                name: $this->translator->trans('collections.system_name'),
                description: null,
                visibility: $player->puzzleCollectionVisibility,
                createdAt: new DateTimeImmutable(),
                itemCount: $systemCollectionPuzzleCount,
                isSystemCollection: true,
            ));
        }

        // Add unsolved puzzles (before system collection) if public or own profile
        if ($player->unsolvedPuzzlesVisibility === CollectionVisibility::Public || $isOwnProfile === true) {
            array_unshift($collections, new CollectionOverviewWithCount(
                collectionId: null,
                name: $this->translator->trans('unsolved_puzzles.name'),
                description: $this->translator->trans('unsolved_puzzles.description'),
                visibility: $player->unsolvedPuzzlesVisibility,
                createdAt: new DateTimeImmutable(),
                itemCount: $unsolvedPuzzlesCount,
                isSystemCollection: false,
                isUnsolvedPuzzles: true,
            ));
        }

        // Add wish list FIRST (before all other collections) if public or own profile
        if ($player->wishListVisibility === CollectionVisibility::Public || $isOwnProfile === true) {
            array_unshift($collections, new CollectionOverviewWithCount(
                collectionId: null,
                name: $this->translator->trans('wish_list.name'),
                description: $this->translator->trans('wish_list.description'),
                visibility: $player->wishListVisibility,
                createdAt: new DateTimeImmutable(),
                itemCount: $wishListCount,
                isSystemCollection: false,
                isUnsolvedPuzzles: false,
                isWishList: true,
            ));
        }

        // Add sell/swap list (always public, visible to everyone)
        array_unshift($collections, new CollectionOverviewWithCount(
            collectionId: null,
            name: $this->translator->trans('sell_swap_list.name'),
            description: $this->translator->trans('sell_swap_list.description'),
            visibility: CollectionVisibility::Public,
            createdAt: new DateTimeImmutable(),
            itemCount: $sellSwapListCount,
            isSystemCollection: false,
            isUnsolvedPuzzles: false,
            isWishList: false,
            isSellSwapList: true,
        ));

        // Add lend/borrow list
        $lentCount = $this->getLentPuzzles->countByOwnerId($player->playerId);
        $borrowedCount = $this->getBorrowedPuzzles->countByHolderId($player->playerId);
        $totalLendBorrowCount = $lentCount + $borrowedCount;

        // Show lend/borrow list if:
        // - It's the player's own profile (always show, with members badge if no membership)
        // - OR it's public and visibility allows it
        $showLendBorrowList = match (true) {
            $isOwnProfile => true,
            $player->lendBorrowListVisibility === CollectionVisibility::Public => true,
            default => false,
        };

        if ($showLendBorrowList) {
            array_unshift($collections, new CollectionOverviewWithCount(
                collectionId: null,
                name: $this->translator->trans('lend_borrow.name'),
                description: $this->translator->trans('lend_borrow.description'),
                visibility: $player->lendBorrowListVisibility,
                createdAt: new DateTimeImmutable(),
                itemCount: $totalLendBorrowCount,
                isSystemCollection: false,
                isUnsolvedPuzzles: false,
                isWishList: false,
                isSellSwapList: false,
                isLendBorrowList: true,
                lentCount: $lentCount,
                borrowedCount: $borrowedCount,
            ));
        }

        return $this->render('collections/list.html.twig', [
            'collections' => $collections,
            'player' => $player,
            'isOwnProfile' => $isOwnProfile,
        ]);
    }
}
