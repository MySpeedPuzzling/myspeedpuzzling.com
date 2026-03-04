<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

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
        ],
        name: 'edit_competition_round',
    )]
    public function __invoke(Request $request, string $roundId): Response
    {
        $round = $this->competitionRoundRepository->get($roundId);
        $competitionId = $round->competition->id->toString();
        $this->denyAccessUnlessGranted(CompetitionEditVoter::COMPETITION_EDIT, $competitionId);

        $competition = $this->getCompetitionEvents->byId($competitionId);

        $formData = CompetitionRoundFormData::fromCompetitionRound($round);
        $form = $this->createForm(CompetitionRoundFormType::class, $formData);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            assert($data->name !== null);
            assert($data->minutesLimit !== null);
            assert($data->startsAt !== null);

            $this->messageBus->dispatch(new EditCompetitionRound(
                roundId: $roundId,
                name: $data->name,
                minutesLimit: $data->minutesLimit,
                startsAt: $data->startsAt,
                badgeBackgroundColor: $data->badgeBackgroundColor,
                badgeTextColor: $data->badgeTextColor,
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
