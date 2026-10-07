<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\FormData\CompetitionFormData;
use SpeedPuzzling\Web\Message\EditCompetition;
use SpeedPuzzling\Web\Query\GetAdminCompetitions;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Changes only the fields sent - the rest stays as it is (null or "" clears a field).
 *
 * **The slug stays** when the name changes, like in the web form: published links and search engines know the
 * competition by it. Only an explicit `slug` changes it (unique, else 409) - the web edit form's "URL" field.
 */
final class UpdateCompetitionController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly ValidatorInterface $validator,
        private readonly CompetitionRepository $competitionRepository,
        private readonly GetAdminCompetitions $getAdminCompetitions,
    ) {
    }

    #[Route(
        path: '/internal-api/competitions/{competitionId}',
        requirements: ['competitionId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['PATCH'],
    )]
    public function __invoke(string $competitionId, Request $request): JsonResponse
    {
        $competition = $this->competitionRepository->get($competitionId);
        $input = InternalApiInput::fromRequest($request, CompetitionInput::FIELDS);

        $data = CompetitionFormData::fromCompetition($competition);
        // An edition belongs to a recurring series - its dates are optional, like the series' own
        $data->isRecurring = $competition->series !== null;

        ['slug' => $slug, 'maintainerIds' => $maintainerIds] = CompetitionInput::applyTo($input, $data);

        if ($input->has('slug') && $slug === null && $input->hasError('slug') === false) {
            $input->addError('slug', 'cannot be cleared - leave it out to keep the slug, or send a new one.');
        }

        // An edition's place is its series' - the form's "an in-person event needs a location" is the series' rule
        $input->addViolations($this->validator->validate($data), $competition->series !== null ? ['location'] : []);
        $input->throwIfInvalid();

        $this->messageBus->dispatch(new EditCompetition(
            competitionId: $competition->id->toString(),
            name: $data->name ?? $competition->name,
            shortcut: $data->shortcut,
            description: $data->description,
            link: $data->link,
            registrationLink: $data->registrationLink,
            resultsLink: $data->resultsLink,
            location: $data->location,
            locationCountryCode: $data->locationCountryCode,
            dateFrom: $data->dateFrom,
            dateTo: $data->dateTo,
            isOnline: $data->isOnline === true,
            logo: null,
            maintainerIds: $maintainerIds ?? $data->maintainers,
            slug: $slug !== $competition->slug ? $slug : null,
            // Managed registration (PR #136) is not part of the API input - kept as the competition has it
            // PORT-TODO: decide whether the internal API exposes the registration settings
            registrationManaged: $data->registrationManaged,
            capacity: $data->capacity,
            registrationOpensAt: $data->registrationOpensAt,
            registrationClosesAt: $data->registrationClosesAt,
            entryFeeText: $data->entryFeeText,
            paymentInstructions: $data->paymentInstructions,
        ));

        return new JsonResponse($this->getAdminCompetitions->detail($competition->id->toString())->toArray());
    }
}
