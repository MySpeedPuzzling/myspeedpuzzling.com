<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\BorrowRequest;

use SpeedPuzzling\Web\Query\GetBorrowRequests;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Borrow requests" (docs/features/wishlist-availability.md): Asked by me / Asked of me, newest first. Only ever the
 * viewer's own.
 */
final class BorrowRequestsController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetBorrowRequests $getBorrowRequests,
    ) {
    }

    #[Route(
        path: '/{_locale}/borrow-requests',
        name: 'borrow_requests',
        methods: ['GET'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request): Response
    {
        $viewer = $this->retrieveLoggedUserProfile->getProfile();

        if ($viewer === null) {
            return $this->redirectToRoute('login');
        }

        $tab = $request->query->getString('tab') === 'received' ? 'received' : 'asked';

        $response = $this->render('borrow_requests/index.html.twig', [
            'tab' => $tab,
            'requests' => $tab === 'received'
                ? $this->getBorrowRequests->askedOf($viewer->playerId)
                : $this->getBorrowRequests->askedBy($viewer->playerId),
        ]);
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
