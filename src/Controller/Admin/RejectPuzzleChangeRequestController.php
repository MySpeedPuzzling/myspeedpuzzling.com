<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Admin;

use SpeedPuzzling\Web\Exceptions\PuzzleChangeRequestAlreadyReviewed;
use SpeedPuzzling\Web\Exceptions\PuzzleChangeRequestNotFound;
use SpeedPuzzling\Web\Message\RejectPuzzleChangeRequest;
use SpeedPuzzling\Web\Query\GetPuzzleChangeRequests;
use SpeedPuzzling\Web\Security\PuzzleModerationVoter;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

final class RejectPuzzleChangeRequestController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private readonly TranslatorInterface $translator,
        private readonly GetPuzzleChangeRequests $getPuzzleChangeRequests,
    ) {
    }

    #[Route(
        path: '/admin/puzzle-change-requests/{id}/reject',
        name: 'admin_reject_puzzle_change_request',
        methods: ['POST'],
    )]
    #[IsGranted(PuzzleModerationVoter::PUZZLE_MODERATION_ACCESS)]
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
            $this->addFlash('error', $this->translator->trans('admin.puzzle_change_request.rejection_reason_required'));
            return $this->redirectToRoute('admin_puzzle_change_request_detail', ['id' => $id]);
        }

        $puzzleId = $this->getPuzzleChangeRequests->puzzleIdOf($id) ?? throw new PuzzleChangeRequestNotFound();

        try {
            $this->messageBus->dispatch(
                new RejectPuzzleChangeRequest(
                    changeRequestId: $id,
                    puzzleId: $puzzleId,
                    reviewerId: $player->playerId,
                    rejectionReason: $rejectionReason,
                ),
            );
        } catch (PuzzleChangeRequestAlreadyReviewed) {
            // Like the approval: somebody decided it meanwhile
            $this->addFlash('warning', $this->translator->trans('admin.puzzle_change_request.already_reviewed'));

            return $this->redirectToRoute('admin_puzzle_change_request_detail', ['id' => $id]);
        }

        $this->addFlash('success', $this->translator->trans('admin.puzzle_change_request.rejected'));

        return $this->redirectToRoute('admin_puzzle_change_requests');
    }
}
