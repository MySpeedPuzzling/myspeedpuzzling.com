<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Admin;

use SpeedPuzzling\Web\Exceptions\InvalidPuzzleApproval;
use SpeedPuzzling\Web\Exceptions\PuzzleAlreadyApproved;
use SpeedPuzzling\Web\Message\ApprovePuzzle;
use SpeedPuzzling\Web\Security\PuzzleModerationVoter;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\PuzzleApprovalBrandChoice;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ApprovePuzzleController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/admin/puzzle-approvals/{puzzleId}/approve',
        name: 'admin_approve_puzzle',
        methods: ['POST'],
    )]
    #[IsGranted(PuzzleModerationVoter::PUZZLE_MODERATION_ACCESS)]
    public function __invoke(string $puzzleId, Request $request): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();

        if ($player === null) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid('approve-puzzle-' . $puzzleId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $targetManufacturerId = $request->request->getString('target_manufacturer_id');
        $brandChoice = PuzzleApprovalBrandChoice::tryFrom($request->request->getString('brand_choice'));

        // A puzzle whose brand is already approved has no brand radios - picking a
        // different brand in the one brand select simply moves it there
        if ($brandChoice === null) {
            $brandChoice = $targetManufacturerId !== '' && $targetManufacturerId !== $request->request->getString('current_manufacturer_id')
                ? PuzzleApprovalBrandChoice::UseExisting
                : PuzzleApprovalBrandChoice::Keep;
        }

        try {
            $this->messageBus->dispatch(new ApprovePuzzle(
                puzzleId: $puzzleId,
                reviewerId: $player->playerId,
                name: $request->request->getString('name'),
                piecesCount: $request->request->getInt('pieces_count'),
                ean: $request->request->getString('ean'),
                identificationNumber: $request->request->getString('identification_number'),
                brandChoice: $brandChoice,
                targetManufacturerId: $targetManufacturerId !== '' ? $targetManufacturerId : null,
            ));
        } catch (HandlerFailedException $exception) {
            $previous = $exception->getPrevious();

            if ($previous instanceof PuzzleAlreadyApproved) {
                $this->addFlash('warning', $this->translator->trans('admin.puzzle_approval.already_approved'));

                return $this->redirectToRoute('admin_puzzle_approvals');
            }

            if ($previous instanceof InvalidPuzzleApproval) {
                $this->addFlash('error', $previous->getMessage());

                return $this->redirectToRoute('admin_puzzle_approval_detail', ['puzzleId' => $puzzleId]);
            }

            throw $exception;
        }

        $this->addFlash('success', $this->translator->trans('admin.puzzle_approval.approved'));

        return $this->redirectToRoute('admin_puzzle_approvals');
    }
}
