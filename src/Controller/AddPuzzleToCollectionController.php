<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Auth0\Symfony\Models\User;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\FormData\AddPuzzleToCollectionFormData;
use SpeedPuzzling\Web\FormType\AddPuzzleToCollectionFormType;
use SpeedPuzzling\Web\Message\AddPuzzleToCollection;
use SpeedPuzzling\Web\Query\GetPlayerCollections;
use SpeedPuzzling\Web\Repository\CollectionItemRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AddPuzzleToCollectionController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetPlayerCollections $getPlayerCollections,
        readonly private CollectionItemRepository $collectionItemRepository,
        readonly private PlayerRepository $playerRepository,
        readonly private PuzzleRepository $puzzleRepository,
    ) {
    }

    /**
     * @throws PlayerNotFound
     */
    #[Route(
        path: [
            'cs' => '/pridat-puzzle-do-kolekce/{puzzleId}',
            'en' => '/en/add-puzzle-to-collection/{puzzleId}',
            'es' => '/es/agregar-rompecabezas-a-coleccion/{puzzleId}',
            'ja' => '/ja/パズルをコレクションに追加/{puzzleId}',
            'fr' => '/fr/ajouter-puzzle-a-collection/{puzzleId}',
            'de' => '/de/puzzle-zu-sammlung-hinzufuegen/{puzzleId}',
        ],
        name: 'add_puzzle_to_collection',
        methods: ['GET', 'POST'],
    )]
    public function __invoke(string $puzzleId, Request $request, #[CurrentUser] User $user): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();
        if ($player === null) {
            throw new PlayerNotFound();
        }

        $collections = $this->getPlayerCollections->byPlayerId($player->playerId, true);

        // Get the player and puzzle entities to check existing collection items
        $playerEntity = $this->playerRepository->get($player->playerId);
        $puzzleEntity = $this->puzzleRepository->get($puzzleId);

        // Get all existing collection items for this player and puzzle
        $existingCollectionItems = $this->collectionItemRepository->findByPlayerAndPuzzle($playerEntity, $puzzleEntity);

        // Create a set of collection IDs that already contain this puzzle (including null for system collection)
        $existingCollectionIds = [];
        foreach ($existingCollectionItems as $item) {
            $existingCollectionIds[] = $item->collection?->id->toString();
        }

        // Create choices array for the form, excluding collections that already contain the puzzle
        $collectionChoices = [];

        // Add system collection as special option if not already containing the puzzle
        if (!in_array(null, $existingCollectionIds, true)) {
            $collectionChoices['collections.system_name'] = null;
        }

        // Add regular collections that don't already contain the puzzle
        foreach ($collections as $collection) {
            if ($collection->collectionId !== null && !in_array($collection->collectionId, $existingCollectionIds, true)) {
                $collectionChoices[$collection->name] = $collection->collectionId;
            }
        }

        $formData = new AddPuzzleToCollectionFormData();
        $form = $this->createForm(AddPuzzleToCollectionFormType::class, $formData, [
            'collections' => $collectionChoices,
        ]);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->messageBus->dispatch(new AddPuzzleToCollection(
                playerId: $player->playerId,
                puzzleId: $puzzleId,
                collectionId: $formData->collectionId,
                comment: $formData->comment,
            ));

            $this->addFlash('success', $this->translator->trans('flashes.puzzle_added_to_collection'));

            return $this->redirectToRoute('puzzle_detail', ['puzzleId' => $puzzleId]);
        }

        return $this->render('collections/add_puzzle_form.html.twig', [
            'form' => $form,
            'puzzleId' => $puzzleId,
        ]);
    }
}
