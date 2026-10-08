<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Exceptions\CompetitionSlugTaken;
use SpeedPuzzling\Web\Exceptions\OrganizationNotManaged;
use SpeedPuzzling\Web\FormData\CompetitionFormData;
use SpeedPuzzling\Web\FormType\CompetitionFormType;
use SpeedPuzzling\Web\Message\AssignEventToOrganization;
use SpeedPuzzling\Web\Message\EditCompetitionSeries;
use SpeedPuzzling\Web\Query\GetCompetitionSeries;
use SpeedPuzzling\Web\Repository\CompetitionSeriesRepository;
use SpeedPuzzling\Web\Security\AdminAccessVoter;
use SpeedPuzzling\Web\Security\CompetitionSeriesEditVoter;
use SpeedPuzzling\Web\Services\CompetitionUrlField;
use SpeedPuzzling\Web\Services\Organizations\OrganizationSelectChoices;
use SpeedPuzzling\Web\Services\PhotoStash\FormPhotoStash;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\OrganizationItemKind;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Edit a series. docs/features/organizations/README.md "Forms": the "Organization" select (changing it moves the series
 * - AssignEventToOrganization after the edit), "Who can enter" (its editions without their own show it) and "When it
 * happens".
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class EditCompetitionSeriesController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly CompetitionSeriesRepository $seriesRepository,
        private readonly GetCompetitionSeries $getCompetitionSeries,
        private readonly TranslatorInterface $translator,
        private readonly CompetitionUrlField $urlField,
        private readonly FormPhotoStash $formPhotoStash,
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private readonly OrganizationSelectChoices $organizationSelectChoices,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/upravit-serii/{seriesId}',
            'en' => '/en/edit-series/{seriesId}',
            'es' => '/es/edit-series/{seriesId}',
            'ja' => '/ja/edit-series/{seriesId}',
            'fr' => '/fr/edit-series/{seriesId}',
            'de' => '/de/edit-series/{seriesId}',
        ],
        name: 'edit_competition_series',
    )]
    public function __invoke(Request $request, string $seriesId): Response
    {
        $this->denyAccessUnlessGranted(CompetitionSeriesEditVoter::COMPETITION_SERIES_EDIT, $seriesId);

        $series = $this->seriesRepository->get($seriesId);
        $seriesOverview = $this->getCompetitionSeries->byId($seriesId);

        $formData = new CompetitionFormData();
        $formData->name = $series->name;
        $formData->shortcut = $series->shortcut;
        $formData->description = $series->description;
        $formData->link = $series->link;
        $formData->isOnline = $series->isOnline;
        $formData->location = $series->location;
        $formData->locationCountryCode = $series->locationCountryCode;

        $maintainerIds = [];
        foreach ($series->maintainers as $maintainer) {
            $maintainerIds[] = $maintainer->id->toString();
        }
        $formData->maintainers = $maintainerIds;

        $formData->slug = $series->slug;
        $formData->eligibility = $series->eligibility;
        $formData->schedule = $series->schedule;
        $formData->organizationId = $series->organization?->id->toString();
        $currentOrganizationId = $formData->organizationId;
        // A series is recurring: its in-person form must not ask for dates (they belong to its editions)
        $formData->isRecurring = true;

        // Editors have a player profile
        $playerId = $this->retrieveLoggedUserProfile->getProfile()?->playerId;
        $organizationChoices = $playerId !== null
            ? $this->organizationSelectChoices->forPlayer($playerId, $this->isGranted(AdminAccessVoter::ADMIN_ACCESS), $currentOrganizationId)
            : [];

        $form = $this->createForm(CompetitionFormType::class, $formData, [
            'url_field' => true,
            'series' => true,
            'organization_choices' => $organizationChoices !== [] ? $organizationChoices : null,
            'schedule_field' => true,
        ]);
        // A logo chosen for a refused submit (e.g. a taken URL) comes back (FormPhotoStash)
        $restoredPhotos = $playerId !== null ? $this->formPhotoStash->restore($request, $form, $playerId) : [];
        $form->handleRequest($request);
        $this->formPhotoStash->reportLost($form, $restoredPhotos);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $slug = $this->urlField->seriesSlug($form->get('slug'), $series->slug, $seriesId);

            // The URL field holds an error when the typed URL cannot be used
            if ($form->get('slug')->getErrors()->count() === 0) {
                try {
                    $this->messageBus->dispatch(new EditCompetitionSeries(
                        seriesId: $seriesId,
                        name: $data->name ?? '',
                        shortcut: $data->shortcut,
                        description: $data->description,
                        link: $data->link,
                        isOnline: $data->isOnline === true,
                        location: $data->isOnline === true ? null : $data->location,
                        locationCountryCode: $data->locationCountryCode,
                        logo: $data->logo,
                        maintainerIds: $data->maintainers,
                        eligibility: $data->eligibility,
                        schedule: $data->schedule,
                        slug: $slug,
                    ));

                    if ($playerId !== null) {
                        $this->formPhotoStash->forget($restoredPhotos, $playerId);
                    }

                    // Into another organization or out of one - the select offers only the player's organizations
                    if ($form->has('organizationId') && $playerId !== null && $data->organizationId !== $currentOrganizationId) {
                        $this->messageBus->dispatch(new AssignEventToOrganization(
                            kind: OrganizationItemKind::Series,
                            itemId: $seriesId,
                            organizationId: $data->organizationId,
                            actingPlayerId: $playerId,
                        ));
                    }

                    $this->addFlash('success', $this->translator->trans('competition.flash.updated'));

                    return $this->redirectToRoute('manage_competition_series', ['seriesId' => $seriesId]);
                } catch (CompetitionSlugTaken) {
                    // Taken by another save since the check above
                    $this->urlField->markTaken($form->get('slug'));
                } catch (OrganizationNotManaged) {
                    // Left the organization's team since the form was opened - the other changes are saved
                    $form->get('organizationId')->addError(new FormError($this->translator->trans('organizer_tools.form.organization_not_managed')));
                }
            }
        }

        return $this->render('edit_competition_series.html.twig', [
            'form' => $form,
            'kept_photos' => $playerId !== null ? $this->formPhotoStash->keep($form, $restoredPhotos, $playerId) : [],
            'series' => $seriesOverview,
            'slug_prefix' => $this->urlField->prefix('competition_series_detail', 'slug'),
        ]);
    }
}
