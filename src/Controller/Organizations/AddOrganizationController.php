<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Organizations;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\FormData\OrganizationFormData;
use SpeedPuzzling\Web\FormType\OrganizationFormType;
use SpeedPuzzling\Web\Message\AddOrganization;
use SpeedPuzzling\Web\Query\GetOrganization;
use SpeedPuzzling\Web\Services\PhotoStash\FormPhotoStash;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\ClickableInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Add an organization (docs/features/organizations/README.md "Forms"): any signed-in player; it waits for an admin's
 * approval - or stays a draft ("Save as draft"), submitted once published. Success → the organization's page; a refused
 * submit comes back with 422 and keeps the chosen logo (FormPhotoStash).
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class AddOrganizationController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetOrganization $getOrganization,
        readonly private FormPhotoStash $formPhotoStash,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/pridat-organizaci',
            'en' => '/en/add-organization',
            'es' => '/es/add-organization',
            'ja' => '/ja/add-organization',
            'fr' => '/fr/add-organization',
            'de' => '/de/add-organization',
        ],
        name: 'add_organization',
        methods: ['GET', 'POST'],
    )]
    public function __invoke(Request $request): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();

        if ($player === null) {
            return $this->redirectToRoute('organizations');
        }

        $form = $this->createForm(OrganizationFormType::class, new OrganizationFormData(), ['draft_button' => true]);
        // A logo chosen for a refused submit comes back (FormPhotoStash)
        $restoredPhotos = $this->formPhotoStash->restore($request, $form, $player->playerId);
        $form->handleRequest($request);
        $this->formPhotoStash->reportLost($form, $restoredPhotos);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $organizationId = Uuid::uuid7();
            $saveDraft = $form->get('saveDraft');
            $isDraft = $saveDraft instanceof ClickableInterface && $saveDraft->isClicked();

            $this->messageBus->dispatch(new AddOrganization(
                organizationId: $organizationId,
                playerId: $player->playerId,
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
                isDraft: $isDraft,
            ));

            $this->formPhotoStash->forget($restoredPhotos, $player->playerId);
            $this->addFlash('success', $this->translator->trans($isDraft ? 'organization_page.flash.saved_as_draft' : 'organization_page.flash.submitted'));

            // The slug is generated from the name by the handler
            $organization = $this->getOrganization->byId($organizationId->toString());

            return $this->redirectToRoute('organization_detail', ['slug' => $organization->slug]);
        }

        return $this->render('add_organization.html.twig', [
            'form' => $form,
            'kept_photos' => $this->formPhotoStash->keep($form, $restoredPhotos, $player->playerId),
        ]);
    }
}
