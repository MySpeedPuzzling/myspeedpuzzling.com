<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\EventSubscriber\InternalApiAuditSubscriber;
use SpeedPuzzling\Web\FormData\CompetitionFormData;
use SpeedPuzzling\Web\Message\AddCompetition;
use SpeedPuzzling\Web\Message\ApproveCompetition;
use SpeedPuzzling\Web\Query\GetAdminCompetitions;
use SpeedPuzzling\Web\Query\GetAdminOrganizations;
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
 * Creates a standalone competition (past or upcoming) like "Add event" does, added by the reviewer player - and with
 * `"approve": true` approves it right away. Rounds and puzzles follow through their own endpoints.
 *
 * Under an approved organization (`organizationId`) it is approved at once anyway - the reviewer player is an admin
 * (OrganizationApprovalPolicy, docs/features/organizations/README.md "Approval"). `"draft": true` creates it as a draft:
 * only its team sees it until it is published.
 */
final class CreateCompetitionController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly ValidatorInterface $validator,
        private readonly GetAdminCompetitions $getAdminCompetitions,
        private readonly GetAdminOrganizations $getAdminOrganizations,
        #[Autowire(env: 'INTERNAL_API_REVIEWER_PLAYER_ID')]
        private readonly string $reviewerPlayerId,
    ) {
    }

    #[Route(
        path: '/internal-api/competitions',
        methods: ['POST'],
    )]
    public function __invoke(Request $request): JsonResponse
    {
        if ($this->reviewerPlayerId === '') {
            throw new BadRequestHttpException(
                'INTERNAL_API_REVIEWER_PLAYER_ID is not configured - no player to add the competition as.',
            );
        }

        $input = InternalApiInput::fromRequest($request, [...CompetitionInput::FIELDS, 'approve', 'organizationId', 'draft']);

        $data = new CompetitionFormData(isOnline: false);
        ['slug' => $slug, 'maintainerIds' => $maintainerIds] = CompetitionInput::applyTo($input, $data);
        $approve = $input->bool('approve') ?? false;
        $isDraft = $input->bool('draft') ?? false;
        $organizationId = $input->id('organizationId');

        if ($organizationId !== null && $this->getAdminOrganizations->exists($organizationId) === false) {
            $input->addError('organizationId', 'is no organization.');
        }

        $input->addViolations($this->validator->validate($data));
        $input->throwIfInvalid();

        $competitionId = Uuid::uuid7();

        $this->messageBus->dispatch(new AddCompetition(
            competitionId: $competitionId,
            playerId: $this->reviewerPlayerId,
            name: $data->name ?? '',
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
            maintainerIds: $maintainerIds ?? [],
            slug: $slug,
            // An admin creates it - nobody has to be asked to review it
            notifyAdmin: false,
            organizationId: $organizationId,
            eligibility: $data->eligibility,
            isDraft: $isDraft,
        ));

        $request->attributes->set(InternalApiAuditSubscriber::CREATED_ID_ATTRIBUTE, $competitionId->toString());

        // Not when an approved organization's policy approved it already
        if ($approve && $this->getAdminCompetitions->detail($competitionId->toString())->competition->status() === 'pending') {
            $this->messageBus->dispatch(new ApproveCompetition(
                competitionId: $competitionId->toString(),
                approvedByPlayerId: $this->reviewerPlayerId,
                // The reviewer player created it - nobody to tell
                notifyCreator: false,
            ));
        }

        return new JsonResponse(
            $this->getAdminCompetitions->detail($competitionId->toString())->toArray(),
            Response::HTTP_CREATED,
        );
    }
}
