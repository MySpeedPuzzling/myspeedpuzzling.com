<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\EventSubscriber\InternalApiAuditSubscriber;
use SpeedPuzzling\Web\FormData\CompetitionFormData;
use SpeedPuzzling\Web\Message\AddCompetitionSeries;
use SpeedPuzzling\Web\Message\ApproveCompetitionSeries;
use SpeedPuzzling\Web\Query\GetAdminOrganizations;
use SpeedPuzzling\Web\Query\GetAdminSeries;
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
 * Creates a series like "Add event" with "Recurring" ticked does, added by the reviewer player. Under an approved
 * organization (`organizationId`) it is approved at once - the reviewer player is an admin (OrganizationApprovalPolicy,
 * docs/features/organizations/README.md "Approval"); elsewhere `"approve": true` approves it. Nobody is e-mailed.
 * `"draft": true` creates it as a draft (it and its editions hidden until it is published). Its editions follow through
 * POST /internal-api/series/{seriesId}/editions.
 */
final class CreateSeriesController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly ValidatorInterface $validator,
        private readonly GetAdminSeries $getAdminSeries,
        private readonly GetAdminOrganizations $getAdminOrganizations,
        #[Autowire(env: 'INTERNAL_API_REVIEWER_PLAYER_ID')]
        private readonly string $reviewerPlayerId,
    ) {
    }

    #[Route(
        path: '/internal-api/series',
        methods: ['POST'],
    )]
    public function __invoke(Request $request): JsonResponse
    {
        if ($this->reviewerPlayerId === '') {
            throw new BadRequestHttpException(
                'INTERNAL_API_REVIEWER_PLAYER_ID is not configured - no player to add the series as.',
            );
        }

        $input = InternalApiInput::fromRequest($request, [...SeriesInput::FIELDS, 'organizationId', 'draft', 'approve']);

        $data = new CompetitionFormData(isOnline: false, isRecurring: true);
        ['slug' => $slug, 'maintainerIds' => $maintainerIds] = SeriesInput::applyTo($input, $data);
        $organizationId = $input->id('organizationId');
        $isDraft = $input->bool('draft') ?? false;
        $approve = $input->bool('approve') ?? false;

        if ($organizationId !== null && $this->getAdminOrganizations->exists($organizationId) === false) {
            $input->addError('organizationId', 'is no organization.');
        }

        $input->addViolations($this->validator->validate($data));
        $input->throwIfInvalid();

        $seriesId = Uuid::uuid7();

        $this->messageBus->dispatch(new AddCompetitionSeries(
            seriesId: $seriesId,
            playerId: $this->reviewerPlayerId,
            name: $data->name ?? '',
            shortcut: $data->shortcut,
            description: $data->description,
            link: $data->link,
            isOnline: $data->isOnline === true,
            location: $data->location,
            locationCountryCode: $data->locationCountryCode,
            logo: null,
            maintainerIds: $maintainerIds ?? [],
            slug: $slug,
            organizationId: $organizationId,
            eligibility: $data->eligibility,
            schedule: $data->schedule,
            isDraft: $isDraft,
            // An admin creates it - nobody has to be asked to review it
            notifyAdmin: false,
        ));

        $request->attributes->set(InternalApiAuditSubscriber::CREATED_ID_ATTRIBUTE, $seriesId->toString());

        // Not when an approved organization's policy approved it already
        if ($approve && $this->getAdminSeries->detail($seriesId->toString())->series->status() === 'pending') {
            $this->messageBus->dispatch(new ApproveCompetitionSeries(
                seriesId: $seriesId->toString(),
                approvedByPlayerId: $this->reviewerPlayerId,
                // The reviewer player created it - nobody to tell
                notifyCreator: false,
            ));
        }

        return new JsonResponse($this->getAdminSeries->detail($seriesId->toString())->toArray(), Response::HTTP_CREATED);
    }
}
