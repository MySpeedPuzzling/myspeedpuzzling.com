<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Exceptions\CannotUnpublish;
use SpeedPuzzling\Web\Exceptions\OrganizationNotManaged;
use SpeedPuzzling\Web\Exceptions\OrganizationOnEdition;
use SpeedPuzzling\Web\FormData\CompetitionFormData;
use SpeedPuzzling\Web\Message\AssignEventToOrganization;
use SpeedPuzzling\Web\Message\EditCompetition;
use SpeedPuzzling\Web\Message\PublishCompetition;
use SpeedPuzzling\Web\Message\UnpublishCompetition;
use SpeedPuzzling\Web\Query\GetAdminCompetitions;
use SpeedPuzzling\Web\Query\GetAdminOrganizations;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\OrganizationRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\Drafts\UnpublishBlockers;
use SpeedPuzzling\Web\Value\OrganizationItemKind;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Changes only the fields sent - the rest stays as it is (null or "" clears a field).
 *
 * **The slug stays** when the name changes, like in the web form: published links and search engines know the
 * competition by it. Only an explicit `slug` changes it (unique, else 409) - the web edit form's "URL" field.
 *
 * `organizationId` moves a one-time event into an organization or out of it (null) - AssignEventToOrganization, an
 * edition refuses one (409, it is its series'). `draft` publishes (false) or takes it back to draft (true, 409 while
 * people joined it or results / solving times are linked to it). Both are checked before anything is written.
 */
final class UpdateCompetitionController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly ValidatorInterface $validator,
        private readonly CompetitionRepository $competitionRepository,
        private readonly GetAdminCompetitions $getAdminCompetitions,
        private readonly GetAdminOrganizations $getAdminOrganizations,
        private readonly UnpublishBlockers $unpublishBlockers,
        private readonly OrganizationRepository $organizationRepository,
        private readonly PlayerRepository $playerRepository,
        #[Autowire(env: 'INTERNAL_API_REVIEWER_PLAYER_ID')]
        private readonly string $reviewerPlayerId,
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
        $input = InternalApiInput::fromRequest($request, [...CompetitionInput::FIELDS, 'organizationId', 'draft']);

        $data = CompetitionFormData::fromCompetition($competition);
        // An edition belongs to a recurring series - its dates are optional, like the series' own
        $data->isRecurring = $competition->series !== null;

        ['slug' => $slug, 'maintainerIds' => $maintainerIds] = CompetitionInput::applyTo($input, $data);

        if ($input->has('slug') && $slug === null && $input->hasError('slug') === false) {
            $input->addError('slug', 'cannot be cleared - leave it out to keep the slug, or send a new one.');
        }

        $organizationId = $input->id('organizationId');
        $changesOrganization = $input->has('organizationId') && $organizationId !== $competition->organization?->id->toString();

        if ($organizationId !== null && $this->getAdminOrganizations->exists($organizationId) === false) {
            $input->addError('organizationId', 'is no organization.');
        }

        $draft = $input->bool('draft');
        $changesDraft = $draft !== null && $draft !== $competition->isDraft;

        // Only the fields sent are checked: a record stored before a rule existed (a span over 30 days, say) does not
        // block a change of its organization or draft state - but a change of its fields validates the whole record
        $editsFields = array_any(CompetitionInput::FIELDS, static fn (string $field): bool => $input->has($field));

        if ($editsFields) {
            // An edition's place is its series' - the form's "an in-person event needs a location" is the series' rule
            $input->addViolations($this->validator->validate($data), $competition->series !== null ? ['location'] : []);
        }

        $input->throwIfInvalid();

        if ($changesOrganization && $organizationId !== null && $competition->series !== null) {
            throw new OrganizationOnEdition();
        }

        // Publishing and unpublishing need no acting player
        if ($changesOrganization && $this->reviewerPlayerId === '') {
            throw new BadRequestHttpException('INTERNAL_API_REVIEWER_PLAYER_ID is not configured - no player to act as.');
        }

        // The reviewer must be able to move it there - checked before anything is saved (AssignEventToOrganization
        // refuses too, but after the fields would have been saved)
        if ($changesOrganization && $organizationId !== null) {
            $organization = $this->organizationRepository->get($organizationId);

            if ($organization->isManagedBy($this->playerRepository->get($this->reviewerPlayerId)) === false) {
                throw new OrganizationNotManaged();
            }
        }

        if ($changesDraft && $draft === true) {
            $check = $this->unpublishBlockers->forCompetition($competition->id->toString());

            if ($check->allowed() === false) {
                throw new CannotUnpublish($check->blockers());
            }
        }

        if ($editsFields) {
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
                eligibility: $data->eligibility,
                slug: $slug !== $competition->slug ? $slug : null,
            ));
        }

        if ($changesOrganization) {
            $this->messageBus->dispatch(new AssignEventToOrganization(
                kind: OrganizationItemKind::Competition,
                itemId: $competition->id->toString(),
                organizationId: $organizationId,
                actingPlayerId: $this->reviewerPlayerId,
            ));
        }

        if ($changesDraft) {
            $this->messageBus->dispatch($draft === true
                ? new UnpublishCompetition($competition->id->toString())
                : new PublishCompetition($competition->id->toString(), notifyAdmin: false));
        }

        return new JsonResponse($this->getAdminCompetitions->detail($competition->id->toString())->toArray());
    }
}
