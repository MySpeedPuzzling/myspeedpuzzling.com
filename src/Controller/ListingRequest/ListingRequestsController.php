<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\ListingRequest;

use SpeedPuzzling\Web\Query\GetListingRequests;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Sell/swap requests" (docs/features/marketplace/12-listing-requests.md) - Received (the viewer sells) and Sent.
 */
final class ListingRequestsController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetListingRequests $getListingRequests,
    ) {
    }

    #[Route(
        path: '/{_locale}/sell-swap-requests',
        name: 'listing_requests',
        methods: ['GET'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request): Response
    {
        $viewer = $this->retrieveLoggedUserProfile->getProfile();

        if ($viewer === null) {
            return $this->redirectToRoute('login');
        }

        $tab = $request->query->getString('tab') === 'received' ? 'received' : 'sent';

        $response = $this->render('listing_requests/index.html.twig', [
            'tab' => $tab,
            'requests' => $tab === 'received'
                ? $this->getListingRequests->receivedBy($viewer->playerId)
                : $this->getListingRequests->sentBy($viewer->playerId),
            'waiting_received' => $this->getListingRequests->waitingCountFor($viewer->playerId),
        ]);
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
