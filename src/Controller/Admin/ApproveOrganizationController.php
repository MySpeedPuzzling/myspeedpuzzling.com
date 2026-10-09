<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Admin;

use SpeedPuzzling\Web\Exceptions\OrganizationNotApprovable;
use SpeedPuzzling\Web\Message\ApproveOrganization;
use SpeedPuzzling\Web\Security\AdminAccessVoter;
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
 * Approves an organization and its pending series and one-time events (docs/features/organizations/README.md, P2).
 */
final class ApproveOrganizationController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/admin/organizations/{organizationId}/approve',
        name: 'admin_approve_organization',
        methods: ['POST'],
    )]
    #[IsGranted(AdminAccessVoter::ADMIN_ACCESS)]
    public function __invoke(Request $request, string $organizationId): Response
    {
        if ($this->isCsrfTokenValid('approve_organization_' . $organizationId, $request->request->getString('_token')) === false) {
            throw $this->createAccessDeniedException();
        }

        $profile = $this->retrieveLoggedUserProfile->getProfile();
        assert($profile !== null);

        try {
            $this->messageBus->dispatch(new ApproveOrganization(
                organizationId: $organizationId,
                approvedByPlayerId: $profile->playerId,
            ));

            $this->addFlash('success', $this->translator->trans('organization.flash.approved'));
        } catch (OrganizationNotApprovable) {
            // Approved or rejected meanwhile (another admin, a second click) - nothing to do
        }

        // The ⋯ menu returns to where it was opened (docs/features/events-page/README.md)
        $returnUrl = ReturnUrl::tryFrom($request->request->getString('return'));

        if ($returnUrl !== null) {
            return $this->redirect($returnUrl->path);
        }

        return $this->redirectToRoute('admin_competition_approvals');
    }
}
