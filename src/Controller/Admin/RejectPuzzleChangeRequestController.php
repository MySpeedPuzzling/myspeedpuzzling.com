<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Admin;

use Auth0\Symfony\Models\User;
use SpeedPuzzling\Web\Message\RejectPuzzleChangeRequest;
use SpeedPuzzling\Web\Security\AdminAccessVoter;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class RejectPuzzleChangeRequestController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    #[Route(
        path: '/admin/puzzle-change-requests/{id}/reject',
        name: 'admin_reject_puzzle_change_request',
        methods: ['POST'],
    )]
    #[IsGranted(AdminAccessVoter::ADMIN_ACCESS)]
    public function __invoke(
        #[CurrentUser] User $user,
        Request $request,
        string $id,
    ): Response {
        $player = $this->retrieveLoggedUserProfile->getProfile();

        if ($player === null) {
            throw $this->createAccessDeniedException();
        }

        $rejectionReason = $request->request->getString('rejection_reason');

        if ($rejectionReason === '') {
            $this->addFlash('error', 'Rejection reason is required.');
            return $this->redirectToRoute('admin_puzzle_change_request_detail', ['id' => $id]);
        }

        $this->messageBus->dispatch(
            new RejectPuzzleChangeRequest(
                changeRequestId: $id,
                reviewerId: $player->playerId,
                rejectionReason: $rejectionReason,
            ),
        );

        $this->addFlash('success', 'Change request has been rejected.');

        return $this->redirectToRoute('admin_puzzle_change_requests');
    }
}
