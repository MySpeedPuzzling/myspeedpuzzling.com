<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Message\MoveEditionToSeries;
use SpeedPuzzling\Web\Query\GetAdminCompetitions;
use SpeedPuzzling\Web\Query\GetAdminSeries;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Services\CompetitionSlugGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Moves an edition to another series (`{"seriesId": "…", "slug": "…"?}`, MoveEditionToSeries) with its participants,
 * rounds, results and solving times. Its slug stays unless it is taken in the target series (409 - send `slug`). Its
 * old address and its rounds' results addresses answer 301 to the new ones. 409 for a one-time event and for the series
 * it is in already.
 */
final class MoveEditionController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly CompetitionRepository $competitionRepository,
        private readonly GetAdminCompetitions $getAdminCompetitions,
        private readonly GetAdminSeries $getAdminSeries,
        #[Autowire(env: 'INTERNAL_API_REVIEWER_PLAYER_ID')]
        private readonly string $reviewerPlayerId,
    ) {
    }

    #[Route(
        path: '/internal-api/competitions/{competitionId}/move',
        requirements: ['competitionId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['POST'],
    )]
    public function __invoke(string $competitionId, Request $request): JsonResponse
    {
        if ($this->reviewerPlayerId === '') {
            throw new BadRequestHttpException('INTERNAL_API_REVIEWER_PLAYER_ID is not configured - no player to act as.');
        }

        $competition = $this->competitionRepository->get($competitionId);

        $input = InternalApiInput::fromRequest($request, ['seriesId', 'slug']);
        $seriesId = $input->id('seriesId', required: true);
        $slug = $input->string('slug');

        if ($seriesId !== null && $this->getAdminSeries->exists($seriesId) === false) {
            $input->addError('seriesId', 'is no series.');
        }

        if ($slug !== null && CompetitionSlugGenerator::isValid($slug) === false) {
            $input->addError('slug', 'must be lower-case letters and digits in words joined by single hyphens, e.g. "spring-open".');
        }

        $input->throwIfInvalid();
        assert($seriesId !== null);

        $this->messageBus->dispatch(new MoveEditionToSeries(
            competitionId: $competition->id->toString(),
            targetSeriesId: $seriesId,
            actingPlayerId: $this->reviewerPlayerId,
            newSlug: $slug,
        ));

        return new JsonResponse($this->getAdminCompetitions->detail($competition->id->toString())->toArray());
    }
}
