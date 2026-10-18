<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\EventSubscriber\InternalApiAuditSubscriber;
use SpeedPuzzling\Web\FormData\CompetitionFormData;
use SpeedPuzzling\Web\Message\DuplicateCompetition;
use SpeedPuzzling\Web\Query\GetAdminCompetitions;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Value\DuplicateDateShift;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Duplicates a one-time event or an edition like the organiser's "Duplicate" does (DuplicateCompetition, docs/features/
 * competitions-management/README.md "Duplicating an event"): a new draft added by the reviewer player, with a fresh
 * slug, its details, settings, rounds and page content - never participants, results, round puzzles, tables, referees
 * or online timing. An edition's copy is a new edition of the same series.
 *
 * Optional body `{"name", "dateFrom", "dateTo", "location"}` overrides the copied values (validated like the edit form).
 * A new `dateFrom` moves `dateTo` (unless sent), every round's start and the registration window by the same number of
 * days. Answers 201 with the new competition.
 */
final class DuplicateCompetitionController extends AbstractController
{
    private const array FIELDS = ['name', 'dateFrom', 'dateTo', 'location'];

    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly ValidatorInterface $validator,
        private readonly CompetitionRepository $competitionRepository,
        private readonly GetAdminCompetitions $getAdminCompetitions,
        #[Autowire(env: 'INTERNAL_API_REVIEWER_PLAYER_ID')]
        private readonly string $reviewerPlayerId,
    ) {
    }

    #[Route(
        path: '/internal-api/competitions/{competitionId}/duplicate',
        requirements: ['competitionId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['POST'],
    )]
    public function __invoke(string $competitionId, Request $request): JsonResponse
    {
        if ($this->reviewerPlayerId === '') {
            throw new BadRequestHttpException('INTERNAL_API_REVIEWER_PLAYER_ID is not configured - no player to act as.');
        }

        $source = $this->competitionRepository->get($competitionId);
        $input = InternalApiInput::fromRequest($request, self::FIELDS);

        $data = CompetitionFormData::fromCompetition($source);
        // An edition belongs to a recurring series - its dates are optional, like the series' own
        $data->isRecurring = $source->series !== null;

        $name = $input->string('name');
        $dateFrom = $input->date('dateFrom');
        $dateTo = $input->date('dateTo');
        $location = $input->string('location');

        if ($input->has('name') && $name === null && $input->hasError('name') === false) {
            $input->addError('name', 'cannot be empty - leave it out to keep the name.');
        }

        $data->name = $name ?? $data->name;
        $data->dateFrom = $dateFrom ?? $data->dateFrom;
        // The handler moves the last day with the first one - validated as it will be saved
        $data->dateTo = $dateTo ?? DuplicateDateShift::between($source->dateFrom, $dateFrom)->apply($data->dateTo);

        if ($source->isOnline === false) {
            $data->location = $location ?? $data->location;
        } elseif ($location !== null) {
            $input->addError('location', 'an online event has no place.');
        }

        // Only an override is checked - a source stored before a rule existed is copied as it is
        if ($name !== null || $dateFrom !== null || $dateTo !== null || $location !== null) {
            // An edition's place is its series' - the form's "an in-person event needs a location" is the series' rule
            $input->addViolations($this->validator->validate($data), $source->series !== null ? ['location'] : []);
        }

        $input->throwIfInvalid();

        $newCompetitionId = Uuid::uuid7();

        $this->messageBus->dispatch(new DuplicateCompetition(
            newCompetitionId: $newCompetitionId,
            sourceCompetitionId: $source->id->toString(),
            actingPlayerId: $this->reviewerPlayerId,
            name: $name,
            dateFrom: $dateFrom,
            dateTo: $dateTo,
            location: $source->isOnline ? null : $location,
        ));

        $request->attributes->set(InternalApiAuditSubscriber::CREATED_ID_ATTRIBUTE, $newCompetitionId->toString());

        return new JsonResponse(
            $this->getAdminCompetitions->detail($newCompetitionId->toString())->toArray(),
            Response::HTTP_CREATED,
        );
    }
}
