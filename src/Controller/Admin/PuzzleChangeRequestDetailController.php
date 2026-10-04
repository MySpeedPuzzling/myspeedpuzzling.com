<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Admin;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PuzzleChangeRequestAlreadyReviewed;
use SpeedPuzzling\Web\Exceptions\PuzzleChangeRequestNotFound;
use SpeedPuzzling\Web\FormData\ReviewPuzzleChangeRequestFormData;
use SpeedPuzzling\Web\FormType\ReviewPuzzleChangeRequestFormType;
use SpeedPuzzling\Web\Message\ApprovePuzzleChangeRequest;
use SpeedPuzzling\Web\Query\GetPuzzleChangeRequests;
use SpeedPuzzling\Web\Security\PuzzleModerationVoter;
use SpeedPuzzling\Web\Services\PhotoStash\FormPhotoStash;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\PuzzleReportStatus;
use SpeedPuzzling\Web\Value\ReviewedPuzzleValues;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The review of a change request. A pending one is approved through a form holding the whole puzzle
 * (every field editable, the player's proposal prefilled and marked), posted back here so a refused
 * form comes back with what the reviewer typed - an uploaded photo included (FormPhotoStash).
 */
final class PuzzleChangeRequestDetailController extends AbstractController
{
    public function __construct(
        private readonly GetPuzzleChangeRequests $getPuzzleChangeRequests,
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
        private readonly FormPhotoStash $formPhotoStash,
    ) {
    }

    #[Route(
        path: '/admin/puzzle-change-requests/{id}',
        name: 'admin_puzzle_change_request_detail',
        methods: ['GET', 'POST'],
    )]
    #[IsGranted(PuzzleModerationVoter::PUZZLE_MODERATION_ACCESS)]
    public function __invoke(Request $request, string $id): Response
    {
        if (!Uuid::isValid($id)) {
            throw new PuzzleChangeRequestNotFound();
        }

        $changeRequest = $this->getPuzzleChangeRequests->byId($id) ?? throw new PuzzleChangeRequestNotFound();

        if ($changeRequest->status !== PuzzleReportStatus::Pending) {
            return $this->render('admin/puzzle_change_request_detail.html.twig', [
                'request' => $changeRequest,
                'form' => null,
            ]);
        }

        $form = $this->createForm(
            ReviewPuzzleChangeRequestFormType::class,
            ReviewPuzzleChangeRequestFormData::prefilled($changeRequest),
            ['has_proposed_image' => $changeRequest->hasImageChange()],
        );

        $player = $this->retrieveLoggedUserProfile->getProfile() ?? throw $this->createAccessDeniedException();

        $restoredPhotos = $this->formPhotoStash->restore($request, $form, $player->playerId);
        $form->handleRequest($request);
        $this->formPhotoStash->reportLost($form, $restoredPhotos);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();

            assert($data->name !== null && $data->piecesCount !== null);

            try {
                $this->messageBus->dispatch(new ApprovePuzzleChangeRequest(
                    changeRequestId: $id,
                    reviewerId: $player->playerId,
                    reviewed: new ReviewedPuzzleValues(
                        name: $data->name,
                        alternativeName: $data->alternativeName,
                        manufacturerId: $data->manufacturerId,
                        piecesCount: $data->piecesCount,
                        ean: $data->ean,
                        identificationNumber: $data->identificationNumber,
                        image: $data->imageChoice(),
                        uploadedImage: $data->puzzlePhoto,
                    ),
                ));
            } catch (PuzzleChangeRequestAlreadyReviewed) {
                $this->formPhotoStash->forget($restoredPhotos, $player->playerId);
                $this->addFlash('warning', $this->translator->trans('admin.puzzle_change_request.already_reviewed'));

                return $this->redirectToRoute('admin_puzzle_change_request_detail', ['id' => $id]);
            }

            $this->formPhotoStash->forget($restoredPhotos, $player->playerId);
            $this->addFlash('success', $this->translator->trans('admin.puzzle_change_request.approved'));

            return $this->redirectToRoute('admin_puzzle_change_requests');
        }

        return $this->render('admin/puzzle_change_request_detail.html.twig', [
            'request' => $changeRequest,
            'form' => $form,
            'kept_photos' => $this->formPhotoStash->keep($form, $restoredPhotos, $player->playerId),
        ]);
    }
}
