<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Message\ApproveOrganization;
use SpeedPuzzling\Web\Query\GetAdminOrganizations;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Approves a pending organization like the admin approval queue does, credited to the reviewer player - and with it
 * its pending series and one-time events (docs/features/organizations/README.md, P2). Its creator gets the "approved"
 * e-mail unless that is the reviewer. Refused (409) for an approved or rejected one.
 */
final class ApproveOrganizationController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly GetAdminOrganizations $getAdminOrganizations,
        #[Autowire(env: 'INTERNAL_API_REVIEWER_PLAYER_ID')]
        private readonly string $reviewerPlayerId,
    ) {
    }

    #[Route(
        path: '/internal-api/organizations/{organizationId}/approve',
        requirements: ['organizationId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['POST'],
    )]
    public function __invoke(string $organizationId): Response
    {
        if ($this->reviewerPlayerId === '') {
            throw new BadRequestHttpException(
                'INTERNAL_API_REVIEWER_PLAYER_ID is not configured - no player to credit the approval to.',
            );
        }

        $organization = $this->getAdminOrganizations->detail($organizationId)->organization;

        $this->messageBus->dispatch(new ApproveOrganization(
            organizationId: $organization->organizationId,
            approvedByPlayerId: $this->reviewerPlayerId,
            notifyCreator: $organization->addedByPlayerId !== strtolower($this->reviewerPlayerId),
        ));

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
