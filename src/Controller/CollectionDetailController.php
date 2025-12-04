<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Entity\Collection;
use SpeedPuzzling\Web\Exceptions\CollectionNotFound;
use SpeedPuzzling\Web\Query\GetCollectionItems;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Query\GetUserPuzzleStatuses;
use SpeedPuzzling\Web\Repository\CollectionRepository;
use SpeedPuzzling\Web\Results\CollectionOverview;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\CollectionVisibility;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Contracts\Translation\TranslatorInterface;

final class CollectionDetailController extends AbstractController
{
    public function __construct(
        readonly private GetCollectionItems $getCollectionItems,
        readonly private CollectionRepository $collectionRepository,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private TranslatorInterface $translator,
        readonly private GetPlayerProfile $getPlayerProfile,
        readonly private GetUserPuzzleStatuses $getUserPuzzleStatuses,
    ) {
    }

    /**
     * @throws CollectionNotFound
     */
    #[Route(
        path: [
            'cs' => '/kolekce/{collectionId}',
            'en' => '/en/collection/{collectionId}',
            'es' => '/es/coleccion/{collectionId}',
            'ja' => '/ja/コレクション/{collectionId}',
            'fr' => '/fr/collection/{collectionId}',
            'de' => '/de/sammlung/{collectionId}',
        ],
        name: 'collection_detail',
    )]
    public function __invoke(string $collectionId, #[CurrentUser] null|UserInterface $user): Response
    {
        try {
            $collection = $this->collectionRepository->get($collectionId);
        } catch (CollectionNotFound) {
            $this->addFlash('primary', $this->translator->trans('flashes.collection_not_found'));
            return $this->redirectToRoute('ladder');
        }

        $player = $this->getPlayerProfile->byId($collection->player->id->toString());
        $loggedPlayerProfile = $this->retrieveLoggedUserProfile->getProfile();

        // Check visibility permissions
        if (
            $collection->visibility === CollectionVisibility::Private
            && $loggedPlayerProfile?->playerId !== $collection->player->id->toString()
        ) {
            return $this->render('collections/private.html.twig', [
                'player' => $player,
                'collectionName' => $collection->name,
            ]);
        }

        $items = $this->getCollectionItems->byCollectionAndPlayer($collectionId, $player->playerId);

        $collectionOverview = new CollectionOverview(
            playerId: $collection->player->id->toString(),
            collectionId: $collection->id->toString(),
            name: $collection->name,
            description: $collection->description,
            visibility: $collection->visibility,
        );

        return $this->render('collections/detail.html.twig', [
            'collection' => $collectionOverview,
            'items' => $items,
            'player' => $player,
            'puzzle_statuses' => $this->getUserPuzzleStatuses->byPlayerId($player->playerId),
            'system_collection_id' => Collection::SYSTEM_ID,
        ]);
    }
}
