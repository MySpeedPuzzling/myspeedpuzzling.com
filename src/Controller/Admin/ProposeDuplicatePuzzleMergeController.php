<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Admin;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\DuplicatePuzzleSignalAlreadyResolved;
use SpeedPuzzling\Web\Message\ProposeDuplicatePuzzleMerge;
use SpeedPuzzling\Web\Security\AdminAccessVoter;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Propose merge" on a catalogue signal (docs/features/duplicate-results.md, Layer 4): files a merge request with
 * the admin as reporter and opens it in the existing merge review.
 */
#[IsGranted(AdminAccessVoter::ADMIN_ACCESS)]
final class ProposeDuplicatePuzzleMergeController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    #[Route(
        path: '/admin/duplicate-results/puzzle-signals/{signalId}/propose-merge',
        name: 'admin_duplicate_puzzle_signal_propose_merge',
        methods: ['POST'],
    )]
    public function __invoke(string $signalId, Request $request): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();

        if ($player === null) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid('duplicate-puzzle-signal-' . $signalId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $mergeRequestId = Uuid::uuid7()->toString();

        try {
            $this->messageBus->dispatch(new ProposeDuplicatePuzzleMerge(
                signalId: $signalId,
                playerId: $player->playerId,
                mergeRequestId: $mergeRequestId,
            ));
        } catch (HandlerFailedException $exception) {
            if ($exception->getPrevious() instanceof DuplicatePuzzleSignalAlreadyResolved) {
                $this->addFlash('warning', 'This signal was already handled in the meantime.');

                return $this->redirectToRoute('admin_duplicate_results', ['_fragment' => 'puzzle-signals']);
            }

            throw $exception;
        }

        return $this->redirectToRoute('admin_puzzle_merge_request_detail', [
            'id' => $mergeRequestId,
            'return' => $this->generateUrl('admin_duplicate_results') . '#puzzle-signals',
            'return_title' => 'Duplicate results',
        ]);
    }
}
