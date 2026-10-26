<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\BorrowRequest;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Message\WithdrawBorrowRequest;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\ReturnUrl;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The asker takes back a borrow request - all of it, or one puzzle (`itemId`). Quiet: the owner is not told. Back to
 * `return` when it is a safe local URL, else to the request.
 */
final class WithdrawBorrowRequestController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/{_locale}/borrow-requests/{requestId}/withdraw',
        name: 'borrow_request_withdraw',
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

        if ($this->isCsrfTokenValid(SendBorrowRequestsController::CSRF_TOKEN_ID, $request->request->getString('_token')) === false) {
            $this->addFlash('danger', $this->translator->trans('borrow_request.flash.expired'));

            return $this->redirectToRoute('borrow_request_detail', ['requestId' => $requestId], Response::HTTP_SEE_OTHER);
        }

        $itemId = $request->request->getString('itemId');

        // Anybody but the asker gets a 404 (BorrowRequestNotFound)
        $this->messageBus->dispatch(new WithdrawBorrowRequest($requestId, $viewer->playerId, $itemId !== '' ? $itemId : null));

        $this->addFlash('success', $this->translator->trans('borrow_request.flash.withdrawn'));

        $return = ReturnUrl::tryFrom($request->request->getString('return'));

        return $return !== null
            ? $this->redirect($return->path, Response::HTTP_SEE_OTHER)
            : $this->redirectToRoute('borrow_request_detail', ['requestId' => $requestId], Response::HTTP_SEE_OTHER);
    }
}
