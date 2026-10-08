<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\EventSubscriber\InternalApiAuditSubscriber;
use SpeedPuzzling\Web\FormData\EditionFormData;
use SpeedPuzzling\Web\Message\AddEdition;
use SpeedPuzzling\Web\Query\GetAdminCompetitions;
use SpeedPuzzling\Web\Repository\CompetitionSeriesRepository;
use SpeedPuzzling\Web\Services\CompetitionSlugGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Adds an edition to a series like "Add edition" does (AddEdition): validated by the form's rules (EditionFormData -
 * name and both dates required, links that are URLs, "Who can enter" ≤ 120 characters), at the series' place, approved
 * through its series. `slug` (unique in the series and among one-time events, else 409) - else generated from the name.
 * `"draft": true` creates it as a draft. Its rounds follow through POST /internal-api/competitions/{id}/rounds.
 */
final class CreateEditionController extends AbstractController
{
    private const array FIELDS = [
        'name',
        'slug',
        'dateFrom',
        'dateTo',
        'link',
        'registrationLink',
        'resultsLink',
        'description',
        'eligibility',
        'draft',
    ];

    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly ValidatorInterface $validator,
        private readonly CompetitionSeriesRepository $competitionSeriesRepository,
        private readonly GetAdminCompetitions $getAdminCompetitions,
    ) {
    }

    #[Route(
        path: '/internal-api/series/{seriesId}/editions',
        requirements: ['seriesId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['POST'],
    )]
    public function __invoke(string $seriesId, Request $request): JsonResponse
    {
        $series = $this->competitionSeriesRepository->get($seriesId);
        $input = InternalApiInput::fromRequest($request, self::FIELDS);

        $data = new EditionFormData(
            name: $input->string('name'),
            dateFrom: $input->date('dateFrom'),
            dateTo: $input->date('dateTo'),
            registrationLink: $input->string('registrationLink'),
            resultsLink: $input->string('resultsLink'),
            link: $input->string('link'),
            description: $input->string('description'),
            eligibility: $input->string('eligibility'),
        );
        $slug = $input->string('slug');
        $isDraft = $input->bool('draft') ?? false;

        if ($slug !== null && CompetitionSlugGenerator::isValid($slug) === false) {
            $input->addError('slug', 'must be lower-case letters and digits in words joined by single hyphens, e.g. "lantern-night-2026-11-02".');
        }

        if ($data->dateFrom !== null && $data->dateTo !== null && $data->dateTo < $data->dateFrom) {
            $input->addError('dateTo', 'must not be before dateFrom.');
        }

        $input->addViolations($this->validator->validate($data));
        $input->throwIfInvalid();

        $competitionId = Uuid::uuid7();

        $this->messageBus->dispatch(new AddEdition(
            competitionId: $competitionId,
            seriesId: $series->id->toString(),
            name: $data->name ?? '',
            dateFrom: $data->dateFrom,
            dateTo: $data->dateTo,
            registrationLink: $data->registrationLink,
            resultsLink: $data->resultsLink,
            link: $data->link,
            description: $data->description,
            eligibility: $data->eligibility,
            isDraft: $isDraft,
            slug: $slug,
        ));

        $request->attributes->set(InternalApiAuditSubscriber::CREATED_ID_ATTRIBUTE, $competitionId->toString());

        return new JsonResponse(
            $this->getAdminCompetitions->detail($competitionId->toString())->toArray(),
            Response::HTTP_CREATED,
        );
    }
}
