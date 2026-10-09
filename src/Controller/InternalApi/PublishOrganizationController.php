<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Message\PublishOrganization;
use SpeedPuzzling\Web\Repository\OrganizationRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The organization's draft flag goes off (PublishOrganization) - one still waiting for approval enters the approval
 * queue then. Publishing a published organization changes nothing.
 */
final class PublishOrganizationController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly OrganizationRepository $organizationRepository,
    ) {
    }

    #[Route(
        path: '/internal-api/organizations/{organizationId}/publish',
        requirements: ['organizationId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['POST'],
    )]
    public function __invoke(string $organizationId): Response
    {
        $organization = $this->organizationRepository->get($organizationId);

        $this->messageBus->dispatch(new PublishOrganization($organization->id->toString(), notifyAdmin: false));

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
