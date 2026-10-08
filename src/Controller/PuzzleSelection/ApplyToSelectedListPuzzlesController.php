<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\PuzzleSelection;

use SpeedPuzzling\Web\Message\MarkPuzzlesAsSoldOrSwapped;
use SpeedPuzzling\Web\Message\RemovePuzzleListingReservations;
use SpeedPuzzling\Web\Message\RemovePuzzlesFromAllCollections;
use SpeedPuzzling\Web\Message\RemovePuzzlesFromSellSwapList;
use SpeedPuzzling\Web\Message\RemovePuzzlesFromWishList;
use SpeedPuzzling\Web\Message\ReservePuzzleListings;
use SpeedPuzzling\Web\Message\ReturnLentPuzzles;
use SpeedPuzzling\Web\Query\GetUserPuzzleStatuses;
use SpeedPuzzling\Web\Services\MultiscanEligibility;
use SpeedPuzzling\Web\Services\PuzzleListSelectionResponder;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\MultiscanAction;
use SpeedPuzzling\Web\Value\PuzzleList;
use SpeedPuzzling\Web\Value\PuzzleListSelectionAction;
use SpeedPuzzling\Web\Value\PuzzleSelection;
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
 * Remove / Mark as sold / Reserve / Remove reservation / Return for the puzzles selected on a list page (wishlist,
 * sell/swap, unsolved, lend/borrow - docs/features/collections/bulk-actions.md "Other lists"). The bar posts the ids
 * into the modal frame: a reservation change acts at once, everything else answers a confirmation first, whose submit
 * acts in one message. Members only; the messages touch the signed-in player's own rows only.
 */
final class ApplyToSelectedListPuzzlesController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetUserPuzzleStatuses $getUserPuzzleStatuses,
        readonly private MultiscanEligibility $eligibility,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
        readonly private PuzzleListSelectionResponder $responder,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/moje-seznamy/{list}/vybrane/{action}',
            'en' => '/en/my-lists/{list}/selected/{action}',
            'es' => '/es/mis-listas/{list}/seleccionados/{action}',
            'ja' => '/ja/my-lists/{list}/selected/{action}',
            'fr' => '/fr/mes-listes/{list}/selection/{action}',
            'de' => '/de/meine-listen/{list}/auswahl/{action}',
        ],
        name: 'puzzle_list_selected_action',
        requirements: ['list' => PuzzleList::ROUTE_REQUIREMENT, 'action' => PuzzleListSelectionAction::ROUTE_REQUIREMENT],
        methods: ['POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request, PuzzleList $list, PuzzleListSelectionAction $action): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();
        assert($player !== null);

        if ($player->activeMembership === false) {
            throw $this->createAccessDeniedException();
        }

        if ($action->isAvailableOn($list) === false) {
            throw $this->createNotFoundException();
        }

        $selection = PuzzleSelection::fromRequest($request);
        $prefix = $action->translationPrefix($list);

        if ($selection->puzzleIds === [] || ($action->needsConfirmation() && $request->request->getBoolean('confirm') === false)) {
            return $this->render('puzzle_selection/confirm_modal.html.twig', [
                'list' => $list,
                'action' => $action,
                'prefix' => $prefix,
                'selection' => $selection,
            ]);
        }

        if ($this->isCsrfTokenValid(PuzzleSelection::CSRF_TOKEN_ID, $request->request->getString('_token')) === false) {
            throw $this->createAccessDeniedException();
        }

        $outcome = $this->apply($list, $action, $player->playerId, $selection->puzzleIds);

        return $this->responder->respond(
            request: $request,
            list: $list,
            playerId: $player->playerId,
            outcome: $outcome,
            toast: $this->translator->trans($prefix . '.done', ['%count%' => $outcome->changed]),
            removedPuzzleIds: $action->removesCards($list) ? $selection->puzzleIds : [],
            refreshPage: $action->removesCards($list) === false,
        );
    }

    /**
     * @param list<string> $puzzleIds
     */
    private function apply(PuzzleList $list, PuzzleListSelectionAction $action, string $playerId, array $puzzleIds): SelectedPuzzlesOutcome
    {
        if ($action === PuzzleListSelectionAction::Return) {
            // The batch return refuses a whole batch holding a puzzle that is not lent: those are left out up front
            $report = $this->eligibility->check(MultiscanAction::Return, $puzzleIds, $this->getUserPuzzleStatuses->byPlayerId($playerId));

            if ($report->eligible !== []) {
                $this->messageBus->dispatch(new ReturnLentPuzzles($playerId, $report->eligible));
            }

            return new SelectedPuzzlesOutcome(changed: count($report->eligible), skipped: count($report->skipped));
        }

        $message = match (true) {
            $list === PuzzleList::Wishlist => new RemovePuzzlesFromWishList($playerId, $puzzleIds),
            $list === PuzzleList::Unsolved => new RemovePuzzlesFromAllCollections($playerId, $puzzleIds),
            $action === PuzzleListSelectionAction::Sold => new MarkPuzzlesAsSoldOrSwapped($playerId, $puzzleIds),
            $action === PuzzleListSelectionAction::Reserve => new ReservePuzzleListings($playerId, $puzzleIds),
            $action === PuzzleListSelectionAction::Unreserve => new RemovePuzzleListingReservations($playerId, $puzzleIds),
            default => new RemovePuzzlesFromSellSwapList($playerId, $puzzleIds),
        };

        $outcome = $this->messageBus->dispatch($message)->last(HandledStamp::class)?->getResult();
        assert($outcome instanceof SelectedPuzzlesOutcome);

        return $outcome;
    }
}
