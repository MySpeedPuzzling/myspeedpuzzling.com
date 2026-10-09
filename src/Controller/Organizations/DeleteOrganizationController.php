<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Organizations;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Exceptions\OrganizationNotEmpty;
use SpeedPuzzling\Web\Message\DeleteOrganization;
use SpeedPuzzling\Web\Security\OrganizationDeleteVoter;
use SpeedPuzzling\Web\Value\ReturnUrl;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Deletes an empty organization (its creator, admins - docs/features/organizations/README.md, P4). One still holding
 * series or events comes back with a flash. Always a redirect.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class DeleteOrganizationController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/{_locale}/delete-organization/{organizationId}',
        name: 'delete_organization',
        requirements: ['organizationId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['POST'],
    )]
    public function __invoke(Request $request, string $organizationId): Response
    {
        $this->denyAccessUnlessGranted(OrganizationDeleteVoter::ORGANIZATION_DELETE, $organizationId);

        if ($this->isCsrfTokenValid('delete_organization_' . $organizationId, $request->request->getString('_token')) === false) {
            throw $this->createAccessDeniedException();
        }

        $returnUrl = ReturnUrl::tryFrom($request->request->getString('return'));

        try {
            $this->messageBus->dispatch(new DeleteOrganization($organizationId));
        } catch (OrganizationNotEmpty) {
            $this->addFlash('warning', $this->translator->trans('organization.flash.not_empty'));

            return $returnUrl !== null ? $this->redirect($returnUrl->path) : $this->redirectToRoute('organized_events');
        }

        $this->addFlash('success', $this->translator->trans('organization.flash.deleted'));

        return $returnUrl !== null ? $this->redirect($returnUrl->path) : $this->redirectToRoute('organized_events');
    }
}
