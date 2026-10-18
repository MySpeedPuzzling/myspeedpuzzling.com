<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Admin;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PuzzleImageNotRestorable;
use SpeedPuzzling\Web\Exceptions\PuzzleIsStillSecret;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Message\RestorePuzzleImage;
use SpeedPuzzling\Web\Security\PuzzleModerationVoter;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Services\SecretPuzzleAccess;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Restore this picture" next to an image change on a puzzle's history (docs/features/puzzle-approvals.md "Restoring a
 * picture") - confirmed in place, posted here, back to the history with a flash. Web only: the internal API has no
 * restore endpoint.
 */
final class RestorePuzzleImageController extends AbstractController
{
    public const string CSRF_TOKEN_ID = 'restore_puzzle_image';

    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private readonly SecretPuzzleAccess $secretPuzzleAccess,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/admin/puzzles/{puzzleId}/history/{decisionId}/restore-image',
        name: 'admin_restore_puzzle_image',
        methods: ['POST'],
    )]
    #[IsGranted(PuzzleModerationVoter::PUZZLE_MODERATION_ACCESS)]
    public function __invoke(Request $request, string $puzzleId, string $decisionId): Response
    {
        if (Uuid::isValid($puzzleId) === false || Uuid::isValid($decisionId) === false) {
            throw new PuzzleNotFound();
        }

        // Whoever does not see the history page does not restore from it (PuzzleHistoryController)
        $this->secretPuzzleAccess->assertVisible($puzzleId, alsoWhileImageHidden: true);

        if ($this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $request->request->getString('_token')) === false) {
            throw $this->createAccessDeniedException();
        }

        $player = $this->retrieveLoggedUserProfile->getProfile() ?? throw $this->createAccessDeniedException();

        try {
            $this->messageBus->dispatch(new RestorePuzzleImage(
                puzzleId: $puzzleId,
                decisionId: $decisionId,
                moderatorId: $player->playerId,
            ));

            $this->addFlash('success', $this->translator->trans('admin.puzzle_history.restored'));
        } catch (PuzzleImageNotRestorable $exception) {
            $this->addFlash('danger', $this->translator->trans('admin.puzzle_history.' . $exception->reason));
        } catch (PuzzleIsStillSecret) {
            $this->addFlash('danger', $this->translator->trans('competition.reveal.puzzle_still_secret'));
        }

        return $this->redirectToRoute('admin_puzzle_history', ['puzzleId' => $puzzleId], Response::HTTP_SEE_OTHER);
    }
}
