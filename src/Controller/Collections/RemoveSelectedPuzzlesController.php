<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Collections;

use SpeedPuzzling\Web\Exceptions\CollectionNotFound;
use SpeedPuzzling\Web\Message\RemovePuzzlesFromCollection;
use SpeedPuzzling\Web\Query\GetPlayerCollections;
use SpeedPuzzling\Web\Services\CollectionSelectionResponder;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\CollectionSelection;
use SpeedPuzzling\Web\Value\SelectedPuzzlesOutcome;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Remove" for the puzzles selected on a collection page (docs/features/collections/bulk-actions.md): the selection
 * bar posts the ids into the modal frame, which asks to confirm; the confirmation removes them in one message.
 */
final class RemoveSelectedPuzzlesController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetPlayerCollections $getPlayerCollections,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
        readonly private CollectionSelectionResponder $responder,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/kolekce/{collectionId}/vybrane/odebrat',
            'en' => '/en/collections/{collectionId}/selected/remove',
            'es' => '/es/colecciones/{collectionId}/seleccionados/quitar',
            'ja' => '/ja/collections/{collectionId}/selected/remove',
            'fr' => '/fr/collections/{collectionId}/selection/retirer',
            'de' => '/de/sammlungen/{collectionId}/auswahl/entfernen',
        ],
        name: 'collection_selected_remove',
        methods: ['POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request, string $collectionId): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();
        assert($player !== null);

        if ($player->activeMembership === false) {
            throw $this->createAccessDeniedException();
        }

        $selection = CollectionSelection::fromRequest(
            $request,
            $collectionId,
            $this->getPlayerCollections->byPlayerId($player->playerId, true),
            $this->translator->trans('collections.system_name'),
        );

        if ($selection === null) {
            throw new CollectionNotFound();
        }

        if ($request->request->getBoolean('confirm') && $selection->puzzleIds !== []) {
            if ($this->isCsrfTokenValid(CollectionSelection::CSRF_TOKEN_ID, $request->request->getString('_token')) === false) {
                throw $this->createAccessDeniedException();
            }

            $outcome = $this->messageBus
                ->dispatch(new RemovePuzzlesFromCollection($player->playerId, $selection->puzzleIds, $selection->collectionId))
                ->last(HandledStamp::class)
                ?->getResult();
            assert($outcome instanceof SelectedPuzzlesOutcome);

            return $this->responder->respond(
                request: $request,
                selection: $selection,
                playerId: $player->playerId,
                outcome: $outcome,
                toast: $this->translator->trans('collection_selection.done.removed', [
                    '%count%' => $outcome->changed,
                    '%collection%' => $selection->collectionName,
                ]),
                removeCards: true,
            );
        }

        return $this->render('collections/selected_remove_modal.html.twig', [
            'selection' => $selection,
        ]);
    }
}
