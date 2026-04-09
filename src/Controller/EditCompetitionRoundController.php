<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use DateTimeImmutable;
use DateTimeZone;
use SpeedPuzzling\Web\FormData\CompetitionRoundFormData;
use SpeedPuzzling\Web\FormType\CompetitionRoundFormType;
use SpeedPuzzling\Web\Message\EditCompetitionRound;
use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class EditCompetitionRoundController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly CompetitionRoundRepository $competitionRoundRepository,
        private readonly GetCompetitionEvents $getCompetitionEvents,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/upravit-kolo-udalosti/{roundId}',
            'en' => '/en/edit-event-round/{roundId}',
            'es' => '/es/edit-event-round/{roundId}',
            'ja' => '/ja/edit-event-round/{roundId}',
            'fr' => '/fr/edit-event-round/{roundId}',
            'de' => '/de/edit-event-round/{roundId}',
        ],
        name: 'edit_competition_round',
    )]
    public function __invoke(Request $request, string $roundId): Response
    {
        $round = $this->competitionRoundRepository->get($roundId);
        $competitionId = $round->competition->id->toString();
        $this->denyAccessUnlessGranted(CompetitionEditVoter::COMPETITION_EDIT, $competitionId);

        $competition = $this->getCompetitionEvents->byId($competitionId);

        $isSingleDay = $competition->dateFrom !== null
            && $competition->dateTo !== null
            && $competition->dateFrom->format('Y-m-d') === $competition->dateTo->format('Y-m-d');

        $formData = CompetitionRoundFormData::fromCompetitionRound($round);
        $form = $this->createForm(CompetitionRoundFormType::class, $formData, [
            'date_from' => $competition->dateFrom,
            'date_to' => $competition->dateTo,
            'country_code' => $competition->locationCountryCode?->name,
        ]);

        if ($isSingleDay) {
            $form->get('startsAtTime')->setData($round->startsAt->format('H:i'));
        }

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            assert($data->name !== null);
            assert($data->minutesLimit !== null);

            /** @var string $timezone */
            $timezone = $form->get('timezone')->getData();
            $tz = new DateTimeZone($timezone);

            if ($isSingleDay) {
                /** @var string $time */
                $time = $form->get('startsAtTime')->getData();
                $localDateTime = new DateTimeImmutable(
                    $competition->dateFrom->format('Y-m-d') . ' ' . $time,
                    $tz,
                );
                $startsAt = $localDateTime->setTimezone(new DateTimeZone('UTC'));
            } else {
                assert($data->startsAt !== null);
                $localDateTime = new DateTimeImmutable(
                    $data->startsAt->format('Y-m-d H:i:s'),
                    $tz,
                );
                $startsAt = $localDateTime->setTimezone(new DateTimeZone('UTC'));
            }

            $this->messageBus->dispatch(new EditCompetitionRound(
                roundId: $roundId,
                name: $data->name,
                minutesLimit: $data->minutesLimit,
                startsAt: $startsAt,
                badgeBackgroundColor: $data->badgeBackgroundColor,
                badgeTextColor: $data->badgeTextColor,
                category: $data->category,
            ));

            $this->addFlash('success', $this->translator->trans('competition.flash.round_updated'));

            return $this->redirectToRoute('manage_competition_rounds', ['competitionId' => $competitionId]);
        }

        return $this->render('edit_competition_round.html.twig', [
            'form' => $form,
            'competition' => $competition,
            'round' => $round,
        ]);
    }
}
