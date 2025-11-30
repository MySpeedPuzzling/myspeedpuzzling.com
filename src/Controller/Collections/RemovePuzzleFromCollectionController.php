<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Collections;

use SpeedPuzzling\Web\Message\RemovePuzzleFromCollection;
use SpeedPuzzling\Web\Query\GetCollectionItems;
use SpeedPuzzling\Web\Query\GetUserPuzzleStatuses;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Turbo\TurboBundle;

final class RemovePuzzleFromCollectionController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
        readonly private GetUserPuzzleStatuses $getUserPuzzleStatuses,
        readonly private GetCollectionItems $getCollectionItems,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/kolekce/{puzzleId}/odebrat',
            'en' => '/en/collections/{puzzleId}/remove',
            'es' => '/es/colecciones/{puzzleId}/eliminar',
            'ja' => '/ja/collections/{puzzleId}/remove',
            'fr' => '/fr/collections/{puzzleId}/supprimer',
            'de' => '/de/sammlungen/{puzzleId}/entfernen',
        ],
        name: 'collection_remove',
        methods: ['POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(
        Request $request,
        string $puzzleId,
    ): Response {
        $loggedPlayer = $this->retrieveLoggedUserProfile->getProfile();
        assert($loggedPlayer !== null);

        // Get collectionId from request body
        $collectionId = $request->request->get('collectionId');

        // Handle special system collection marker
        if ($collectionId === '__system_collection__' || $collectionId === '') {
            $collectionId = null;
        }

        $this->messageBus->dispatch(new RemovePuzzleFromCollection(
            playerId: $loggedPlayer->playerId,
            puzzleId: $puzzleId,
            collectionId: is_string($collectionId) ? $collectionId : null,
        ));

        // Check if this is a Turbo request
        if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
            $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

            $context = $request->request->getString('context', 'detail');
            $currentCollectionId = $request->request->getString('currentCollectionId', '');
            if ($currentCollectionId === '__system_collection__' || $currentCollectionId === '') {
                $currentCollectionId = null;
            }

            // Different response based on context
            if ($context === 'list') {
                // Called from collection detail page - remove item, update count, possibly show empty state
                $remainingCount = $this->getCollectionItems->countByCollectionAndPlayer(
                    is_string($collectionId) ? $collectionId : null,
                    $loggedPlayer->playerId,
                );

                return $this->render('collections/_remove_from_list_stream.html.twig', [
                    'puzzle_id' => $puzzleId,
                    'removed_from_collection_id' => is_string($collectionId) ? $collectionId : '__system_collection__',
                    'current_collection_id' => $currentCollectionId ?? '__system_collection__',
                    'remaining_count' => $remainingCount,
                    'message' => $this->translator->trans('collections.flash.puzzle_removed'),
                ]);
            }

            // Called from puzzle detail page - update badges and dropdown
            $puzzleStatuses = $this->getUserPuzzleStatuses->byPlayerId($loggedPlayer->playerId);

            return $this->render('collections/_stream.html.twig', [
                'puzzle_id' => $puzzleId,
                'puzzle_statuses' => $puzzleStatuses,
                'message' => $this->translator->trans('collections.flash.puzzle_removed'),
            ]);
        }

        // Non-Turbo request: redirect with flash message
        $this->addFlash('success', $this->translator->trans('collections.flash.puzzle_removed'));

        return $this->redirectToRoute('puzzle_detail', ['puzzleId' => $puzzleId]);
    }
}
