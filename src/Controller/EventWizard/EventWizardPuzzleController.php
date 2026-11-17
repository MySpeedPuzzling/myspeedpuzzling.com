<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\EventWizard;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Exceptions\AutomaticRevealChangedMeanwhile;
use SpeedPuzzling\Web\Exceptions\OfficialResultsProtected;
use SpeedPuzzling\Web\Exceptions\OnlineTimingInUse;
use SpeedPuzzling\Web\Exceptions\PuzzleAlreadyInCompetitionRoundCategory;
use SpeedPuzzling\Web\Exceptions\PuzzleHiddenByHand;
use SpeedPuzzling\Web\Exceptions\PuzzleNameAlreadyPublic;
use SpeedPuzzling\Web\Exceptions\RevealMomentAlreadyPassed;
use SpeedPuzzling\Web\Exceptions\RoundPuzzleAlreadyRevealed;
use SpeedPuzzling\Web\Exceptions\RoundPuzzleFormatRefused;
use SpeedPuzzling\Web\Exceptions\RoundStopwatchAlreadyStarted;
use SpeedPuzzling\Web\FormData\CompetitionRoundFormData;
use SpeedPuzzling\Web\FormData\EventWizardPuzzleFormData;
use SpeedPuzzling\Web\FormType\EventWizardPuzzleFormType;
use SpeedPuzzling\Web\Message\AddPuzzleToCompetitionRound;
use SpeedPuzzling\Web\Message\ChangeRoundPuzzleReveal;
use SpeedPuzzling\Web\Message\EditCompetitionRound;
use SpeedPuzzling\Web\Message\SetUpEventWizardRound;
use SpeedPuzzling\Web\Query\GetRoundPuzzlesForManagement;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Results\EventWizardProgress;
use SpeedPuzzling\Web\Security\CompetitionToolkitVoter;
use SpeedPuzzling\Web\Services\CompetitionDetailUrl;
use SpeedPuzzling\Web\Services\CompetitionToolkit\CompetitionDisplayLiveUpdates;
use SpeedPuzzling\Web\Services\EventWizard\EventWizardAccess;
use SpeedPuzzling\Web\Services\EventWizard\EventWizardDefaults;
use SpeedPuzzling\Web\Services\EventWizard\EventWizardRefusal;
use SpeedPuzzling\Web\Services\PhotoStash\FormPhotoStash;
use SpeedPuzzling\Web\Services\SecretPuzzleAccess;
use SpeedPuzzling\Web\Services\ZonedDateTimeFormatter;
use SpeedPuzzling\Web\Value\BrandCodeList;
use SpeedPuzzling\Web\Value\EanList;
use SpeedPuzzling\Web\Value\EventWizard\EventWizardStep;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\RoundPuzzleFormat;
use SpeedPuzzling\Web\Value\RoundPuzzleReveal;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\ClickableInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The event creation wizard's "Puzzle & format" on the one-round path (W-creation-wizard-research.md §3.4): who competes
 * (team size only for teams), the time limit, a puzzle from the catalogue or a new one (or "Decide later"), "Keep it
 * secret until the start" with its reveal ("when I start the stopwatch" for whom COMPETITION_TOOLKIT_MANAGE grants;
 * the round's automatic reveal by default), and for a round with several puzzles how many each competitor solves (the
 * toolkit's round format, gated as on the edit round form).
 *
 * Everything is written in one transaction (SetUpEventWizardRound): a refused puzzle leaves the round as it was. The
 * secret puzzle contract of the round puzzles page holds: the round's automatic reveal the page showed travels with the
 * form (`automatic_reveal_at`) and a secret puzzle is added only while the round still has it; another organiser's
 * secret puzzle is refused (SecretPuzzleAccess). The puzzles the round has already are listed - removing one or changing
 * its reveal stays on the round's puzzles page.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class EventWizardPuzzleController extends AbstractController
{
    public function __construct(
        private readonly EventWizardAccess $access,
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
        private readonly CompetitionRoundRepository $competitionRoundRepository,
        private readonly GetRoundPuzzlesForManagement $getRoundPuzzlesForManagement,
        private readonly SecretPuzzleAccess $secretPuzzleAccess,
        private readonly FormPhotoStash $formPhotoStash,
        private readonly ZonedDateTimeFormatter $zonedDateTimeFormatter,
        private readonly CompetitionDisplayLiveUpdates $displayLiveUpdates,
        private readonly CompetitionDetailUrl $competitionDetailUrl,
        private readonly EventWizardDefaults $defaults,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route(
        path: '/{_locale}/new-event/{competitionId}/puzzle',
        name: 'event_wizard_puzzle',
        requirements: ['_locale' => 'en|cs|es|ja|fr|de', 'competitionId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET', 'POST'],
    )]
    public function __invoke(Request $request, string $competitionId, #[CurrentUser] UserInterface $user): Response
    {
        $player = $this->access->player();
        $progress = $this->access->draft($competitionId, EventWizardStep::Puzzle);

        if ($progress === null) {
            return $this->redirectToRoute('edit_competition', ['competitionId' => $competitionId]);
        }

        $roundId = $progress->state?->singleRoundId;

        // A draft without its round yet (made on the classic form): Basics creates it with the start time
        if ($roundId === null) {
            return $this->redirectToRoute('event_wizard_basics_edit', ['competitionId' => $competitionId]);
        }

        $round = $this->competitionRoundRepository->get($roundId);
        $toolkit = $this->isGranted(CompetitionToolkitVoter::COMPETITION_TOOLKIT_MANAGE, $competitionId);
        $hasPuzzles = $progress->state->roundPuzzlesCount > 0;

        $formData = new EventWizardPuzzleFormData(
            category: $round->category,
            teamSize: $round->teamSize,
            minutesLimit: $round->minutesLimit ?? EventWizardDefaults::MINUTES_LIMIT,
            puzzlesToSolve: $round->puzzlesToSolve,
            // The round edit form's reading back (CompetitionRoundFormData): K = N is "Solve all"
            puzzleFormatChoice: match (true) {
                $round->puzzleFormat === RoundPuzzleFormat::Total => CompetitionRoundFormData::FORMAT_CHOICE_TOTAL,
                $round->puzzlesToSolve !== null && $round->puzzlesToSolve >= $progress->state->roundPuzzlesCount => CompetitionRoundFormData::FORMAT_CHOICE_ALL,
                default => CompetitionRoundFormData::FORMAT_CHOICE_PICK,
            },
        );
        $form = $this->createForm(EventWizardPuzzleFormType::class, $formData, [
            'action' => $request->getRequestUri(),
            'competition_id' => $competitionId,
            'stopwatch_reveal' => $toolkit,
            // How the puzzles are solved - a toolkit setting; a round using it keeps it (the edit round form's rule).
            // Asked once the round has a puzzle: the format is meant for the second one and every later one
            'puzzle_format' => $hasPuzzles && ($round->puzzleFormat !== RoundPuzzleFormat::Total || $toolkit),
            'puzzles_count' => $progress->state->roundPuzzlesCount,
        ]);
        // The box photo of a refused submit comes back (FormPhotoStash)
        $restoredPhotos = $this->formPhotoStash->restore($request, $form, $player->playerId);
        $form->handleRequest($request);
        $this->formPhotoStash->reportLost($form, $restoredPhotos);

        // The round's automatic reveal the page named next to "Keep it secret" (a Unix timestamp) - a secret puzzle is
        // added only while the round still has it (AddPuzzleToCompetitionRound); said right away, whatever else the
        // form refuses
        $postedAutomaticRevealAt = (string) $request->request->get('automatic_reveal_at');
        $shownAutomaticRevealAt = preg_match('/^\d{1,12}\z/', $postedAutomaticRevealAt) === 1
            ? new DateTimeImmutable('@' . $postedAutomaticRevealAt)
            : null;

        if (
            $form->isSubmitted()
            && $form->getData()->hasPuzzle()
            && $form->get('hideUntilRoundStarts')->getData() === true
            && $shownAutomaticRevealAt?->getTimestamp() !== $round->automaticRevealAt()->getTimestamp()
        ) {
            $form->get('hideUntilRoundStarts')->addError(new FormError($this->automaticChangedMessage($round)));
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $puzzle = $data->hasPuzzle() ? trim((string) $data->puzzle) : null;

            // Another organiser's secret puzzle is not theirs to use (the picker never offers it)
            if ($puzzle !== null && Uuid::isValid($puzzle)) {
                $this->secretPuzzleAccess->assertVisible($puzzle, alsoWhileImageHidden: true);
            }

            $message = $this->message($round, $competitionId, $data, $puzzle, $user->getUserIdentifier(), $shownAutomaticRevealAt, $form->has('puzzleFormat'));

            try {
                $this->messageBus->dispatch($message);
            } catch (HandlerFailedException | HttpExceptionInterface $failure) {
                // The handlers cleared the entity manager - the round is read again for the page
                $round = $this->competitionRoundRepository->get($roundId);

                if ($this->explain($failure, $form, $round) === false) {
                    throw $failure;
                }

                return $this->page($form, $progress, $round, $restoredPhotos, $player->playerId, $toolkit);
            }

            // After the commit: the event's displays read the round again (competition toolkit D5)
            if ($message->puzzles !== []) {
                $this->displayLiveUpdates->puzzlesChanged($roundId);
            }

            $this->formPhotoStash->forget($restoredPhotos, $player->playerId);

            if (self::clicked($form, 'later')) {
                $this->addFlash('success', $this->translator->trans('event_wizard.flash.saved_for_later'));

                return $this->redirect($this->competitionDetailUrl->of($competitionId), Response::HTTP_SEE_OTHER);
            }

            if (self::clicked($form, 'another')) {
                return $this->redirectToRoute('event_wizard_puzzle', ['competitionId' => $competitionId], Response::HTTP_SEE_OTHER);
            }

            $next = $progress->next(EventWizardStep::Puzzle) ?? EventWizardStep::Review;

            return $this->redirectToRoute($next->route(), ['competitionId' => $competitionId], Response::HTTP_SEE_OTHER);
        }

        return $this->page($form, $progress, $round, $restoredPhotos, $player->playerId, $toolkit);
    }

    private function message(
        CompetitionRound $round,
        string $competitionId,
        EventWizardPuzzleFormData $data,
        null|string $puzzle,
        string $userId,
        null|DateTimeImmutable $shownAutomaticRevealAt,
        bool $formatAsked,
    ): SetUpEventWizardRound {
        $roundId = $round->id->toString();
        assert($data->minutesLimit !== null);

        // The generated name follows the category while nobody renamed the round
        $renamed = $round->name !== $this->defaults->roundName($round->category);

        $roundEdit = new EditCompetitionRound(
            roundId: $roundId,
            competitionId: $competitionId,
            name: $renamed ? $round->name : $this->defaults->roundName($data->category),
            minutesLimit: $data->minutesLimit,
            startsAt: $round->startsAt,
            timezone: $round->timezone ?? $round->displayTimezone(),
            badgeBackgroundColor: $round->badgeBackgroundColor,
            badgeTextColor: $round->badgeTextColor,
            category: $data->category,
            resultsLink: $round->resultsLink,
            keepFields: ['startsAt', 'timezone', 'badgeBackgroundColor', 'badgeTextColor', 'resultsLink', ...($renamed ? ['name'] : [])],
            teamSize: $data->category === RoundCategory::Team ? $data->teamSize : null,
            clearTeamSize: $data->category === RoundCategory::Team && $data->teamSize === null,
        );

        $puzzles = [];
        $reveals = [];

        if ($puzzle !== null) {
            $secret = $data->hideUntilRoundStarts;
            $roundPuzzleId = Uuid::uuid7();
            assert($data->brand !== null);

            $puzzles[] = new AddPuzzleToCompetitionRound(
                roundPuzzleId: $roundPuzzleId,
                roundId: $roundId,
                userId: $userId,
                brand: $data->brand,
                puzzle: $puzzle,
                piecesCount: $data->piecesCount,
                puzzlePhoto: $data->puzzlePhoto,
                eans: EanList::fromInputs($data->puzzleEans),
                brandCodes: BrandCodeList::fromInputs($data->puzzleBrandCodes),
                hideUntilRoundStarts: $secret,
                hideMode: $data->hideMode,
                shownAutomaticRevealAt: $shownAutomaticRevealAt,
            );

            // Added with the automatic reveal the page showed, then switched in the same transaction - nothing is ever
            // visible with the automatic moment in between
            if ($secret && $data->revealMode !== RoundPuzzleReveal::Automatic) {
                $reveals[] = new ChangeRoundPuzzleReveal(
                    roundPuzzleId: $roundPuzzleId->toString(),
                    hideMode: $data->hideMode,
                    revealMode: $data->revealMode,
                    shownAutomaticRevealAt: $shownAutomaticRevealAt,
                );
            }
        }

        $format = null;
        // "Solve all": K = the round's puzzles once this save added its puzzle
        [$puzzleFormat, $toSolve] = $data->chosenFormat($round->roundPuzzles->count() + count($puzzles));

        if ($formatAsked && ($puzzleFormat !== $round->puzzleFormat || $toSolve !== $round->puzzlesToSolve)) {
            $format = new EditCompetitionRound(
                roundId: $roundId,
                competitionId: $competitionId,
                name: $round->name,
                minutesLimit: $data->minutesLimit,
                startsAt: $round->startsAt,
                timezone: $round->timezone ?? $round->displayTimezone(),
                badgeBackgroundColor: $round->badgeBackgroundColor,
                badgeTextColor: $round->badgeTextColor,
                category: $data->category,
                resultsLink: $round->resultsLink,
                // Only the format - the step's other changes are the first message's
                keepFields: EditCompetitionRound::FIELDS,
                puzzleFormat: $puzzleFormat,
                puzzlesToSolve: $toSolve,
            );
        }

        return new SetUpEventWizardRound(
            competitionId: $competitionId,
            round: $roundEdit,
            puzzles: $puzzles,
            reveals: $reveals,
            format: $format,
        );
    }

    /**
     * A refusal the form can explain - false for anything else (it is thrown on). A form error makes render() answer
     * 422.
     *
     * @param FormInterface<EventWizardPuzzleFormData> $form
     */
    private function explain(\Throwable $failure, FormInterface $form, CompetitionRound $round): bool
    {
        $inCategory = EventWizardRefusal::find($failure, PuzzleAlreadyInCompetitionRoundCategory::class);
        if ($inCategory !== null) {
            $form->get('puzzle')->addError(new FormError($this->translator->trans('competition.round.form.puzzle_already_in_category', [
                '%round%' => $inCategory->conflictingRoundName,
            ])));

            return true;
        }

        if (EventWizardRefusal::find($failure, AutomaticRevealChangedMeanwhile::class) !== null) {
            $form->get('hideUntilRoundStarts')->addError(new FormError($this->automaticChangedMessage($round)));

            return true;
        }

        if (EventWizardRefusal::find($failure, PuzzleHiddenByHand::class) !== null) {
            $form->get('puzzle')->addError(new FormError($this->translator->trans('competition.reveal.flash.puzzle_hidden_by_hand')));

            return true;
        }

        if (EventWizardRefusal::find($failure, PuzzleNameAlreadyPublic::class) !== null) {
            $form->get('hideMode')->addError(new FormError($this->translator->trans('competition.reveal.flash.name_already_public')));

            return true;
        }

        if (EventWizardRefusal::find($failure, RoundPuzzleAlreadyRevealed::class) !== null || EventWizardRefusal::find($failure, RevealMomentAlreadyPassed::class) !== null) {
            $form->get('revealMode')->addError(new FormError($this->translator->trans('competition.reveal.flash.time_passed')));

            return true;
        }

        if (EventWizardRefusal::find($failure, RoundStopwatchAlreadyStarted::class) !== null) {
            $form->get('revealMode')->addError(new FormError($this->translator->trans('competition_toolkit.reveal_stopwatch.flash.already_started')));

            return true;
        }

        $format = EventWizardRefusal::find($failure, RoundPuzzleFormatRefused::class);
        if ($format !== null && $form->has('puzzlesToSolve')) {
            $form->get('puzzlesToSolve')->addError(new FormError($this->translator->trans($format->translationKey(), $format->translationParameters())));

            return true;
        }

        $online = EventWizardRefusal::find($failure, OnlineTimingInUse::class);
        if ($online !== null) {
            $form->get('category')->addError(new FormError($this->translator->trans($online->translationKey(), $online->translationParameters())));

            return true;
        }

        $protected = EventWizardRefusal::find($failure, OfficialResultsProtected::class);
        if ($protected !== null) {
            $form->get('category')->addError(new FormError($this->translator->trans($protected->translationKey())));

            return true;
        }

        return false;
    }

    private function automaticChangedMessage(CompetitionRound $round): string
    {
        return $this->translator->trans('competition.reveal.flash.automatic_changed', [
            '%time%' => $this->zonedDateTimeFormatter->format($round->automaticRevealAt(), $round->displayTimezone(), $round->isTimezoneAssumed()),
        ]);
    }

    /**
     * @param FormInterface<EventWizardPuzzleFormData> $form
     * @param array<string, null|string> $restoredPhotos
     */
    private function page(FormInterface $form, EventWizardProgress $progress, CompetitionRound $round, array $restoredPhotos, string $playerId, bool $toolkit): Response
    {
        return $this->render('event_wizard/puzzle.html.twig', [
            'form' => $form,
            'progress' => $progress,
            'step' => EventWizardStep::Puzzle,
            'round' => $round,
            'round_puzzles' => $this->getRoundPuzzlesForManagement->ofRound($round->id->toString()),
            'toolkit' => $toolkit,
            // The round's automatic reveal is over: a secret puzzle added now is revealed at once - the form says so
            'revealed_right_away' => $round->automaticRevealAt() <= $this->clock->now(),
            'kept_photos' => $this->formPhotoStash->keep($form, $restoredPhotos, $playerId),
        ]);
    }

    /**
     * @param FormInterface<EventWizardPuzzleFormData> $form
     */
    private static function clicked(FormInterface $form, string $button): bool
    {
        if ($form->has($button) === false) {
            return false;
        }

        $field = $form->get($button);

        return $field instanceof ClickableInterface && $field->isClicked();
    }
}
