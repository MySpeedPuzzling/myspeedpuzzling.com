<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\FormData\EditionFormData;
use SpeedPuzzling\Web\FormType\EditionFormType;
use SpeedPuzzling\Web\Message\AddEdition;
use SpeedPuzzling\Web\Query\GetCompetitionSeries;
use SpeedPuzzling\Web\Services\CompetitionDetailUrl;
use SpeedPuzzling\Web\Security\CompetitionSeriesEditVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\ClickableInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Add one edition to a series - "Who can enter" and "Save as draft" (docs/features/organizations/README.md "Forms");
 * several dates at once are `add_editions`.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class AddEditionController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly GetCompetitionSeries $getCompetitionSeries,
        private readonly TranslatorInterface $translator,
        private readonly CompetitionDetailUrl $competitionDetailUrl,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/pridat-edici/{seriesId}',
            'en' => '/en/add-edition/{seriesId}',
            'es' => '/es/add-edition/{seriesId}',
            'ja' => '/ja/add-edition/{seriesId}',
            'fr' => '/fr/add-edition/{seriesId}',
            'de' => '/de/add-edition/{seriesId}',
        ],
        name: 'add_edition',
    )]
    public function __invoke(Request $request, string $seriesId): Response
    {
        $this->denyAccessUnlessGranted(CompetitionSeriesEditVoter::COMPETITION_SERIES_EDIT, $seriesId);

        $series = $this->getCompetitionSeries->byId($seriesId);

        $formData = new EditionFormData();
        $form = $this->createForm(EditionFormType::class, $formData);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $saveDraft = $form->get('saveDraft');
            $isDraft = $saveDraft instanceof ClickableInterface && $saveDraft->isClicked();

            $competitionId = Uuid::uuid7();

            $this->messageBus->dispatch(new AddEdition(
                competitionId: $competitionId,
                seriesId: $seriesId,
                name: $data->name ?? '',
                dateFrom: $data->dateFrom,
                dateTo: $data->dateTo,
                registrationLink: $data->registrationLink,
                resultsLink: $data->resultsLink,
                link: $data->link,
                description: $data->description,
                eligibility: $data->eligibility,
                isDraft: $isDraft,
            ));

            $this->addFlash('success', $this->translator->trans($isDraft ? 'organizer_tools.flash.saved_as_draft' : 'edition.flash.created'));

            // A draft: its own page, where the draft banner offers Publish
            if ($isDraft) {
                return $this->redirect($this->competitionDetailUrl->of($competitionId->toString()));
            }

            return $this->redirectToRoute('manage_competition_series', ['seriesId' => $seriesId]);
        }

        return $this->render('add_edition.html.twig', [
            'form' => $form,
            'series' => $series,
        ]);
    }
}
