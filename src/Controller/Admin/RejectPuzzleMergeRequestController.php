<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Admin;

use SpeedPuzzling\Web\Message\RejectPuzzleMergeRequest;
use SpeedPuzzling\Web\Security\AdminAccessVoter;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

final class RejectPuzzleMergeRequestController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/admin/puzzle-merge-requests/{id}/reject',
        name: 'admin_reject_puzzle_merge_request',
        methods: ['POST'],
    )]
    #[IsGranted(AdminAccessVoter::ADMIN_ACCESS)]
    public function __invoke(
        Request $request,
        string $id,
    ): Response {
        $player = $this->retrieveLoggedUserProfile->getProfile();

        if ($player === null) {
            throw $this->createAccessDeniedException();
        }

        $rejectionReason = $request->request->getString('rejection_reason');

        if ($rejectionReason === '') {
            $this->addFlash('error', $this->translator->trans('admin.puzzle_merge_request.rejection_reason_required'));
            return $this->redirectToRoute('admin_puzzle_merge_request_detail', ['id' => $id]);
        }

        $this->messageBus->dispatch(
            new RejectPuzzleMergeRequest(
                mergeRequestId: $id,
                reviewerId: $player->playerId,
                rejectionReason: $rejectionReason,
            ),
        );

        $this->addFlash('success', $this->translator->trans('admin.puzzle_merge_request.rejected'));

        return $this->redirectToRoute('admin_puzzle_merge_requests');
    }
}
