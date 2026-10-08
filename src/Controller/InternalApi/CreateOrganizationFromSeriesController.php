<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\EventSubscriber\InternalApiAuditSubscriber;
use SpeedPuzzling\Web\Message\CreateOrganizationFromSeries;
use SpeedPuzzling\Web\Query\GetAdminOrganizations;
use SpeedPuzzling\Web\Repository\CompetitionSeriesRepository;
use SpeedPuzzling\Web\Services\CompetitionSlugGenerator;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\OrganizationKind;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Turns a series into an organization (CreateOrganizationFromSeries) - **approved at once** (the reviewer player is an
 * admin), created by the series' creator. Copied from the series: logo, about (its description), website (its link),
 * country and region (its location) unless sent, the team (its maintainers). The series' followers follow the
 * organization; the series is attached to it - with `newSeriesName` / `newSeriesSlug` renamed, and with a new slug its
 * old address answers 301 to the organization and its editions' old addresses to where they are now. The organization
 * may take the series' old slug (`slug`) - organizations and series have separate addresses. Social links and the rest
 * follow with PATCH /internal-api/organizations/{id}.
 */
final class CreateOrganizationFromSeriesController extends AbstractController
{
    private const array FIELDS = ['name', 'shortName', 'slug', 'kind', 'countryCode', 'region', 'newSeriesName', 'newSeriesSlug'];

    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly CompetitionSeriesRepository $competitionSeriesRepository,
        private readonly GetAdminOrganizations $getAdminOrganizations,
        #[Autowire(env: 'INTERNAL_API_REVIEWER_PLAYER_ID')]
        private readonly string $reviewerPlayerId,
    ) {
    }

    #[Route(
        path: '/internal-api/series/{seriesId}/create-organization',
        requirements: ['seriesId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['POST'],
    )]
    public function __invoke(string $seriesId, Request $request): JsonResponse
    {
        if ($this->reviewerPlayerId === '') {
            throw new BadRequestHttpException('INTERNAL_API_REVIEWER_PLAYER_ID is not configured - no player to act as.');
        }

        $series = $this->competitionSeriesRepository->get($seriesId);
        $input = InternalApiInput::fromRequest($request, self::FIELDS);

        $name = $input->string('name', required: true, maxLength: 120);
        $shortName = $input->string('shortName', maxLength: 30);
        $region = $input->string('region', maxLength: 120);
        $newSeriesName = $input->string('newSeriesName', maxLength: 250);
        $slug = $input->string('slug');
        $newSeriesSlug = $input->string('newSeriesSlug');

        foreach (['slug' => $slug, 'newSeriesSlug' => $newSeriesSlug] as $field => $value) {
            if ($value !== null && CompetitionSlugGenerator::isValid($value) === false) {
                $input->addError($field, 'must be lower-case letters and digits in words joined by single hyphens, e.g. "riverbend-jigsaw".');
            }
        }

        $kind = null;
        $kindValue = $input->string('kind');

        if ($kindValue !== null) {
            $kind = OrganizationKind::tryFrom($kindValue);

            if ($kind === null) {
                $input->addError('kind', sprintf(
                    'must be one of: %s - or null.',
                    implode(', ', array_map(static fn (OrganizationKind $case): string => $case->value, OrganizationKind::cases())),
                ));
            }
        }

        $countryCode = $input->string('countryCode');
        $country = CountryCode::fromCode($countryCode);

        if ($countryCode !== null && $country === null) {
            $input->addError('countryCode', 'must be an ISO 3166-1 alpha-2 country code, e.g. "us".');
        }

        $input->throwIfInvalid();
        assert($name !== null);

        $organizationId = Uuid::uuid7();

        $this->messageBus->dispatch(new CreateOrganizationFromSeries(
            seriesId: $series->id->toString(),
            organizationId: $organizationId,
            actingPlayerId: $this->reviewerPlayerId,
            name: $name,
            shortName: $shortName,
            slug: $slug,
            kind: $kind,
            countryCode: $country?->name,
            region: $region,
            approve: true,
            newSeriesName: $newSeriesName,
            newSeriesSlug: $newSeriesSlug,
        ));

        $request->attributes->set(InternalApiAuditSubscriber::CREATED_ID_ATTRIBUTE, $organizationId->toString());

        return new JsonResponse(
            $this->getAdminOrganizations->detail($organizationId->toString())->toArray(),
            Response::HTTP_CREATED,
        );
    }
}
