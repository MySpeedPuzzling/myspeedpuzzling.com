<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Collections;

use SpeedPuzzling\Web\Message\RemovePuzzleFromCollection;
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
