<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\CompetitionRoundPuzzle;
use SpeedPuzzling\Web\Exceptions\InvalidLocalTime;
use SpeedPuzzling\Web\Exceptions\OfficialResultsProtected;
use SpeedPuzzling\Web\Exceptions\PuzzleAlreadyInCompetitionRoundCategory;
use SpeedPuzzling\Web\Exceptions\SecretPuzzlesWouldBeRevealed;
use SpeedPuzzling\Web\FormData\CompetitionRoundFormData;
use SpeedPuzzling\Web\FormType\CompetitionRoundFormType;
use SpeedPuzzling\Web\Message\EditCompetitionRound;
use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use SpeedPuzzling\Web\Query\GetCompetitionRoundsForManagement;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use SpeedPuzzling\Web\Services\SecretRevealPreview;
use SpeedPuzzling\Web\Services\ZonedDateTimeFormatter;
use SpeedPuzzling\Web\Value\ReturnUrl;
use SpeedPuzzling\Web\Value\RoundBadgeColor;
use SpeedPuzzling\Web\Value\RoundPuzzleReveal;
use SpeedPuzzling\Web\Value\RoundTimezone;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @phpstan-import-type RevealedPuzzle from SecretRevealPreview
 */
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
        private readonly SecretRevealPreview $secretRevealPreview,
        private readonly GetCompetitionRoundsForManagement $getCompetitionRoundsForManagement,
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
        $timezone = $round->displayTimezone();

        // The time-only field puts the time on the event's day - only right when the round is on that day in its zone.
        // A round on another local day (an evening round in another zone, an old save) gets the full date and time,
        // or an untouched save would move it by a day.
        $singleDay = $competition->singleDay();
        if ($singleDay !== null && RoundTimezone::toLocal($round->startsAt, $timezone)->format('Y-m-d') !== $singleDay->format('Y-m-d')) {
            $singleDay = null;
        }

        $now = $this->clock->now();
        $hasSecretPuzzles = array_filter(
            $round->roundPuzzles->toArray(),
            static fn (CompetitionRoundPuzzle $roundPuzzle): bool => $roundPuzzle->isHiddenAt($now),
        ) !== [];

        // Shows the round in the zone it was typed in, as the organiser typed it
        $formData = CompetitionRoundFormData::fromCompetitionRound($round);
        $form = $this->createForm(CompetitionRoundFormType::class, $formData, [
            'single_day' => $singleDay !== null,
            'timezone_offset_at' => $round->startsAt,
            'reveal_confirmation' => $hasSecretPuzzles,
            'timezone_assumed' => $round->isTimezoneNeverSaved(),
        ]);
        $form->handleRequest($request);
        $revealedEarlier = [];

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            assert($data->name !== null);
            assert($data->minutesLimit !== null);
            assert($data->timezone !== null);

            // The delay the round has after the save
            $revealDelayMinutes = $data->revealDelayMinutes;
            assert($revealDelayMinutes !== null);

            $previousStartsAt = $round->startsAt;
            $previousAutomaticRevealAt = $round->automaticRevealAt();
            $startsAt = null;
            $ticked = $form->has('confirmReveal') && $form->get('confirmReveal')->getData() === true;

            try {
                $startsAt = $data->startsAtInstant($singleDay);
            } catch (InvalidLocalTime) {
                $form->get($singleDay !== null ? 'startsAtTime' : 'startsAt')->addError(new FormError(
                    $this->translator->trans('competition.round.form.invalid_local_time'),
                ));
            }

            // A start or a delay moving the automatic reveal (start + delay) earlier lets the round's secret puzzles out
            // earlier than planned (a moment already over: right away) - only on an explicit yes
            if ($startsAt !== null) {
                $revealedEarlier = $this->secretRevealPreview->byChangingRound($round, $startsAt, $revealDelayMinutes);
                // The yes counts only for exactly the list it was given (shown with the form, re-computed now)
                $confirmed = $ticked && $request->request->get('confirm_reveal_hash') === SecretRevealPreview::hash($revealedEarlier);

                if ($revealedEarlier !== [] && $confirmed === false) {
                    // Ticked for another list (the start or the minutes changed since it was shown) - asked anew
                    $this->askToConfirm($form, $revealedEarlier, $ticked
                        ? 'competition.reveal.form.confirm_reveal_changed'
                        : 'competition.reveal.form.confirm_reveal_required');
                }
            }

            // Errors added above (an impossible time, a missing yes) keep the form from saving
            if ($startsAt !== null && count($form->getErrors(true)) === 0) {
                try {
                    $this->messageBus->dispatch(new EditCompetitionRound(
                        roundId: $roundId,
                        competitionId: $competitionId,
                        name: $data->name,
                        minutesLimit: $data->minutesLimit,
                        startsAt: $startsAt,
                        timezone: $data->timezone,
                        badgeBackgroundColor: RoundBadgeColor::chosen($data->badgeBackgroundColor),
                        // The form asks for no text colour - it is picked for contrast wherever the round is shown
                        badgeTextColor: RoundBadgeColor::textForChosen($data->badgeBackgroundColor),
                        category: $data->category,
                        resultsLink: $data->resultsLink,
                        // Asked above - the yes is bound to exactly that list, re-checked after the handler's locks:
                        // another change in between asks again
                        refuseToReveal: false,
                        confirmedRevealHash: SecretRevealPreview::hash($revealedEarlier),
                        revealDelayMinutes: $revealDelayMinutes,
                    ));

                    // The handler worked on freshly read rows - read the round again for the flash
                    $this->flashRoundUpdated(
                        $this->competitionRoundRepository->get($roundId),
                        $previousStartsAt,
                        $previousAutomaticRevealAt,
                        $data->timezone,
                        $revealedEarlier,
                    );

                    // Back where the organiser came from - the round's puzzles page links to the reveal minutes
                    $returnUrl = ReturnUrl::tryFrom($request->query->getString('return'));

                    if ($returnUrl !== null) {
                        return $this->redirect($returnUrl->path);
                    }

                    return $this->redirectToRoute('manage_competition_rounds', ['competitionId' => $competitionId]);
                } catch (SecretPuzzlesWouldBeRevealed $changed) {
                    // Something changed between the form and the save: the new list, confirmed anew - "not what you
                    // confirmed" only when there was a yes (an unticked save whose list appeared meanwhile is asked
                    // for the first time)
                    $round = $this->competitionRoundRepository->get($roundId);
                    $revealedEarlier = $changed->puzzles;
                    $this->askToConfirm($form, $revealedEarlier, $ticked
                        ? 'competition.reveal.form.confirm_reveal_changed'
                        : 'competition.reveal.form.confirm_reveal_required');
                } catch (OfficialResultsProtected $protected) {
                    // The round's category stays while it has official results - read the round again, like below
                    $round = $this->competitionRoundRepository->get($roundId);
                    $form->get('category')->addError(new FormError($this->translator->trans($protected->translationKey())));
                } catch (HandlerFailedException $e) {
                    $nested = $e->getPrevious() ?? $e;

                    if (!$nested instanceof PuzzleAlreadyInCompetitionRoundCategory) {
                        throw $e;
                    }

                    // The handler cleared the entity manager (SecretPuzzleHides::lock()) - read the round again
                    $round = $this->competitionRoundRepository->get($roundId);

                    // A form error makes the form invalid, so render() answers 422 - Turbo Drive drops a 200
                    $form->get('category')->addError(new FormError($this->translator->trans(
                        'competition.round.form.puzzle_already_in_category',
                        ['%round%' => $nested->conflictingRoundName],
                    )));
                }
            }
        }

        $revealConfirmationHash = SecretRevealPreview::hash($revealedEarlier);

        return $this->render('edit_competition_round.html.twig', [
            'form' => $form,
            'competition' => $competition,
            'round' => $round,
            'single_day' => $singleDay,
            'revealed_earlier' => $revealedEarlier,
            'reveal_confirmation_hash' => $revealConfirmationHash,
            // Shown ticked again only for the very list the tick was given for - a stale tick never confirms a new list
            'reveal_confirmation_ticked' => $form->isSubmitted()
                && $form->has('confirmReveal')
                && $form->get('confirmReveal')->getData() === true
                && $request->request->get('confirm_reveal_hash') === $revealConfirmationHash,
            'timezone' => $timezone,
            'schedule_position' => $this->schedulePosition($competitionId, $roundId),
        ]);
    }

    /**
     * Says what would come out earlier and asks for the yes - on the tick box when the form has one (the round had secret
     * puzzles when the form was built), on the form otherwise.
     *
     * @param FormInterface<CompetitionRoundFormData> $form
     * @param list<RevealedPuzzle> $revealed
     */
    private function askToConfirm(FormInterface $form, array $revealed, string $messageKey): void
    {
        $error = new FormError($this->translator->trans($messageKey, [
            '%puzzles%' => implode(', ', array_column($revealed, 'name')),
        ]));

        if ($form->has('confirmReveal')) {
            $form->get('confirmReveal')->addError($error);
        } else {
            $form->addError($error);
        }
    }

    /**
     * The round's place in the event's schedule - decides its automatic badge colour (RoundBadgeColor)
     */
    private function schedulePosition(string $competitionId, string $roundId): int
    {
        foreach ($this->getCompetitionRoundsForManagement->ofCompetition($competitionId) as $round) {
            if ($round->id === $roundId) {
                return $round->schedulePosition;
            }
        }

        return 0;
    }

    /**
     * The automatic reveal (start + delay) moved: say when the round's secret puzzles with it are revealed now, what came
     * out right away, and that the organiser's own reveal times did not move (also when only the start moved).
     *
     * @param list<RevealedPuzzle> $revealed
     */
    private function flashRoundUpdated(
        CompetitionRound $round,
        DateTimeImmutable $previousStartsAt,
        DateTimeImmutable $previousAutomaticRevealAt,
        string $timezone,
        array $revealed,
    ): void {
        $revealedRightAway = array_filter($revealed, static fn (array $item): bool => $item['revealsAt'] === null);

        if ($revealedRightAway !== []) {
            $this->addFlash('warning', $this->translator->trans('competition.reveal.flash.round_updated_revealed', [
                '%puzzles%' => implode(', ', array_column($revealedRightAway, 'name')),
            ]));
        }

        $now = $this->clock->now();
        $automaticRevealMoved = $round->automaticRevealAt()->getTimestamp() !== $previousAutomaticRevealAt->getTimestamp();
        $startMoved = $round->startsAt->getTimestamp() !== $previousStartsAt->getTimestamp();
        $automaticRevealsAt = null;
        $ownRevealStaysPut = false;

        if ($automaticRevealMoved || $startMoved) {
            foreach ($round->roundPuzzles as $roundPuzzle) {
                if ($roundPuzzle->isHiddenAt($now) === false) {
                    continue;
                }

                if ($roundPuzzle->revealMode !== RoundPuzzleReveal::Automatic) {
                    $ownRevealStaysPut = true;
                } elseif ($automaticRevealMoved) {
                    $automaticRevealsAt = $roundPuzzle->revealsAt();
                }
            }
        }

        if ($automaticRevealsAt !== null) {
            $this->addFlash('success', $this->translator->trans('competition.reveal.flash.round_updated_reveal_moved', [
                '%time%' => $this->zonedDateTimeFormatter->format($automaticRevealsAt, $timezone, $round->isTimezoneAssumed()),
            ]));
        } else {
            $this->addFlash('success', $this->translator->trans('competition.flash.round_updated'));
        }

        if ($ownRevealStaysPut) {
            $this->addFlash('warning', $this->translator->trans('competition.reveal.flash.round_updated_own_reveals_kept'));
        }
    }
}
