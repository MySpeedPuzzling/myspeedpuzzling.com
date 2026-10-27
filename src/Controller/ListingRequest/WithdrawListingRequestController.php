<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\ListingRequest;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Message\WithdrawListingRequest;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The buyer takes back a listing request - all of it, or one listing (`itemId`). The seller gets no notification.
 */
final class WithdrawListingRequestController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/{_locale}/sell-swap-requests/{requestId}/withdraw',
        name: 'listing_request_withdraw',
        requirements: ['requestId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(string $requestId, Request $request): Response
    {
        $viewer = $this->retrieveLoggedUserProfile->getProfile();

        if ($viewer === null) {
            return $this->redirectToRoute('login');
        }

        if ($this->isCsrfTokenValid(SendListingRequestsController::CSRF_TOKEN_ID, $request->request->getString('_token')) === false) {
            $this->addFlash('danger', $this->translator->trans('listing_request.flash.expired'));

            return $this->redirectToRoute('listing_request_detail', ['requestId' => $requestId], Response::HTTP_SEE_OTHER);
        }

        $itemId = $request->request->getString('itemId');

        // Anybody but the buyer gets a 404 (ListingRequestNotFound)
        $this->messageBus->dispatch(new WithdrawListingRequest($requestId, $viewer->playerId, $itemId !== '' ? $itemId : null));

        $this->addFlash('success', $this->translator->trans('listing_request.flash.withdrawn'));

        return $this->redirectToRoute('listing_request_detail', ['requestId' => $requestId], Response::HTTP_SEE_OTHER);
    }
}
