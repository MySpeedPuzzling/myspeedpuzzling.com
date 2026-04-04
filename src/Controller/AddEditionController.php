<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\FormData\EditionFormData;
use SpeedPuzzling\Web\FormType\EditionFormType;
use SpeedPuzzling\Web\Message\AddEdition;
use SpeedPuzzling\Web\Query\GetCompetitionSeries;
use SpeedPuzzling\Web\Security\CompetitionSeriesEditVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class AddEditionController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly GetCompetitionSeries $getCompetitionSeries,
        private readonly TranslatorInterface $translator,
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

            /** @var string $selectedTimezone */
            $selectedTimezone = $form->get('timezone')->getData();

            $startsAt = $data->startsAt;
            assert($startsAt instanceof DateTimeImmutable);

            $localTz = new \DateTimeZone($selectedTimezone);
            $utcTz = new \DateTimeZone('UTC');
            $localDateTime = DateTimeImmutable::createFromFormat(
                'Y-m-d H:i',
                $startsAt->format('Y-m-d H:i'),
                $localTz,
            );
            assert($localDateTime instanceof DateTimeImmutable);
            $utcDateTime = $localDateTime->setTimezone($utcTz);

            $this->messageBus->dispatch(new AddEdition(
                competitionId: Uuid::uuid7(),
                roundId: Uuid::uuid7(),
                seriesId: $seriesId,
                name: $data->name ?? '',
                startsAt: $utcDateTime,
                minutesLimit: $data->minutesLimit ?? 0,
                registrationLink: $data->registrationLink,
                resultsLink: $data->resultsLink,
            ));

            $this->addFlash('success', $this->translator->trans('edition.flash.created'));

            return $this->redirectToRoute('manage_competition_series', ['seriesId' => $seriesId]);
        }

        return $this->render('add_edition.html.twig', [
            'form' => $form,
            'series' => $series,
        ]);
    }
}
