<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Entity\Collection;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Query\GetCollectionItems;
use SpeedPuzzling\Web\Query\GetLentPuzzleIds;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Query\GetUserPuzzleStatuses;
use SpeedPuzzling\Web\Results\CollectionOverview;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\CollectionVisibility;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Contracts\Translation\TranslatorInterface;

final class SystemCollectionDetailController extends AbstractController
{
    public function __construct(
        readonly private GetPlayerProfile $getPlayerProfile,
        readonly private GetCollectionItems $getCollectionItems,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private TranslatorInterface $translator,
        readonly private GetLentPuzzleIds $getLentPuzzleIds,
        readonly private GetUserPuzzleStatuses $getUserPuzzleStatuses,
    ) {
    }

    /**
     * @throws PlayerNotFound
     */
    #[Route(
        path: [
            'cs' => '/puzzle-kolekce/{playerId}',
            'en' => '/en/puzzle-collection/{playerId}',
            'es' => '/es/coleccion-puzzle/{playerId}',
            'ja' => '/ja/パズルコレクション/{playerId}',
            'fr' => '/fr/collection-puzzle/{playerId}',
            'de' => '/de/puzzle-sammlung/{playerId}',
        ],
        name: 'system_collection_detail',
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
        $visibility = $player->puzzleCollectionVisibility;
        $collectionName = $this->translator->trans('collections.system_name');

        // Check visibility permissions
        if ($visibility === CollectionVisibility::Private && $playerId !== $loggedPlayerProfile?->playerId) {
            return $this->render('collections/private.html.twig', [
                'player' => $player,
                'collectionName' => $collectionName,
            ]);
        }

        $items = $this->getCollectionItems->byCollectionAndPlayer(null, $player->playerId);

        // Get lent puzzles to show overlay - only for own profile
        $lentPuzzles = [];
        if ($loggedPlayerProfile?->playerId === $playerId) {
            $lentPuzzles = $this->getLentPuzzleIds->byOwnerId($player->playerId);
        }

        $collectionOverview = new CollectionOverview(
            playerId: $playerId,
            collectionId: null,
            name: $collectionName,
            description: null,
            visibility: $visibility,
        );

        return $this->render('collections/detail.html.twig', [
            'collection' => $collectionOverview,
            'items' => $items,
            'player' => $player,
            'lentPuzzles' => $lentPuzzles,
            'puzzle_statuses' => $this->getUserPuzzleStatuses->byPlayerId($playerId),
            'system_collection_id' => Collection::SYSTEM_ID,
        ]);
    }
}
