<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Drafts;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Message\UnpublishOrganization;
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
 * An organization back to draft - always allowed (it hides only its own page). Always a redirect.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class UnpublishOrganizationController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
        readonly private OrganizationRepository $organizationRepository,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/{_locale}/unpublish-organization/{organizationId}',
        name: 'unpublish_organization',
        requirements: ['organizationId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['POST'],
    )]
    public function __invoke(Request $request, string $organizationId): Response
    {
        $this->denyAccessUnlessGranted(OrganizationEditVoter::ORGANIZATION_EDIT, $organizationId);

        if ($this->isCsrfTokenValid('unpublish_organization_' . $organizationId, $request->request->getString('_token')) === false) {
            throw $this->createAccessDeniedException();
        }

        $this->messageBus->dispatch(new UnpublishOrganization($organizationId));
        $this->addFlash('success', $this->translator->trans('drafts_core.flash.unpublished'));

        $returnUrl = ReturnUrl::tryFrom($request->request->getString('return'));

        return $returnUrl !== null
            ? $this->redirect($returnUrl->path)
            : $this->redirectToRoute('organization_detail', ['slug' => $this->organizationRepository->get($organizationId)->slug]);
    }
}
