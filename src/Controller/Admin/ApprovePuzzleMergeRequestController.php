<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Admin;

use SpeedPuzzling\Web\Message\ApprovePuzzleMergeRequest;
use SpeedPuzzling\Web\Security\AdminAccessVoter;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ApprovePuzzleMergeRequestController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/admin/puzzle-merge-requests/{id}/approve',
        name: 'admin_approve_puzzle_merge_request',
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

        $survivorPuzzleId = $request->request->getString('survivor_puzzle_id');
        $mergedName = $request->request->getString('merged_name');
        $mergedEan = $request->request->getString('merged_ean');
        $mergedIdentificationNumber = $request->request->getString('merged_identification_number');
        $mergedPiecesCount = $request->request->getInt('merged_pieces_count');
        $mergedManufacturerId = $request->request->getString('merged_manufacturer_id');
        $selectedImagePuzzleId = $request->request->getString('selected_image_puzzle_id');

        if ($survivorPuzzleId === '' || $mergedName === '' || $mergedPiecesCount === 0) {
            $this->addFlash('error', $this->translator->trans('admin.puzzle_merge_request.validation_failed'));
            return $this->redirectToRoute('admin_puzzle_merge_request_detail', ['id' => $id]);
        }

        $this->messageBus->dispatch(
            new ApprovePuzzleMergeRequest(
                mergeRequestId: $id,
                reviewerId: $player->playerId,
                survivorPuzzleId: $survivorPuzzleId,
                mergedName: $mergedName,
                mergedEan: $mergedEan !== '' ? $mergedEan : null,
                mergedIdentificationNumber: $mergedIdentificationNumber !== '' ? $mergedIdentificationNumber : null,
                mergedPiecesCount: $mergedPiecesCount,
                mergedManufacturerId: $mergedManufacturerId !== '' ? $mergedManufacturerId : null,
                selectedImagePuzzleId: $selectedImagePuzzleId !== '' ? $selectedImagePuzzleId : null,
            ),
        );

        $this->addFlash('success', $this->translator->trans('admin.puzzle_merge_request.approved'));

        return $this->redirectToRoute('admin_puzzle_merge_requests');
    }
}
