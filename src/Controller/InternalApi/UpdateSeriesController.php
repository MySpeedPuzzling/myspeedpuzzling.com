<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Exceptions\CannotUnpublish;
use SpeedPuzzling\Web\Message\AssignEventToOrganization;
use SpeedPuzzling\Web\Message\EditCompetitionSeries;
use SpeedPuzzling\Web\Message\PublishCompetitionSeries;
use SpeedPuzzling\Web\Message\UnpublishCompetitionSeries;
use SpeedPuzzling\Web\Query\GetAdminOrganizations;
use SpeedPuzzling\Web\Query\GetAdminSeries;
use SpeedPuzzling\Web\Repository\CompetitionSeriesRepository;
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
 * Changes only the fields sent (EditCompetitionSeries) - null or "" clears a field, `maintainerIds` replaces the list
 * (left out or null keeps it). **A rename keeps the slug**; only an explicit `slug` changes it (unique among series,
 * else 409 - no redirect from the old one). `organizationId` moves the series into an organization or out of it (null,
 * AssignEventToOrganization); `draft` publishes (false) or takes it back to draft (true - 409 while an edition has
 * participants, official results or linked solving times). Both are checked before anything is written.
 */
final class UpdateSeriesController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly ValidatorInterface $validator,
        private readonly CompetitionSeriesRepository $competitionSeriesRepository,
        private readonly GetAdminSeries $getAdminSeries,
        private readonly GetAdminOrganizations $getAdminOrganizations,
        private readonly UnpublishBlockers $unpublishBlockers,
        #[Autowire(env: 'INTERNAL_API_REVIEWER_PLAYER_ID')]
        private readonly string $reviewerPlayerId,
    ) {
    }

    #[Route(
        path: '/internal-api/series/{seriesId}',
        requirements: ['seriesId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['PATCH'],
    )]
    public function __invoke(string $seriesId, Request $request): JsonResponse
    {
        $series = $this->competitionSeriesRepository->get($seriesId);
        $input = InternalApiInput::fromRequest($request, [...SeriesInput::FIELDS, 'organizationId', 'draft']);

        $data = SeriesInput::formDataOf($series);
        ['slug' => $slug, 'maintainerIds' => $maintainerIds] = SeriesInput::applyTo($input, $data);

        if ($input->has('slug') && $slug === null && $input->hasError('slug') === false) {
            $input->addError('slug', 'cannot be cleared - leave it out to keep the slug, or send a new one.');
        }

        $organizationId = $input->id('organizationId');
        $changesOrganization = $input->has('organizationId') && $organizationId !== $series->organization?->id->toString();

        if ($organizationId !== null && $this->getAdminOrganizations->exists($organizationId) === false) {
            $input->addError('organizationId', 'is no organization.');
        }

        $draft = $input->bool('draft');
        $changesDraft = $draft !== null && $draft !== $series->isDraft;
        $editsFields = array_any(SeriesInput::FIELDS, static fn (string $field): bool => $input->has($field));

        if ($editsFields) {
            $input->addViolations($this->validator->validate($data));
        }

        $input->throwIfInvalid();

        if ($changesOrganization && $this->reviewerPlayerId === '') {
            throw new BadRequestHttpException('INTERNAL_API_REVIEWER_PLAYER_ID is not configured - no player to act as.');
        }

        if ($changesDraft && $draft === true) {
            $check = $this->unpublishBlockers->forSeries($series->id->toString());

            if ($check->allowed() === false) {
                throw new CannotUnpublish($check->blockers());
            }
        }

        if ($editsFields) {
            $this->messageBus->dispatch(new EditCompetitionSeries(
                seriesId: $series->id->toString(),
                name: $data->name ?? $series->name,
                shortcut: $data->shortcut,
                description: $data->description,
                link: $data->link,
                isOnline: $data->isOnline === true,
                location: $data->location,
                locationCountryCode: $data->locationCountryCode,
                logo: null,
                maintainerIds: $maintainerIds ?? $data->maintainers,
                eligibility: $data->eligibility,
                schedule: $data->schedule,
                slug: $slug !== $series->slug ? $slug : null,
            ));
        }

        if ($changesOrganization) {
            $this->messageBus->dispatch(new AssignEventToOrganization(
                kind: OrganizationItemKind::Series,
                itemId: $series->id->toString(),
                organizationId: $organizationId,
                actingPlayerId: $this->reviewerPlayerId,
            ));
        }

        if ($changesDraft) {
            $this->messageBus->dispatch($draft === true
                ? new UnpublishCompetitionSeries($series->id->toString())
                : new PublishCompetitionSeries($series->id->toString()));
        }

        return new JsonResponse($this->getAdminSeries->detail($series->id->toString())->toArray());
    }
}
