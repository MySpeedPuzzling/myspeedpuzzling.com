<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\FormData\CompetitionFormData;
use SpeedPuzzling\Web\FormType\CompetitionFormType;
use SpeedPuzzling\Web\Message\EditCompetitionSeries;
use SpeedPuzzling\Web\Query\GetCompetitionSeries;
use SpeedPuzzling\Web\Repository\CompetitionSeriesRepository;
use SpeedPuzzling\Web\Security\CompetitionSeriesEditVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class EditCompetitionSeriesController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly CompetitionSeriesRepository $seriesRepository,
        private readonly GetCompetitionSeries $getCompetitionSeries,
        private readonly TranslatorInterface $translator,
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

        $form = $this->createForm(CompetitionFormType::class, $formData);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();

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
            ));

            $this->addFlash('success', $this->translator->trans('competition.flash.updated'));

            return $this->redirectToRoute('manage_competition_series', ['seriesId' => $seriesId]);
        }

        return $this->render('edit_competition_series.html.twig', [
            'form' => $form,
            'series' => $seriesOverview,
        ]);
    }
}
