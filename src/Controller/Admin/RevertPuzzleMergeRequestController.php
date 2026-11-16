<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Admin;

use SpeedPuzzling\Web\Exceptions\InvalidPuzzleValues;
use SpeedPuzzling\Web\Exceptions\PuzzleMergeRequestNotFound;
use SpeedPuzzling\Web\Message\RevertPuzzleMergeRequest;
use SpeedPuzzling\Web\Query\GetPuzzleMergeRequests;
use SpeedPuzzling\Web\Security\AdminAccessVoter;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Undo an approved merge (admins only, confirmed in a modal on the merge request page) - RevertPuzzleMergeRequestHandler.
 */
final class RevertPuzzleMergeRequestController extends AbstractController
{
    public const string CSRF_TOKEN_ID = 'revert_puzzle_merge_request';

    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private readonly GetPuzzleMergeRequests $getPuzzleMergeRequests,
    ) {
    }

    #[Route(
        path: '/admin/puzzle-merge-requests/{id}/revert',
        name: 'admin_revert_puzzle_merge_request',
        methods: ['POST'],
    )]
    #[IsGranted(AdminAccessVoter::ADMIN_ACCESS)]
    public function __invoke(Request $request, string $id): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile() ?? throw $this->createAccessDeniedException();

        if ($this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $request->request->getString('_token')) === false) {
            throw $this->createAccessDeniedException();
        }

        $mergeRequest = $this->getPuzzleMergeRequests->byId($id) ?? throw new PuzzleMergeRequestNotFound();

        if ($mergeRequest->finalMergedPuzzleId === null) {
            $this->addFlash('error', 'Only an approved merge can be reverted.');

            return $this->redirectToRoute('admin_puzzle_merge_request_detail', ['id' => $id]);
        }

        $note = trim($request->request->getString('note'));

        try {
            $this->messageBus->dispatch(new RevertPuzzleMergeRequest(
                mergeRequestId: $id,
                adminId: $player->playerId,
                survivorPuzzleId: $mergeRequest->finalMergedPuzzleId,
                note: $note !== '' ? $note : null,
            ));
        } catch (InvalidPuzzleValues $refusal) {
            $this->addFlash('error', $refusal->getMessage());

            return $this->redirectToRoute('admin_puzzle_merge_request_detail', ['id' => $id]);
        }

        $this->addFlash('success', 'The merge was reverted - the merged puzzle exists again.');

        return $this->redirectToRoute('admin_puzzle_merge_request_detail', ['id' => $id]);
    }
}
