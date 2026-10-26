<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\BorrowRequest;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetUserPuzzleStatuses;
use SpeedPuzzling\Web\Services\BorrowRequest\BorrowAskSheetBuilder;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\PuzzleSelection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Ask to borrow" on the wishlist's selection bar (docs/features/wishlist-availability.md): the puzzles picked (POST body,
 * never the URL - the bar's CSRF token) become the sheet in the modal - who is asked for what, the note per person,
 * Send. Free for everybody: asking is not a member feature.
 */
final class BorrowAskSheetController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetUserPuzzleStatuses $getUserPuzzleStatuses,
        readonly private BorrowAskSheetBuilder $borrowAskSheetBuilder,
    ) {
    }

    #[Route(
        path: '/{_locale}/borrow-requests/ask',
        name: 'borrow_ask_sheet',
        methods: ['POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request): Response
    {
        $asker = $this->retrieveLoggedUserProfile->getProfile();

        if ($asker === null) {
            return $this->redirectToRoute('login');
        }

        if ($this->isCsrfTokenValid(PuzzleSelection::CSRF_TOKEN_ID, $request->request->getString('_token')) === false) {
            throw $this->createAccessDeniedException();
        }

        $sheet = $this->borrowAskSheetBuilder->build(
            $asker->playerId,
            PuzzleSelection::fromRequest($request)->puzzleIds,
            $this->getUserPuzzleStatuses->byPlayerId($asker->playerId),
        );

        $response = $this->render('borrow_requests/ask_sheet_modal.html.twig', [
            'sheet' => $sheet,
            'send_id' => Uuid::uuid7()->toString(),
            'csrf_token_id' => SendBorrowRequestsController::CSRF_TOKEN_ID,
        ]);
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
