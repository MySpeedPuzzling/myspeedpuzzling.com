<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\FormData\OrganizationFormData;
use SpeedPuzzling\Web\Message\EditOrganization;
use SpeedPuzzling\Web\Message\PublishOrganization;
use SpeedPuzzling\Web\Message\UnpublishOrganization;
use SpeedPuzzling\Web\Query\GetAdminOrganizations;
use SpeedPuzzling\Web\Repository\OrganizationRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Changes only the fields sent (EditOrganization) - null or "" clears a field, `socialLinks` replaces the whole list
 * (`[]` / null removes every link), `maintainerIds` the whole team besides the creator (left out or null keeps it).
 * **A rename keeps the slug**; only an explicit `slug` changes it (unique among organizations, else 409; no redirect).
 * `draft` publishes (false) or takes it back to draft (true - always allowed: a draft organization hides only its
 * own page, directory entry and "Organized by" links).
 */
final class UpdateOrganizationController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly ValidatorInterface $validator,
        private readonly OrganizationRepository $organizationRepository,
        private readonly GetAdminOrganizations $getAdminOrganizations,
    ) {
    }

    #[Route(
        path: '/internal-api/organizations/{organizationId}',
        requirements: ['organizationId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['PATCH'],
    )]
    public function __invoke(string $organizationId, Request $request): JsonResponse
    {
        $organization = $this->organizationRepository->get($organizationId);
        $input = InternalApiInput::fromRequest($request, [...OrganizationInput::FIELDS, 'draft']);

        $data = OrganizationFormData::fromOrganization($organization);
        ['slug' => $slug, 'maintainerIds' => $maintainerIds] = OrganizationInput::applyTo($input, $data, $organization->addedByPlayer?->id->toString());

        if ($input->has('slug') && $slug === null && $input->hasError('slug') === false) {
            $input->addError('slug', 'cannot be cleared - leave it out to keep the slug, or send a new one.');
        }

        $draft = $input->bool('draft');
        $editsFields = array_any(OrganizationInput::FIELDS, static fn (string $field): bool => $input->has($field));

        if ($editsFields) {
            $input->addViolations($this->validator->validate($data));
        }

        $input->throwIfInvalid();

        if ($editsFields) {
            $this->messageBus->dispatch(new EditOrganization(
                organizationId: $organization->id->toString(),
                name: $data->name ?? $organization->name,
                shortName: $data->shortName,
                about: $data->about,
                website: $data->website,
                socialLinks: $data->socialLinks,
                countryCode: $data->countryCode,
                region: $data->region,
                kind: $data->kind,
                logo: null,
                maintainerIds: $maintainerIds ?? $data->maintainers,
                slug: $slug !== $organization->slug ? $slug : null,
            ));
        }

        if ($draft !== null && $draft !== $organization->isDraft) {
            $this->messageBus->dispatch($draft
                ? new UnpublishOrganization($organization->id->toString())
                : new PublishOrganization($organization->id->toString(), notifyAdmin: false));
        }

        return new JsonResponse($this->getAdminOrganizations->detail($organization->id->toString())->toArray());
    }
}
