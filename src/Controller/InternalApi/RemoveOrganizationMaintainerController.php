<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Message\RemoveOrganizationMaintainer;
use SpeedPuzzling\Web\Repository\OrganizationRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Removes a player from the organization's team - idempotent (a player not on the team is a 204 too). The creator is
 * not a maintainer row and stays on the team.
 */
final class RemoveOrganizationMaintainerController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly OrganizationRepository $organizationRepository,
    ) {
    }

    #[Route(
        path: '/internal-api/organizations/{organizationId}/maintainers/{playerId}',
        requirements: [
            'organizationId' => FirstTryConflictsController::ID_REQUIREMENT,
            'playerId' => FirstTryConflictsController::ID_REQUIREMENT,
        ],
        methods: ['DELETE'],
    )]
    public function __invoke(string $organizationId, string $playerId): Response
    {
        $organization = $this->organizationRepository->get($organizationId);

        $this->messageBus->dispatch(new RemoveOrganizationMaintainer(
            organizationId: $organization->id->toString(),
            playerId: strtolower($playerId),
        ));

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
