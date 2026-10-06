<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Admin;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PuzzleIsStillSecret;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Query\IsPuzzleKeptSecret;
use SpeedPuzzling\Web\Message\SubmitPuzzleMergeRequest;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Security\PuzzleModerationVoter;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\LanguageTag;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "This new puzzle is a duplicate of that one": files a merge request with the
 * moderator as reporter (and the language of the new puzzle's name, when given)
 * and opens it in the existing merge review, where the merged values are
 * settled and every solving time moves to the survivor.
 */
final class MergeUnapprovedPuzzleController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private readonly PuzzleRepository $puzzleRepository,
        private readonly TranslatorInterface $translator,
        private readonly IsPuzzleKeptSecret $isPuzzleKeptSecret,
    ) {
    }

    #[Route(
        path: '/admin/puzzle-approvals/{puzzleId}/merge',
        name: 'admin_merge_unapproved_puzzle',
        methods: ['POST'],
    )]
    #[IsGranted(PuzzleModerationVoter::PUZZLE_MODERATION_ACCESS)]
    public function __invoke(string $puzzleId, Request $request): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();

        if ($player === null) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid('merge-puzzle-' . $puzzleId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        // Accepts a bare id or anything containing one - a pasted puzzle URL
        $targetPuzzleId = null;

        if (preg_match('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $request->request->getString('target_puzzle'), $matches) === 1) {
            $targetPuzzleId = strtolower($matches[0]);
        }

        // A target a competition keeps secret answers exactly like an unknown one - its existence is not signalled
        if (
            $targetPuzzleId === null
            || $targetPuzzleId === $puzzleId
            || $this->puzzleExists($targetPuzzleId) === false
            || $this->isPuzzleKeptSecret->byId($targetPuzzleId)
        ) {
            $this->addFlash('error', $this->translator->trans('admin.puzzle_approval.merge_target_invalid'));

            return $this->redirectToRoute('admin_puzzle_approval_detail', ['puzzleId' => $puzzleId]);
        }

        $mergeRequestId = Uuid::uuid7()->toString();

        // Optional: what the new puzzle's name is in - the merge review's names editor starts from it
        $nameLanguage = LanguageTag::normalize($request->request->getString('name_language'));

        try {
            $this->messageBus->dispatch(new SubmitPuzzleMergeRequest(
                mergeRequestId: $mergeRequestId,
                sourcePuzzleId: $puzzleId,
                reporterId: $player->playerId,
                duplicatePuzzleIds: [$targetPuzzleId],
                reportedNameLanguages: $nameLanguage !== null ? [$puzzleId => $nameLanguage] : [],
            ));
        } catch (PuzzleIsStillSecret | PuzzleNotFound) {
            // Became secret meanwhile (either side) - answered like an unknown target
            $this->addFlash('error', $this->translator->trans('admin.puzzle_approval.merge_target_invalid'));

            return $this->redirectToRoute('admin_puzzle_approval_detail', ['puzzleId' => $puzzleId]);
        }

        return $this->redirectToRoute('admin_puzzle_merge_request_detail', [
            'id' => $mergeRequestId,
            'return' => $this->generateUrl('admin_puzzle_approvals'),
            'return_title' => 'Puzzle approvals',
        ]);
    }

    private function puzzleExists(string $puzzleId): bool
    {
        try {
            $this->puzzleRepository->get($puzzleId);

            return true;
        } catch (PuzzleNotFound) {
            return false;
        }
    }
}
