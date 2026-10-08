<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\PuzzleSelection;

use SpeedPuzzling\Web\Entity\Collection;
use SpeedPuzzling\Web\FormData\CollectionPuzzleActionFormData;
use SpeedPuzzling\Web\FormType\CollectionPuzzleActionFormType;
use SpeedPuzzling\Web\Message\AddPuzzlesToCollection;
use SpeedPuzzling\Web\Query\GetPlayerCollections;
use SpeedPuzzling\Web\Query\GetUserPuzzleStatuses;
use SpeedPuzzling\Web\Services\MultiscanEligibility;
use SpeedPuzzling\Web\Services\PuzzleListSelectionResponder;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Services\SecretPuzzleAccess;
use SpeedPuzzling\Web\Services\SelectedPuzzlesCollectionTarget;
use SpeedPuzzling\Web\Value\MultiscanAction;
use SpeedPuzzling\Web\Value\PuzzleList;
use SpeedPuzzling\Web\Value\PuzzleSelection;
use SpeedPuzzling\Web\Value\SelectedPuzzlesOutcome;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Add to collection…" for the puzzles selected on the wishlist or the unsolved page
 * (docs/features/collections/bulk-actions.md "Other lists"): the collection picker of a collection page's Move, then
 * one batch add. Puzzles the collection holds already and secret ones are left out and counted. A puzzle added from
 * the wishlist leaves it when its wishlist item says so, as when added one by one - the wishlist page is loaded again.
 */
final class AddSelectedListPuzzlesToCollectionController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetPlayerCollections $getPlayerCollections,
        readonly private GetUserPuzzleStatuses $getUserPuzzleStatuses,
        readonly private MultiscanEligibility $eligibility,
        readonly private SecretPuzzleAccess $secretPuzzleAccess,
        readonly private SelectedPuzzlesCollectionTarget $collectionTarget,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
        readonly private PuzzleListSelectionResponder $responder,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/moje-seznamy/{list}/vybrane/do-kolekce',
            'en' => '/en/my-lists/{list}/selected/add-to-collection',
            'es' => '/es/mis-listas/{list}/seleccionados/anadir-a-coleccion',
            'ja' => '/ja/my-lists/{list}/selected/add-to-collection',
            'fr' => '/fr/mes-listes/{list}/selection/ajouter-a-collection',
            'de' => '/de/meine-listen/{list}/auswahl/zur-sammlung',
        ],
        name: 'puzzle_list_selected_add_to_collection',
        requirements: ['list' => 'wishlist|unsolved'],
        methods: ['POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request, PuzzleList $list): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();
        assert($player !== null);

        if ($player->activeMembership === false) {
            throw $this->createAccessDeniedException();
        }

        $selection = PuzzleSelection::fromRequest($request);

        $choices = [$this->translator->trans('collections.system_name') => Collection::SYSTEM_ID];
        foreach ($this->getPlayerCollections->byPlayerId($player->playerId, true) as $collection) {
            $choices[$collection->name] = $collection->collectionId;
        }

        $form = $this->createForm(CollectionPuzzleActionFormType::class, new CollectionPuzzleActionFormData(), [
            'collections' => $choices,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && $selection->puzzleIds !== []) {
            if ($this->isCsrfTokenValid(PuzzleSelection::CSRF_TOKEN_ID, $request->request->getString('_token')) === false) {
                throw $this->createAccessDeniedException();
            }

            /** @var CollectionPuzzleActionFormData $formData */
            $formData = $form->getData();
            [$targetCollectionId, $targetName] = $this->collectionTarget->resolve($formData, $player->playerId, $choices);

            // The batch add refuses a whole batch holding a puzzle it may not add: those are left out up front
            $secret = $this->secretPuzzleAccess->pendingRevealAmong($selection->puzzleIds);
            $report = $this->eligibility->check(
                MultiscanAction::AddToLibrary,
                array_values(array_filter($selection->puzzleIds, static fn (string $id): bool => !isset($secret[$id]))),
                $this->getUserPuzzleStatuses->byPlayerId($player->playerId),
                $targetCollectionId,
            );

            if ($report->eligible !== []) {
                $this->messageBus->dispatch(new AddPuzzlesToCollection(
                    playerId: $player->playerId,
                    puzzleIds: $report->eligible,
                    collectionId: $targetCollectionId,
                ));
            }

            return $this->responder->respond(
                request: $request,
                list: $list,
                playerId: $player->playerId,
                outcome: new SelectedPuzzlesOutcome(
                    changed: count($report->eligible),
                    alreadyThere: count($report->skipped),
                    skipped: count($secret),
                ),
                toast: $this->translator->trans('puzzle_selection.done.added_to_collection', [
                    '%count%' => count($report->eligible),
                    '%collection%' => $targetName,
                ]),
                refreshPage: $list === PuzzleList::Wishlist && $report->eligible !== [],
                openUrl: $targetCollectionId === null
                    ? $this->generateUrl('system_collection_detail', ['playerId' => $player->playerId])
                    : $this->generateUrl('collection_detail', ['collectionId' => $targetCollectionId]),
            );
        }

        return $this->render('puzzle_selection/add_to_collection_modal.html.twig', [
            'form' => $form,
            'list' => $list,
            'selection' => $selection,
            'system_collection_id' => Collection::SYSTEM_ID,
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}
