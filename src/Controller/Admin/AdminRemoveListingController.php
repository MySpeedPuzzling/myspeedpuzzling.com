<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Admin;

use SpeedPuzzling\Web\Message\AdminRemoveListing;
use SpeedPuzzling\Web\Security\AdminAccessVoter;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AdminRemoveListingController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/admin/moderation/remove-listing/{itemId}',
        name: 'admin_remove_listing',
        methods: ['POST'],
    )]
    #[IsGranted(AdminAccessVoter::ADMIN_ACCESS)]
    public function __invoke(Request $request, string $itemId): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();

        if ($player === null) {
            throw $this->createAccessDeniedException();
        }

        $reason = $request->request->getString('reason');
        $reportId = $request->request->getString('report_id');

        $this->messageBus->dispatch(new AdminRemoveListing(
            sellSwapListItemId: $itemId,
            adminId: $player->playerId,
            reason: $reason !== '' ? $reason : null,
            reportId: $reportId !== '' ? $reportId : null,
        ));

        $this->addFlash('success', $this->translator->trans('moderation.listing_removed'));

        return $this->redirectToRoute('admin_moderation_dashboard');
    }
}
