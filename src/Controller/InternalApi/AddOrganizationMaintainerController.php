<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Message\AddOrganizationMaintainer;
use SpeedPuzzling\Web\Repository\OrganizationRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Adds a player to the organization's team (`{"playerId": "…"}`) - idempotent; its creator is on the team as its
 * creator and never gets a maintainer row. The team has the creator's rights on everything under the organization.
 */
final class AddOrganizationMaintainerController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly OrganizationRepository $organizationRepository,
        private readonly PlayerRepository $playerRepository,
    ) {
    }

    #[Route(
        path: '/internal-api/organizations/{organizationId}/maintainers',
        requirements: ['organizationId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['POST'],
    )]
    public function __invoke(string $organizationId, Request $request): Response
    {
        $organization = $this->organizationRepository->get($organizationId);

        $input = InternalApiInput::fromRequest($request, ['playerId']);
        $playerId = $input->id('playerId', required: true);
        $input->throwIfInvalid();
        assert($playerId !== null);

        // 404 for an unknown player before anything is written
        $player = $this->playerRepository->get($playerId);

        $this->messageBus->dispatch(new AddOrganizationMaintainer(
            organizationId: $organization->id->toString(),
            playerId: $player->id->toString(),
        ));

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
