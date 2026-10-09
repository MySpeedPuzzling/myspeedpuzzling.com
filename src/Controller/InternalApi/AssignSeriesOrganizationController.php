<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Message\AssignEventToOrganization;
use SpeedPuzzling\Web\Query\GetAdminOrganizations;
use SpeedPuzzling\Web\Query\GetAdminSeries;
use SpeedPuzzling\Web\Repository\CompetitionSeriesRepository;
use SpeedPuzzling\Web\Value\OrganizationItemKind;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Moves a series into an organization (`{"organizationId": "…"}`) or out of it (`null`) - AssignEventToOrganization as
 * the reviewer player (an admin): a pending series moved under an approved organization is approved at once
 * (OrganizationApprovalPolicy); moving out keeps its approval. Its editions belong to its organization with it.
 */
final class AssignSeriesOrganizationController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly CompetitionSeriesRepository $competitionSeriesRepository,
        private readonly GetAdminSeries $getAdminSeries,
        private readonly GetAdminOrganizations $getAdminOrganizations,
        #[Autowire(env: 'INTERNAL_API_REVIEWER_PLAYER_ID')]
        private readonly string $reviewerPlayerId,
    ) {
    }

    #[Route(
        path: '/internal-api/series/{seriesId}/organization',
        requirements: ['seriesId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['PUT'],
    )]
    public function __invoke(string $seriesId, Request $request): JsonResponse
    {
        if ($this->reviewerPlayerId === '') {
            throw new BadRequestHttpException('INTERNAL_API_REVIEWER_PLAYER_ID is not configured - no player to act as.');
        }

        $series = $this->competitionSeriesRepository->get($seriesId);

        $input = InternalApiInput::fromRequest($request, ['organizationId']);
        $organizationId = $input->id('organizationId');

        if ($input->has('organizationId') === false) {
            $input->addError('organizationId', 'is required - an organization id, or null to take the series out of its organization.');
        }

        if ($organizationId !== null && $this->getAdminOrganizations->exists($organizationId) === false) {
            $input->addError('organizationId', 'is no organization.');
        }

        $input->throwIfInvalid();

        $this->messageBus->dispatch(new AssignEventToOrganization(
            kind: OrganizationItemKind::Series,
            itemId: $series->id->toString(),
            organizationId: $organizationId,
            actingPlayerId: $this->reviewerPlayerId,
        ));

        return new JsonResponse($this->getAdminSeries->detail($series->id->toString())->toArray());
    }
}
