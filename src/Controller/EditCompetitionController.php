<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Exceptions\CompetitionSlugTaken;
use SpeedPuzzling\Web\FormData\CompetitionFormData;
use SpeedPuzzling\Web\FormType\CompetitionFormType;
use SpeedPuzzling\Web\Message\EditCompetition;
use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use SpeedPuzzling\Web\Services\CompetitionUrlField;
use SpeedPuzzling\Web\Services\PhotoStash\FormPhotoStash;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class EditCompetitionController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly CompetitionRepository $competitionRepository,
        private readonly GetCompetitionEvents $getCompetitionEvents,
        private readonly TranslatorInterface $translator,
        private readonly CompetitionUrlField $urlField,
        private readonly FormPhotoStash $formPhotoStash,
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/upravit-udalost/{competitionId}',
            'en' => '/en/edit-event/{competitionId}',
            'es' => '/es/edit-event/{competitionId}',
            'ja' => '/ja/edit-event/{competitionId}',
            'fr' => '/fr/edit-event/{competitionId}',
            'de' => '/de/edit-event/{competitionId}',
        ],
        name: 'edit_competition',
    )]
    public function __invoke(Request $request, string $competitionId): Response
    {
        $this->denyAccessUnlessGranted(CompetitionEditVoter::COMPETITION_EDIT, $competitionId);

        $competition = $this->competitionRepository->get($competitionId);
        $competitionEvent = $this->getCompetitionEvents->byId($competitionId);

        $seriesId = $competition->series?->id->toString();
        $seriesSlug = $competition->series?->slug;

        $formData = CompetitionFormData::fromCompetition($competition);
        $formData->slug = $competition->slug;
        $form = $this->createForm(CompetitionFormType::class, $formData, ['url_field' => true]);
        // A logo chosen for a refused submit (e.g. a taken URL) comes back (FormPhotoStash) - editors have a player profile
        $playerId = $this->retrieveLoggedUserProfile->getProfile()?->playerId;
        $restoredPhotos = $playerId !== null ? $this->formPhotoStash->restore($request, $form, $playerId) : [];
        $form->handleRequest($request);
        $this->formPhotoStash->reportLost($form, $restoredPhotos);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $slug = $this->urlField->competitionSlug($form->get('slug'), $competition->slug, $seriesId, $competitionId);

            // The URL field holds an error when the typed URL cannot be used
            if ($form->get('slug')->getErrors()->count() === 0) {
                try {
                    $this->messageBus->dispatch(new EditCompetition(
                        competitionId: $competitionId,
                        name: $data->name ?? '',
                        shortcut: $data->shortcut,
                        description: $data->description,
                        link: $data->link,
                        registrationLink: $data->registrationLink,
                        resultsLink: $data->resultsLink,
                        location: $data->isOnline === true ? null : $data->location,
                        locationCountryCode: $data->locationCountryCode,
                        // An online event keeps its dates too - an edition of an online series always is one
                        dateFrom: $data->dateFrom,
                        dateTo: $data->dateTo,
                        isOnline: $data->isOnline === true,
                        logo: $data->logo,
                        maintainerIds: $data->maintainers,
                        slug: $slug,
                    ));

                    if ($playerId !== null) {
                        $this->formPhotoStash->forget($restoredPhotos, $playerId);
                    }

                    $this->addFlash('success', $this->translator->trans('competition.flash.updated'));

                    return $this->redirectToRoute('edit_competition', ['competitionId' => $competitionId]);
                } catch (CompetitionSlugTaken) {
                    // Taken by another save since the check above
                    $this->urlField->markTaken($form->get('slug'));
                }
            }
        }

        if ($seriesId === null) {
            $slugPrefix = $this->urlField->prefix('event_detail', 'slug');
        } elseif ($seriesSlug !== null) {
            $slugPrefix = $this->urlField->prefix('edition_detail', 'editionSlug', ['seriesSlug' => $seriesSlug]);
        } else {
            $slugPrefix = null;
        }

        return $this->render('edit_competition.html.twig', [
            'form' => $form,
            'kept_photos' => $playerId !== null ? $this->formPhotoStash->keep($form, $restoredPhotos, $playerId) : [],
            'competition' => $competitionEvent,
            'slug_prefix' => $slugPrefix,
        ]);
    }
}
