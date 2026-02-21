<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Admin;

use SpeedPuzzling\Web\Message\ApprovePuzzleChangeRequest;
use SpeedPuzzling\Web\Security\AdminAccessVoter;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ApprovePuzzleChangeRequestController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/admin/puzzle-change-requests/{id}/approve',
        name: 'admin_approve_puzzle_change_request',
        methods: ['POST'],
    )]
    #[IsGranted(AdminAccessVoter::ADMIN_ACCESS)]
    public function __invoke(string $id, Request $request): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();

        if ($player === null) {
            throw $this->createAccessDeniedException();
        }

        $allFields = ['name', 'manufacturer', 'piecesCount', 'ean', 'identificationNumber', 'image'];
        $selectedFields = [];

        foreach ($allFields as $field) {
            if ($request->request->get('field_' . $field) !== null) {
                $selectedFields[] = $field;
            }
        }

        $overrides = [];

        foreach (['name', 'piecesCount', 'ean', 'identificationNumber'] as $field) {
            if (!in_array($field, $selectedFields, true)) {
                continue;
            }

            $value = $request->request->get('override_' . $field);

            if (!is_string($value) || $value === '') {
                continue;
            }

            if ($field === 'piecesCount') {
                $overrides[$field] = (int) $value;
            } else {
                $overrides[$field] = $value;
            }
        }

        $this->messageBus->dispatch(
            new ApprovePuzzleChangeRequest(
                changeRequestId: $id,
                reviewerId: $player->playerId,
                selectedFields: $selectedFields,
                overrides: $overrides,
            ),
        );

        $this->addFlash('success', $this->translator->trans('admin.puzzle_change_request.approved'));

        return $this->redirectToRoute('admin_puzzle_change_requests');
    }
}
