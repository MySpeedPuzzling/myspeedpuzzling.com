<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Message\UnpublishOrganization;
use SpeedPuzzling\Web\Repository\OrganizationRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Back to draft (UnpublishOrganization) - always allowed: a draft organization hides only its own page, directory
 * entry and "Organized by" links, never its series or events.
 */
final class UnpublishOrganizationController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly OrganizationRepository $organizationRepository,
    ) {
    }

    #[Route(
        path: '/internal-api/organizations/{organizationId}/unpublish',
        requirements: ['organizationId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['POST'],
    )]
    public function __invoke(string $organizationId): Response
    {
        $organization = $this->organizationRepository->get($organizationId);

        $this->messageBus->dispatch(new UnpublishOrganization($organization->id->toString()));

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
