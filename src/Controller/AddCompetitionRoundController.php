<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\FormData\CompetitionRoundFormData;
use SpeedPuzzling\Web\FormType\CompetitionRoundFormType;
use SpeedPuzzling\Web\Message\AddCompetitionRound;
use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class AddCompetitionRoundController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly GetCompetitionEvents $getCompetitionEvents,
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

        $formData = new CompetitionRoundFormData();
        $form = $this->createForm(CompetitionRoundFormType::class, $formData);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            assert($data->name !== null);
            assert($data->minutesLimit !== null);
            assert($data->startsAt !== null);

            $this->messageBus->dispatch(new AddCompetitionRound(
                roundId: Uuid::uuid7(),
                competitionId: $competitionId,
                name: $data->name,
                minutesLimit: $data->minutesLimit,
                startsAt: $data->startsAt,
                badgeBackgroundColor: $data->badgeBackgroundColor,
                badgeTextColor: $data->badgeTextColor,
            ));

            $this->addFlash('success', $this->translator->trans('competition.flash.round_added'));

            return $this->redirectToRoute('manage_competition_rounds', ['competitionId' => $competitionId]);
        }

        return $this->render('add_competition_round.html.twig', [
            'form' => $form,
            'competition' => $competition,
        ]);
    }
}
