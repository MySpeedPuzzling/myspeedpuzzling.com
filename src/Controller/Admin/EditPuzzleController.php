<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Admin;

use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\FormData\PuzzleRecordFormData;
use SpeedPuzzling\Web\FormType\PuzzleRecordFormType;
use SpeedPuzzling\Web\Message\EditPuzzle;
use SpeedPuzzling\Web\Query\GetPendingPuzzleProposals;
use SpeedPuzzling\Web\Query\GetPuzzleRecord;
use SpeedPuzzling\Web\Security\PuzzleModerationVoter;
use SpeedPuzzling\Web\Services\PhotoStash\FormPhotoStash;
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
 * A moderator's direct edit of a puzzle - the change request review's form without a proposal, saved at once
 * and recorded in the puzzle's history. A refused form comes back with what was typed, an uploaded photo
 * included (FormPhotoStash).
 */
final class EditPuzzleController extends AbstractController
{
    public function __construct(
        private readonly GetPuzzleRecord $getPuzzleRecord,
        private readonly GetPendingPuzzleProposals $getPendingPuzzleProposals,
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
        private readonly FormPhotoStash $formPhotoStash,
    ) {
    }

    #[Route(
        path: '/admin/puzzles/{puzzleId}/edit',
        name: 'admin_edit_puzzle',
        methods: ['GET', 'POST'],
    )]
    #[IsGranted(PuzzleModerationVoter::PUZZLE_MODERATION_ACCESS)]
    public function __invoke(Request $request, string $puzzleId): Response
    {
        $puzzle = $this->getPuzzleRecord->byId($puzzleId) ?? throw new PuzzleNotFound();
        $player = $this->retrieveLoggedUserProfile->getProfile() ?? throw $this->createAccessDeniedException();

        $form = $this->createForm(PuzzleRecordFormType::class, PuzzleRecordFormData::fromPuzzle($puzzle));

        $restoredPhotos = $this->formPhotoStash->restore($request, $form, $player->playerId);
        $form->handleRequest($request);
        $this->formPhotoStash->reportLost($form, $restoredPhotos);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();

            $this->messageBus->dispatch(new EditPuzzle(
                puzzleId: $puzzle->puzzleId,
                editorId: $player->playerId,
                values: $data->toValues(),
                note: $data->note,
            ));

            $this->formPhotoStash->forget($restoredPhotos, $player->playerId);
            $this->addFlash('success', $this->translator->trans('admin.puzzle_edit.saved'));

            $returnUrl = ReturnUrl::tryFrom($request->query->getString('return'));

            return $returnUrl !== null
                ? $this->redirect($returnUrl->path)
                : $this->redirectToRoute('puzzle_detail', ['puzzleId' => $puzzle->puzzleId]);
        }

        return $this->render('admin/puzzle_edit.html.twig', [
            'puzzle' => $puzzle,
            'form' => $form,
            'kept_photos' => $this->formPhotoStash->keep($form, $restoredPhotos, $player->playerId),
            'pending_proposals' => $this->getPendingPuzzleProposals->forPuzzle($puzzle->puzzleId),
        ]);
    }
}
