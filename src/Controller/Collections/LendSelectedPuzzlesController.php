<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Collections;

use SpeedPuzzling\Web\Exceptions\CannotLendToSelf;
use SpeedPuzzling\Web\Exceptions\CollectionNotFound;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\FormData\LendPuzzleFormData;
use SpeedPuzzling\Web\FormType\LendPuzzleFormType;
use SpeedPuzzling\Web\Message\LendPuzzlesToPlayer;
use SpeedPuzzling\Web\Query\GetFavoritePlayers;
use SpeedPuzzling\Web\Query\GetPlayerCollections;
use SpeedPuzzling\Web\Query\GetUserPuzzleStatuses;
use SpeedPuzzling\Web\Services\CollectionSelectionResponder;
use SpeedPuzzling\Web\Services\LendBorrowParticipantParser;
use SpeedPuzzling\Web\Services\MultiscanEligibility;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\CollectionSelection;
use SpeedPuzzling\Web\Value\MultiscanAction;
use SpeedPuzzling\Web\Value\SelectedPuzzlesOutcome;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Lend to…" for the puzzles selected on a collection page (docs/features/collections/bulk-actions.md): one person
 * for all of them, through the multiscan's batch lend. Puzzles already lent out are left out and counted.
 */
final class LendSelectedPuzzlesController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetPlayerCollections $getPlayerCollections,
        readonly private GetUserPuzzleStatuses $getUserPuzzleStatuses,
        readonly private GetFavoritePlayers $getFavoritePlayers,
        readonly private MultiscanEligibility $eligibility,
        readonly private LendBorrowParticipantParser $participantParser,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
        readonly private CollectionSelectionResponder $responder,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/kolekce/{collectionId}/vybrane/pujcit',
            'en' => '/en/collections/{collectionId}/selected/lend',
            'es' => '/es/colecciones/{collectionId}/seleccionados/prestar',
            'ja' => '/ja/collections/{collectionId}/selected/lend',
            'fr' => '/fr/collections/{collectionId}/selection/preter',
            'de' => '/de/sammlungen/{collectionId}/auswahl/verleihen',
        ],
        name: 'collection_selected_lend',
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

        $report = $this->eligibility->check(
            MultiscanAction::Lend,
            $selection->puzzleIds,
            $this->getUserPuzzleStatuses->byPlayerId($player->playerId),
        );

        $form = $this->createForm(LendPuzzleFormType::class, new LendPuzzleFormData());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && $report->eligible !== []) {
            if ($this->isCsrfTokenValid(CollectionSelection::CSRF_TOKEN_ID, $request->request->getString('_token')) === false) {
                throw $this->createAccessDeniedException();
            }

            /** @var LendPuzzleFormData $formData */
            $formData = $form->getData();

            try {
                $borrower = $this->participantParser->parse((string) $formData->borrowerCode, $player->playerId);
            } catch (CannotLendToSelf) {
                $form->get('borrowerCode')->addError(new FormError($this->translator->trans('lend_borrow.flash.cannot_lend_to_self')));
                $borrower = null;
            } catch (PlayerNotFound) {
                $form->get('borrowerCode')->addError(new FormError($this->translator->trans('lend_borrow.flash.player_not_found')));
                $borrower = null;
            }

            if ($borrower !== null) {
                $this->messageBus->dispatch(new LendPuzzlesToPlayer(
                    ownerPlayerId: $player->playerId,
                    puzzleIds: $report->eligible,
                    borrowerPlayerId: $borrower->playerId,
                    borrowerName: $borrower->playerName,
                    notes: $formData->notes,
                ));

                $outcome = new SelectedPuzzlesOutcome(changed: count($report->eligible));
                $toast = $this->translator->trans('collection_selection.done.lent', [
                    '%count%' => $outcome->changed,
                    '%name%' => $borrower->displayName ?? $borrower->playerName ?? (string) $formData->borrowerCode,
                ]);

                if ($report->skipped !== []) {
                    $toast .= ' ' . $this->translator->trans('collection_selection.done.already_lent', ['%count%' => count($report->skipped)]);
                }

                // The lent badge sits on every card: the page is loaded again rather than patched card by card
                return $this->responder->respond(
                    request: $request,
                    selection: $selection,
                    playerId: $player->playerId,
                    outcome: $outcome,
                    toast: $toast,
                    removeCards: false,
                    refreshPage: true,
                );
            }
        }

        return $this->render('collections/selected_lend_modal.html.twig', [
            'form' => $form,
            'selection' => $selection,
            'lendable_count' => count($report->eligible),
            'already_lent_count' => count($report->skipped),
            'favorite_players' => $this->getFavoritePlayers->forPlayerId($player->playerId),
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}
