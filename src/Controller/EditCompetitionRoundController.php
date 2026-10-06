<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Exceptions\PuzzleAlreadyInCompetitionRoundCategory;
use SpeedPuzzling\Web\FormData\CompetitionRoundFormData;
use SpeedPuzzling\Web\FormType\CompetitionRoundFormType;
use SpeedPuzzling\Web\Message\EditCompetitionRound;
use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use SpeedPuzzling\Web\Services\ZonedDateTimeFormatter;
use SpeedPuzzling\Web\Value\RoundPuzzleReveal;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class EditCompetitionRoundController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly CompetitionRoundRepository $competitionRoundRepository,
        private readonly GetCompetitionEvents $getCompetitionEvents,
        private readonly TranslatorInterface $translator,
        private readonly ClockInterface $clock,
        private readonly ZonedDateTimeFormatter $zonedDateTimeFormatter,
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

        $singleDay = $competition->singleDay();

        // Shows the round in the zone it was typed in, as the organiser typed it
        $formData = CompetitionRoundFormData::fromCompetitionRound($round);
        $form = $this->createForm(CompetitionRoundFormType::class, $formData, [
            'single_day' => $singleDay !== null,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            assert($data->name !== null);
            assert($data->minutesLimit !== null);
            assert($data->timezone !== null);

            $previousStartsAt = $round->startsAt;
            $startsAt = $data->startsAtInstant($singleDay);

            try {
                $this->messageBus->dispatch(new EditCompetitionRound(
                    roundId: $roundId,
                    name: $data->name,
                    minutesLimit: $data->minutesLimit,
                    startsAt: $startsAt,
                    timezone: $data->timezone,
                    badgeBackgroundColor: $data->badgeBackgroundColor,
                    badgeTextColor: $data->badgeTextColor,
                    category: $data->category,
                    resultsLink: $data->resultsLink,
                ));
            } catch (HandlerFailedException $e) {
                $nested = $e->getPrevious() ?? $e;

                if (!$nested instanceof PuzzleAlreadyInCompetitionRoundCategory) {
                    throw $e;
                }

                // A form error makes the form invalid, so render() answers 422 - Turbo Drive drops a 200
                $form->get('category')->addError(new FormError($this->translator->trans(
                    'competition.round.form.puzzle_already_in_category',
                    ['%round%' => $nested->conflictingRoundName],
                )));

                return $this->render('edit_competition_round.html.twig', [
                    'form' => $form,
                    'competition' => $competition,
                    'round' => $round,
                    'single_day' => $singleDay,
                ]);
            }

            $this->flashRoundUpdated($round, $previousStartsAt->getTimestamp() !== $startsAt->getTimestamp(), $data->timezone);

            return $this->redirectToRoute('manage_competition_rounds', ['competitionId' => $competitionId]);
        }

        return $this->render('edit_competition_round.html.twig', [
            'form' => $form,
            'competition' => $competition,
            'round' => $round,
            'single_day' => $singleDay,
        ]);
    }

    /**
     * A moved round moves the automatic reveals of its secret puzzles - say when they are revealed now, and that the
     * organiser's own reveal times did not move.
     */
    private function flashRoundUpdated(CompetitionRound $round, bool $startMoved, string $timezone): void
    {
        $now = $this->clock->now();
        $automaticRevealsAt = null;
        $ownRevealStaysPut = false;

        if ($startMoved) {
            foreach ($round->roundPuzzles as $roundPuzzle) {
                if ($roundPuzzle->isHiddenAt($now) === false) {
                    continue;
                }

                if ($roundPuzzle->revealMode === RoundPuzzleReveal::Automatic) {
                    $automaticRevealsAt = $roundPuzzle->revealsAt();
                } else {
                    $ownRevealStaysPut = true;
                }
            }
        }

        if ($automaticRevealsAt !== null) {
            $this->addFlash('success', $this->translator->trans('competition.reveal.flash.round_updated_reveal_moved', [
                '%time%' => $this->zonedDateTimeFormatter->format($automaticRevealsAt, $timezone),
            ]));
        } else {
            $this->addFlash('success', $this->translator->trans('competition.flash.round_updated'));
        }

        if ($ownRevealStaysPut) {
            $this->addFlash('warning', $this->translator->trans('competition.reveal.flash.round_updated_own_reveals_kept'));
        }
    }
}
