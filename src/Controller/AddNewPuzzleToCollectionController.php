<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Auth0\Symfony\Models\User;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Collection;
use SpeedPuzzling\Web\Exceptions\CollectionAlreadyExists;
use SpeedPuzzling\Web\FormData\AddNewPuzzleToCollectionFormData;
use SpeedPuzzling\Web\FormType\AddNewPuzzleToCollectionFormType;
use SpeedPuzzling\Web\Message\AddPuzzle;
use SpeedPuzzling\Web\Message\AddPuzzleToCollection;
use SpeedPuzzling\Web\Message\CreateCollection;
use SpeedPuzzling\Web\Query\GetPlayerCollections;
use SpeedPuzzling\Web\Results\CollectionOverview;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AddNewPuzzleToCollectionController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetPlayerCollections $getPlayerCollections,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/pridat-puzzle-do-kolekce/{collectionId}',
            'en' => '/en/add-puzzle-to-collection/{collectionId}',
            'es' => '/es/agregar-puzzle-a-coleccion/{collectionId}',
            'ja' => '/ja/コレクションにパズルを追加/{collectionId}',
            'fr' => '/fr/ajouter-puzzle-a-collection/{collectionId}',
            'de' => '/de/puzzle-zur-sammlung-hinzufuegen/{collectionId}',
        ],
        name: 'add_new_puzzle_to_collection',
        defaults: ['collectionId' => null],
    )]
    public function __invoke(
        Request $request,
        #[CurrentUser] User $user,
        null|string $collectionId = null,
    ): Response {
        $userProfile = $this->retrieveLoggedUserProfile->getProfile();

        if ($userProfile === null) {
            return $this->redirectToRoute('login');
        }

        $hasActiveMembership = $userProfile->activeMembership;
        $data = new AddNewPuzzleToCollectionFormData();

        $collections = $this->getPlayerCollections->byPlayerId($userProfile->playerId, true);
        $collectionChoices = $this->buildCollectionChoices($collections, $userProfile->puzzleCollectionVisibility->value);

        if ($hasActiveMembership === false) {
            $data->collection = Collection::SYSTEM_ID;
        } elseif ($collectionId !== null && Uuid::isValid($collectionId)) {
            $data->collection = $collectionId;
        }

        $form = $this->createForm(AddNewPuzzleToCollectionFormType::class, $data, [
            'collections' => $collectionChoices,
            'allow_create_collection' => $hasActiveMembership,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $userId = $user->getUserIdentifier();
            $puzzleId = $data->puzzle;

            if (is_string($data->puzzle) && $data->puzzlePiecesCount !== null && Uuid::isValid($data->puzzle) === false) {
                $newPuzzleId = Uuid::uuid7();

                assert($data->brand !== null);

                $this->messageBus->dispatch(new AddPuzzle(
                    puzzleId: $newPuzzleId,
                    userId: $userId,
                    puzzleName: $data->puzzle,
                    brand: $data->brand,
                    piecesCount: $data->puzzlePiecesCount,
                    puzzlePhoto: $data->puzzlePhoto,
                    puzzleEan: $data->puzzleEan,
                    puzzleIdentificationNumber: $data->puzzleIdentificationNumber,
                ));

                $puzzleId = $newPuzzleId->toString();

                $this->addFlash('warning', $this->translator->trans('flashes.puzzle_needs_approve'));
            }

            $targetCollectionId = $data->collection;

            if ($targetCollectionId === Collection::SYSTEM_ID) {
                $targetCollectionId = null;
            }

            if ($targetCollectionId !== null && Uuid::isValid($targetCollectionId) === false) {
                $newCollectionId = Uuid::uuid7()->toString();

                try {
                    $this->messageBus->dispatch(new CreateCollection(
                        collectionId: $newCollectionId,
                        playerId: $userProfile->playerId,
                        name: $targetCollectionId,
                        description: $data->collectionDescription,
                        visibility: $data->collectionVisibility,
                    ));

                    $targetCollectionId = $newCollectionId;
                } catch (HandlerFailedException $exception) {
                    $realException = $exception->getPrevious();
                    if ($realException instanceof CollectionAlreadyExists) {
                        $targetCollectionId = $realException->collectionId;
                    } else {
                        throw $exception;
                    }
                }
            }

            assert($puzzleId !== null);

            $this->messageBus->dispatch(new AddPuzzleToCollection(
                playerId: $userProfile->playerId,
                puzzleId: $puzzleId,
                collectionId: $targetCollectionId,
                comment: $data->comment,
            ));

            $this->addFlash('success', $this->translator->trans('collections.add_puzzle.success'));

            if ($targetCollectionId === null) {
                return $this->redirectToRoute('system_collection_detail', [
                    'playerId' => $userProfile->playerId,
                ]);
            }

            return $this->redirectToRoute('collection_detail', [
                'collectionId' => $targetCollectionId,
            ]);
        }

        $cancelUrl = $collectionId !== null && Uuid::isValid($collectionId)
            ? $this->generateUrl('collection_detail', ['collectionId' => $collectionId])
            : $this->generateUrl('player_collections', ['playerId' => $userProfile->playerId]);

        return $this->render('collections/add_new_puzzle.html.twig', [
            'form' => $form,
            'player' => $userProfile,
            'has_active_membership' => $hasActiveMembership,
            'system_collection_id' => Collection::SYSTEM_ID,
            'hide_new_puzzle' => true,
            'cancel_url' => $cancelUrl,
        ]);
    }

    /**
     * @param array<CollectionOverview> $collections
     * @return array<string, null|string>
     */
    private function buildCollectionChoices(array $collections, string $systemCollectionVisibility): array
    {
        $choices = [];

        $choices[$this->translator->trans('collections.system_name')] = Collection::SYSTEM_ID;

        foreach ($collections as $collection) {
            if ($collection->collectionId !== null) {
                $choices[$collection->name] = $collection->collectionId;
            }
        }

        return $choices;
    }
}
