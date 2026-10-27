<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\ListingRequest;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Services\ListingRequest\ListingAskSheetBuilder;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\ListingSelection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The listing request sheet (docs/features/marketplace/12-listing-requests.md): the buyer's selection bar on a sell/swap
 * list or the marketplace posts the ticked `listingIds[]` here, into the modal frame. Nothing is sent before Send.
 */
final class ListingAskSheetController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private ListingAskSheetBuilder $listingAskSheetBuilder,
    ) {
    }

    #[Route(
        path: '/{_locale}/sell-swap-requests/ask',
        name: 'listing_ask_sheet',
        methods: ['POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request): Response
    {
        $buyer = $this->retrieveLoggedUserProfile->getProfile();

        if ($buyer === null) {
            return $this->redirectToRoute('login');
        }

        if ($this->isCsrfTokenValid(ListingSelection::CSRF_TOKEN_ID, $request->request->getString('_token')) === false) {
            throw $this->createAccessDeniedException();
        }

        $selection = ListingSelection::fromRequest($request);

        $response = $this->render('listing_requests/ask_sheet_modal.html.twig', [
            'sheet' => $this->listingAskSheetBuilder->build($buyer->playerId, $buyer->country, $selection->listingIds, $selection->truncated),
            'send_id' => Uuid::uuid7()->toString(),
            'csrf_token_id' => SendListingRequestsController::CSRF_TOKEN_ID,
        ]);
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
