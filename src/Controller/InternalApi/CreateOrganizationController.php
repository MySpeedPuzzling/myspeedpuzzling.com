<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\EventSubscriber\InternalApiAuditSubscriber;
use SpeedPuzzling\Web\FormData\OrganizationFormData;
use SpeedPuzzling\Web\Message\AddOrganization;
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
 * Creates an organization like "Add organization" does, created by the reviewer player and **approved at once** (an
 * admin creates it - docs/features/organizations/README.md "Approval"; no e-mail). `"draft": true` keeps it a draft:
 * its page answers 404 to everyone but its team until it is published. The logo is uploaded in the UI.
 */
final class CreateOrganizationController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly ValidatorInterface $validator,
        private readonly GetAdminOrganizations $getAdminOrganizations,
        #[Autowire(env: 'INTERNAL_API_REVIEWER_PLAYER_ID')]
        private readonly string $reviewerPlayerId,
    ) {
    }

    #[Route(
        path: '/internal-api/organizations',
        methods: ['POST'],
    )]
    public function __invoke(Request $request): JsonResponse
    {
        if ($this->reviewerPlayerId === '') {
            throw new BadRequestHttpException(
                'INTERNAL_API_REVIEWER_PLAYER_ID is not configured - no player to add the organization as.',
            );
        }

        $input = InternalApiInput::fromRequest($request, [...OrganizationInput::FIELDS, 'draft']);

        $data = new OrganizationFormData();
        ['slug' => $slug, 'maintainerIds' => $maintainerIds] = OrganizationInput::applyTo($input, $data, strtolower($this->reviewerPlayerId));
        $isDraft = $input->bool('draft') ?? false;

        $input->addViolations($this->validator->validate($data));
        $input->throwIfInvalid();

        $organizationId = Uuid::uuid7();

        $this->messageBus->dispatch(new AddOrganization(
            organizationId: $organizationId,
            playerId: $this->reviewerPlayerId,
            name: $data->name ?? '',
            shortName: $data->shortName,
            about: $data->about,
            website: $data->website,
            socialLinks: $data->socialLinks,
            countryCode: $data->countryCode,
            region: $data->region,
            kind: $data->kind,
            logo: null,
            maintainerIds: $maintainerIds ?? [],
            slug: $slug,
            isDraft: $isDraft,
            approve: true,
        ));

        $request->attributes->set(InternalApiAuditSubscriber::CREATED_ID_ATTRIBUTE, $organizationId->toString());

        return new JsonResponse(
            $this->getAdminOrganizations->detail($organizationId->toString())->toArray(),
            Response::HTTP_CREATED,
        );
    }
}
