<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Admin;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\InvalidPuzzleApproval;
use SpeedPuzzling\Web\Exceptions\InvalidPuzzleValues;
use SpeedPuzzling\Web\Exceptions\PuzzleAlreadyApproved;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\FormData\ApprovePuzzleFormData;
use SpeedPuzzling\Web\FormType\ApprovePuzzleFormType;
use SpeedPuzzling\Web\Message\ApprovePuzzle;
use SpeedPuzzling\Web\Query\GetPuzzleApprovals;
use SpeedPuzzling\Web\Results\BrandSuggestion;
use SpeedPuzzling\Web\Security\PuzzleModerationVoter;
use SpeedPuzzling\Web\Services\PhotoStash\FormPhotoStash;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * One newly added puzzle in the approval queue: the similar puzzles in the catalogue to compare it with (merge it
 * if it is one of them), and the approval - its record with the moderator's corrections, a new photo included, and
 * its brand settled. The form posts back here, so a refused one comes back with what was typed (FormPhotoStash
 * keeps the photo).
 */
final class PuzzleApprovalDetailController extends AbstractController
{
    public function __construct(
        private readonly GetPuzzleApprovals $getPuzzleApprovals,
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
        private readonly FormPhotoStash $formPhotoStash,
    ) {
    }

    #[Route(
        path: '/admin/puzzle-approvals/{puzzleId}',
        name: 'admin_puzzle_approval_detail',
        methods: ['GET', 'POST'],
    )]
    #[IsGranted(PuzzleModerationVoter::PUZZLE_MODERATION_ACCESS)]
    public function __invoke(Request $request, string $puzzleId): Response
    {
        if (!Uuid::isValid($puzzleId)) {
            throw new PuzzleNotFound();
        }

        $puzzle = $this->getPuzzleApprovals->byPuzzleId($puzzleId) ?? throw new PuzzleNotFound();

        $brandSuggestions = [];

        if ($puzzle->manufacturerId !== null && $puzzle->manufacturerApproved === false) {
            $brandSuggestions = $this->getPuzzleApprovals->brandSuggestions($puzzle->manufacturerId, $puzzle->ean);
        }

        $similarPuzzles = $this->getPuzzleApprovals->possibleDuplicates(
            $puzzleId,
            array_map(static fn (BrandSuggestion $suggestion): string => $suggestion->manufacturerId, $brandSuggestions),
        );

        $parameters = [
            'puzzle' => $puzzle,
            'similar_puzzles' => $similarPuzzles,
            'brand_suggestions' => $brandSuggestions,
            'form' => null,
            'kept_photos' => [],
        ];

        if ($puzzle->approved) {
            return $this->render('admin/puzzle_approval_detail.html.twig', $parameters);
        }

        $player = $this->retrieveLoggedUserProfile->getProfile() ?? throw $this->createAccessDeniedException();

        $data = ApprovePuzzleFormData::fromPendingPuzzle($puzzle, $brandSuggestions[0]->manufacturerId ?? null);
        $form = $this->createForm(ApprovePuzzleFormType::class, $data, [
            'new_brand' => $data->newBrand,
            'brand_suggestions' => $brandSuggestions,
        ]);

        $restoredPhotos = $this->formPhotoStash->restore($request, $form, $player->playerId);
        $form->handleRequest($request);
        $this->formPhotoStash->reportLost($form, $restoredPhotos);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            assert($data->name !== null && $data->piecesCount !== null);

            try {
                $this->messageBus->dispatch(new ApprovePuzzle(
                    puzzleId: $puzzleId,
                    reviewerId: $player->playerId,
                    name: $data->name,
                    piecesCount: $data->piecesCount,
                    ean: $data->ean,
                    identificationNumber: $data->identificationNumber,
                    brandChoice: $data->resolvedBrandChoice(),
                    targetManufacturerId: $data->targetManufacturerId,
                    alternativeName: $data->alternativeName,
                    uploadedImage: $data->puzzlePhoto,
                    note: $data->note,
                ));

                $this->formPhotoStash->forget($restoredPhotos, $player->playerId);
                $this->addFlash('success', $this->translator->trans('admin.puzzle_approval.approved'));

                return $this->redirectToRoute('admin_puzzle_approvals');
            } catch (InvalidPuzzleValues $exception) {
                $form->addError(new FormError($exception->getMessage()));
            } catch (HandlerFailedException $exception) {
                $previous = $exception->getPrevious();

                if ($previous instanceof PuzzleAlreadyApproved) {
                    $this->formPhotoStash->forget($restoredPhotos, $player->playerId);
                    $this->addFlash('warning', $this->translator->trans('admin.puzzle_approval.already_approved'));

                    return $this->redirectToRoute('admin_puzzle_approvals');
                }

                if ($previous instanceof InvalidPuzzleApproval === false) {
                    throw $exception;
                }

                $form->addError(new FormError($previous->getMessage()));
            }
        }

        return $this->render('admin/puzzle_approval_detail.html.twig', [
            'form' => $form,
            'kept_photos' => $this->formPhotoStash->keep($form, $restoredPhotos, $player->playerId),
        ] + $parameters);
    }
}
