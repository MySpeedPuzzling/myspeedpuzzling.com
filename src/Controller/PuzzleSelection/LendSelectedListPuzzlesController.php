<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\PuzzleSelection;

use SpeedPuzzling\Web\Exceptions\CannotLendToSelf;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\FormData\LendPuzzleFormData;
use SpeedPuzzling\Web\FormType\LendPuzzleFormType;
use SpeedPuzzling\Web\Message\LendPuzzlesToPlayer;
use SpeedPuzzling\Web\Query\GetFavoritePlayers;
use SpeedPuzzling\Web\Query\GetUserPuzzleStatuses;
use SpeedPuzzling\Web\Services\LendBorrowParticipantParser;
use SpeedPuzzling\Web\Services\MultiscanEligibility;
use SpeedPuzzling\Web\Services\PuzzleListSelectionResponder;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\MultiscanAction;
use SpeedPuzzling\Web\Value\PuzzleList;
use SpeedPuzzling\Web\Value\PuzzleSelection;
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
 * "Lend to…" for the puzzles selected on the unsolved page (docs/features/collections/bulk-actions.md "Other lists"):
 * the collection page's lend modal and batch lend. Puzzles already lent out and puzzles the player only borrowed (the
 * unsolved page lists those too) are left out and counted.
 */
final class LendSelectedListPuzzlesController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetUserPuzzleStatuses $getUserPuzzleStatuses,
        readonly private GetFavoritePlayers $getFavoritePlayers,
        readonly private MultiscanEligibility $eligibility,
        readonly private LendBorrowParticipantParser $participantParser,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
        readonly private PuzzleListSelectionResponder $responder,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/moje-seznamy/{list}/vybrane/pujcit',
            'en' => '/en/my-lists/{list}/selected/lend',
            'es' => '/es/mis-listas/{list}/seleccionados/prestar',
            'ja' => '/ja/my-lists/{list}/selected/lend',
            'fr' => '/fr/mes-listes/{list}/selection/preter',
            'de' => '/de/meine-listen/{list}/auswahl/verleihen',
        ],
        name: 'puzzle_list_selected_lend',
        requirements: ['list' => 'unsolved'],
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
        $statuses = $this->getUserPuzzleStatuses->byPlayerId($player->playerId);

        $borrowed = array_values(array_filter($selection->puzzleIds, static fn (string $id): bool => isset($statuses->borrowedPuzzleIds[$id])));
        $report = $this->eligibility->check(
            MultiscanAction::Lend,
            array_values(array_diff($selection->puzzleIds, $borrowed)),
            $statuses,
        );

        $form = $this->createForm(LendPuzzleFormType::class, new LendPuzzleFormData());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && $report->eligible !== []) {
            if ($this->isCsrfTokenValid(PuzzleSelection::CSRF_TOKEN_ID, $request->request->getString('_token')) === false) {
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

                $toast = $this->translator->trans('collection_selection.done.lent', [
                    '%count%' => count($report->eligible),
                    '%name%' => $borrower->displayName ?? $borrower->playerName ?? (string) $formData->borrowerCode,
                ]);

                if ($report->skipped !== []) {
                    $toast .= ' ' . $this->translator->trans('collection_selection.done.already_lent', ['%count%' => count($report->skipped)]);
                }

                if ($borrowed !== []) {
                    $toast .= ' ' . $this->translator->trans('puzzle_selection.done.borrowed_left_out', ['%count%' => count($borrowed)]);
                }

                // The lent badge sits on every card: the page is loaded again rather than patched card by card
                return $this->responder->respond(
                    request: $request,
                    list: $list,
                    playerId: $player->playerId,
                    outcome: new SelectedPuzzlesOutcome(changed: count($report->eligible)),
                    toast: $toast,
                    refreshPage: true,
                );
            }
        }

        return $this->render('collections/selected_lend_modal.html.twig', [
            'form' => $form,
            'selection' => $selection,
            'action_url' => $this->generateUrl('puzzle_list_selected_lend', ['list' => $list->value]),
            'lendable_count' => count($report->eligible),
            'already_lent_count' => count($report->skipped),
            'borrowed_count' => count($borrowed),
            'favorite_players' => $this->getFavoritePlayers->forPlayerId($player->playerId),
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}
