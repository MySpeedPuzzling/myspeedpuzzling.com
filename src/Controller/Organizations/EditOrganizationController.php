<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Organizations;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Exceptions\OrganizationSlugTaken;
use SpeedPuzzling\Web\FormData\OrganizationFormData;
use SpeedPuzzling\Web\FormType\OrganizationFormType;
use SpeedPuzzling\Web\Message\EditOrganization;
use SpeedPuzzling\Web\Query\GetOrganization;
use SpeedPuzzling\Web\Repository\OrganizationRepository;
use SpeedPuzzling\Web\Security\OrganizationEditVoter;
use SpeedPuzzling\Web\Services\CompetitionUrlField;
use SpeedPuzzling\Web\Services\PhotoStash\FormPhotoStash;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\ReturnUrl;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Edit an organization (docs/features/organizations/README.md "Forms"): its team and admins. A rename keeps the slug;
 * the "URL" field changes it (no redirect, P11). The page lists the team - for the team only, never on the public page
 * (D19) - and says why the organization is not public yet (waiting for approval, rejected with the reason, a draft).
 * Success → back where the edit was opened from (`return`), else the organization's page, with a flash.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class EditOrganizationController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
        readonly private OrganizationRepository $organizationRepository,
        readonly private GetOrganization $getOrganization,
        readonly private CompetitionUrlField $urlField,
        readonly private FormPhotoStash $formPhotoStash,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/upravit-organizaci/{organizationId}',
            'en' => '/en/edit-organization/{organizationId}',
            'es' => '/es/edit-organization/{organizationId}',
            'ja' => '/ja/edit-organization/{organizationId}',
            'fr' => '/fr/edit-organization/{organizationId}',
            'de' => '/de/edit-organization/{organizationId}',
        ],
        name: 'edit_organization',
        requirements: ['organizationId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET', 'POST'],
    )]
    public function __invoke(Request $request, string $organizationId): Response
    {
        $this->denyAccessUnlessGranted(OrganizationEditVoter::ORGANIZATION_EDIT, $organizationId);

        $organization = $this->organizationRepository->get($organizationId);
        $form = $this->createForm(OrganizationFormType::class, OrganizationFormData::fromOrganization($organization), ['url_field' => true]);

        // A logo chosen for a refused submit (e.g. a taken URL) comes back (FormPhotoStash) - editors have a player profile
        $playerId = $this->retrieveLoggedUserProfile->getProfile()?->playerId;
        $restoredPhotos = $playerId !== null ? $this->formPhotoStash->restore($request, $form, $playerId) : [];
        $form->handleRequest($request);
        $this->formPhotoStash->reportLost($form, $restoredPhotos);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $slug = $this->urlField->organizationSlug($form->get('slug'), $organization->slug, $organizationId);

            // The URL field holds an error when the typed URL cannot be used
            if ($form->get('slug')->getErrors()->count() === 0) {
                try {
                    $this->messageBus->dispatch(new EditOrganization(
                        organizationId: $organizationId,
                        name: $data->name ?? '',
                        shortName: $data->shortName,
                        about: $data->about,
                        website: $data->website,
                        socialLinks: $data->socialLinks,
                        countryCode: $data->countryCode,
                        region: $data->region,
                        kind: $data->kind,
                        logo: $data->logo,
                        maintainerIds: $data->maintainers,
                        slug: $slug,
                    ));

                    if ($playerId !== null) {
                        $this->formPhotoStash->forget($restoredPhotos, $playerId);
                    }

                    $this->addFlash('success', $this->translator->trans('organization_page.flash.saved'));

                    $returnUrl = ReturnUrl::tryFrom($request->query->getString('return'));

                    if ($returnUrl !== null) {
                        return $this->redirect($returnUrl->path);
                    }

                    return $this->redirectToRoute('organization_detail', ['slug' => $slug ?? $organization->slug]);
                } catch (OrganizationSlugTaken) {
                    // Taken by another save since the check above
                    $this->urlField->markTaken($form->get('slug'));
                }
            }
        }

        $team = [];

        if ($organization->addedByPlayer !== null) {
            $team[] = ['name' => $organization->addedByPlayer->name ?? $organization->addedByPlayer->code, 'code' => $organization->addedByPlayer->code, 'creator' => true];
        }

        foreach ($organization->maintainers as $maintainer) {
            $team[] = ['name' => $maintainer->name ?? $maintainer->code, 'code' => $maintainer->code, 'creator' => false];
        }

        return $this->render('edit_organization.html.twig', [
            'form' => $form,
            'kept_photos' => $playerId !== null ? $this->formPhotoStash->keep($form, $restoredPhotos, $playerId) : [],
            'organization' => $this->getOrganization->byId($organizationId),
            'team' => $team,
            'slug_prefix' => $this->urlField->prefix('organization_detail', 'slug'),
        ]);
    }
}
