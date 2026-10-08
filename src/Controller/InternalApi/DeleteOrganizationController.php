<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Message\DeleteOrganization;
use SpeedPuzzling\Web\Repository\OrganizationRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Deletes an empty organization (no series, no one-time events - 409 OrganizationNotEmpty otherwise, docs/features/
 * organizations/README.md P4). Its follows, team and redirect rows go with it.
 */
final class DeleteOrganizationController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly OrganizationRepository $organizationRepository,
    ) {
    }

    #[Route(
        path: '/internal-api/organizations/{organizationId}',
        requirements: ['organizationId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['DELETE'],
    )]
    public function __invoke(string $organizationId): Response
    {
        // 404 for an unknown organization before anything runs
        $organization = $this->organizationRepository->get($organizationId);

        $this->messageBus->dispatch(new DeleteOrganization($organization->id->toString()));

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
