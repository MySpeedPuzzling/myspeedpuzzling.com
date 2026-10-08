<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Drafts;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Message\PublishOrganization;
use SpeedPuzzling\Web\Repository\OrganizationRepository;
use SpeedPuzzling\Web\Security\OrganizationEditVoter;
use SpeedPuzzling\Web\Value\ReturnUrl;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Publish a draft organization (docs/features/organizations/README.md "Drafts"). Always a redirect: to `return`, else
 * the organization's page.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class PublishOrganizationController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
        readonly private OrganizationRepository $organizationRepository,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/{_locale}/publish-organization/{organizationId}',
        name: 'publish_organization',
        requirements: ['organizationId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['POST'],
    )]
    public function __invoke(Request $request, string $organizationId): Response
    {
        $this->denyAccessUnlessGranted(OrganizationEditVoter::ORGANIZATION_EDIT, $organizationId);

        if ($this->isCsrfTokenValid('publish_organization_' . $organizationId, $request->request->getString('_token')) === false) {
            throw $this->createAccessDeniedException();
        }

        $this->messageBus->dispatch(new PublishOrganization($organizationId));

        $organization = $this->organizationRepository->get($organizationId);
        $approved = $organization->isApproved() && $organization->isRejected() === false;

        $this->addFlash('success', $this->translator->trans($approved ? 'drafts_core.flash.published' : 'drafts_core.flash.published_waiting'));

        $returnUrl = ReturnUrl::tryFrom($request->request->getString('return'));

        return $returnUrl !== null
            ? $this->redirect($returnUrl->path)
            : $this->redirectToRoute('organization_detail', ['slug' => $organization->slug]);
    }
}
