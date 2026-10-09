<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Message\AssignEventToOrganization;
use SpeedPuzzling\Web\Query\GetAdminCompetitions;
use SpeedPuzzling\Web\Query\GetAdminOrganizations;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Value\OrganizationItemKind;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Moves a one-time event into an organization (`{"organizationId": "…"}`) or out of it (`null`) -
 * AssignEventToOrganization as the reviewer player (an admin): a pending event moved under an approved organization is
 * approved at once; moving out keeps its approval. An edition refuses an organization (409 - it is its series').
 */
final class AssignCompetitionOrganizationController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly CompetitionRepository $competitionRepository,
        private readonly GetAdminCompetitions $getAdminCompetitions,
        private readonly GetAdminOrganizations $getAdminOrganizations,
        #[Autowire(env: 'INTERNAL_API_REVIEWER_PLAYER_ID')]
        private readonly string $reviewerPlayerId,
    ) {
    }

    #[Route(
        path: '/internal-api/competitions/{competitionId}/organization',
        requirements: ['competitionId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['PUT'],
    )]
    public function __invoke(string $competitionId, Request $request): JsonResponse
    {
        if ($this->reviewerPlayerId === '') {
            throw new BadRequestHttpException('INTERNAL_API_REVIEWER_PLAYER_ID is not configured - no player to act as.');
        }

        $competition = $this->competitionRepository->get($competitionId);

        $input = InternalApiInput::fromRequest($request, ['organizationId']);
        $organizationId = $input->id('organizationId');

        if ($input->has('organizationId') === false) {
            $input->addError('organizationId', 'is required - an organization id, or null to take the event out of its organization.');
        }

        if ($organizationId !== null && $this->getAdminOrganizations->exists($organizationId) === false) {
            $input->addError('organizationId', 'is no organization.');
        }

        $input->throwIfInvalid();

        $this->messageBus->dispatch(new AssignEventToOrganization(
            kind: OrganizationItemKind::Competition,
            itemId: $competition->id->toString(),
            organizationId: $organizationId,
            actingPlayerId: $this->reviewerPlayerId,
        ));

        return new JsonResponse($this->getAdminCompetitions->detail($competition->id->toString())->toArray());
    }
}
