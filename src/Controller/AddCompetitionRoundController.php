<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\FormData\CompetitionRoundFormData;
use SpeedPuzzling\Web\FormType\CompetitionRoundFormType;
use SpeedPuzzling\Web\Message\AddCompetitionRound;
use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use SpeedPuzzling\Web\Query\GetCompetitionRoundsForManagement;
use SpeedPuzzling\Web\Value\RoundTimezone;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class AddCompetitionRoundController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly GetCompetitionEvents $getCompetitionEvents,
        private readonly GetCompetitionRoundsForManagement $getCompetitionRoundsForManagement,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/pridat-kolo-udalosti/{competitionId}',
            'en' => '/en/add-event-round/{competitionId}',
            'es' => '/es/add-event-round/{competitionId}',
            'ja' => '/ja/add-event-round/{competitionId}',
            'fr' => '/fr/add-event-round/{competitionId}',
            'de' => '/de/add-event-round/{competitionId}',
        ],
        name: 'add_competition_round',
    )]
    public function __invoke(Request $request, string $competitionId): Response
    {
        $this->denyAccessUnlessGranted(CompetitionEditVoter::COMPETITION_EDIT, $competitionId);

        $competition = $this->getCompetitionEvents->byId($competitionId);

        $singleDay = $competition->singleDay();

        // An organiser picks the zone once: a new round starts in the zone of the event's other rounds
        $otherRounds = $this->getCompetitionRoundsForManagement->ofCompetition($competitionId);
        $timezone = $otherRounds !== []
            ? $otherRounds[array_key_last($otherRounds)]->timezone
            : RoundTimezone::resolve(null, $competition->locationCountryCode?->name);

        $formData = CompetitionRoundFormData::forNewRound($timezone);
        $form = $this->createForm(CompetitionRoundFormType::class, $formData, [
            'single_day' => $singleDay !== null,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            assert($data->name !== null);
            assert($data->minutesLimit !== null);
            assert($data->timezone !== null);

            $this->messageBus->dispatch(new AddCompetitionRound(
                roundId: Uuid::uuid7(),
                competitionId: $competitionId,
                name: $data->name,
                minutesLimit: $data->minutesLimit,
                startsAt: $data->startsAtInstant($singleDay),
                timezone: $data->timezone,
                badgeBackgroundColor: $data->badgeBackgroundColor,
                badgeTextColor: $data->badgeTextColor,
                category: $data->category,
                resultsLink: $data->resultsLink,
            ));

            $this->addFlash('success', $this->translator->trans('competition.flash.round_added'));

            return $this->redirectToRoute('manage_competition_rounds', ['competitionId' => $competitionId]);
        }

        return $this->render('add_competition_round.html.twig', [
            'form' => $form,
            'competition' => $competition,
            'single_day' => $singleDay,
        ]);
    }
}
