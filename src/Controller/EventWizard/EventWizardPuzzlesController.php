<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\EventWizard;

use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Exceptions\AutomaticRevealChangedMeanwhile;
use SpeedPuzzling\Web\Exceptions\PuzzleAlreadyInCompetitionRound;
use SpeedPuzzling\Web\Exceptions\PuzzleHiddenByHand;
use SpeedPuzzling\Web\Exceptions\PuzzleNameAlreadyPublic;
use SpeedPuzzling\Web\Exceptions\RevealMomentAlreadyPassed;
use SpeedPuzzling\Web\Exceptions\RoundPuzzleAlreadyRevealed;
use SpeedPuzzling\Web\Exceptions\RoundPuzzleFormatRefused;
use SpeedPuzzling\Web\Exceptions\RoundStopwatchAlreadyStarted;
use SpeedPuzzling\Web\FormData\CompetitionRoundFormData;
use SpeedPuzzling\Web\FormData\EventWizardPuzzlesFormData;
use SpeedPuzzling\Web\FormData\EventWizardPuzzlesRowFormData;
use SpeedPuzzling\Web\FormType\EventWizardPuzzlesFormType;
use SpeedPuzzling\Web\FormType\RoundPuzzleFormType;
use SpeedPuzzling\Web\Message\AddPuzzleToCompetitionRound;
use SpeedPuzzling\Web\Exceptions\CompetitionRoundPuzzleNotFound;
use SpeedPuzzling\Web\Message\ChangeRoundUnboxed;
use SpeedPuzzling\Web\Message\ArrangeRoundPuzzles;
use SpeedPuzzling\Web\Message\ChangeRoundPuzzleReveal;
use SpeedPuzzling\Web\Message\EditCompetitionRound;
use SpeedPuzzling\Web\Message\SetUpEventWizardPuzzles;
use SpeedPuzzling\Web\Message\SetUpEventWizardRound;
use SpeedPuzzling\Web\Query\GetRoundPuzzlesForManagement;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Results\EventWizardProgress;
use SpeedPuzzling\Web\Security\CompetitionToolkitVoter;
use SpeedPuzzling\Web\Services\CompetitionDetailUrl;
use SpeedPuzzling\Web\Services\CompetitionToolkit\CompetitionDisplayLiveUpdates;
use SpeedPuzzling\Web\Services\EventWizard\EventWizardAccess;
use SpeedPuzzling\Web\Services\EventWizard\EventWizardDefaults;
use SpeedPuzzling\Web\Services\EventWizard\EventWizardRefusal;
use SpeedPuzzling\Web\Services\SecretPuzzleAccess;
use SpeedPuzzling\Web\Services\ZonedDateTimeFormatter;
use SpeedPuzzling\Web\Value\BrandCodeList;
use SpeedPuzzling\Web\Value\EanList;
use SpeedPuzzling\Web\Value\EventWizard\EventWizardStep;
use SpeedPuzzling\Web\Value\RoundPuzzleFormat;
use SpeedPuzzling\Web\Value\RoundPuzzleReveal;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\ClickableInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
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
 * The event creation wizard's "Puzzles" on the several-rounds path (W-creation-wizard-research.md §3.6): one row per
 * round with W1's puzzle picker inline (RoundPuzzleFormType's brand + puzzle; "Decide later" = left empty), one switch
 * "Keep every puzzle secret until its round starts" with the reveal chosen once (per row "change" overrides it; "when I
 * start the stopwatch" for whom COMPETITION_TOOLKIT_MANAGE grants), "+ another puzzle" per row and "each competitor
 * solves K of them" (the toolkit's round format, the edit round form's gate).
 *
 * One submit for the whole grid, one transaction (SetUpEventWizardPuzzles = W1's SetUpEventWizardRound per row). Checked
 * up front, said on the row - never a 500, never half written:
 * - a round lists each puzzle once (PuzzleAlreadyInCompetitionRound): chosen twice on one row, or already in that round.
 *   Rounds of one category may share a puzzle (shared-puzzles-and-divisions.md - a solving time's round then follows
 *   from each player's entry); the grid says so under a saved puzzle ("Same puzzle as …");
 * - the secret puzzle contract of W1's step, per row: the round's automatic reveal the page showed travels with the form
 *   (`automatic_reveal_at[<round id>]`) and a secret puzzle is added only while the round still has it; another
 *   organiser's secret puzzle is refused (SecretPuzzleAccess);
 * - K out of range.
 *
 * Without JavaScript "+ another puzzle" is a submit (the grid is saved, then shown again at the row); a new puzzle's
 * photo and codes are added on the round's own puzzle form (linked per row).
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class EventWizardPuzzlesController extends AbstractController
{
    public const string FORM = 'event_wizard_puzzles';

    // The Challenges template's plan (spws-wizard.md): `?challenges[<round id>]=3,4` - a prefill only, never state
    public const string CHALLENGES = 'challenges';

    public function __construct(
        private readonly EventWizardAccess $access,
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
        private readonly FormFactoryInterface $formFactory,
        private readonly CompetitionRepository $competitionRepository,
        private readonly CompetitionRoundRepository $competitionRoundRepository,
        private readonly GetRoundPuzzlesForManagement $getRoundPuzzlesForManagement,
        private readonly SecretPuzzleAccess $secretPuzzleAccess,
        private readonly ZonedDateTimeFormatter $zonedDateTimeFormatter,
        private readonly CompetitionDisplayLiveUpdates $displayLiveUpdates,
        private readonly CompetitionDetailUrl $competitionDetailUrl,
        private readonly RoundPuzzleFormType $roundPuzzleFormType,
        private readonly EventWizardDefaults $defaults,
    ) {
    }

    #[Route(
        path: '/{_locale}/new-event/{competitionId}/puzzles',
        name: 'event_wizard_puzzles',
        requirements: ['_locale' => 'en|cs|es|ja|fr|de', 'competitionId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET', 'POST'],
    )]
    public function __invoke(Request $request, string $competitionId, #[CurrentUser] UserInterface $user): Response
    {
        $this->access->player();
        $progress = $this->access->draft($competitionId, EventWizardStep::Puzzles);

        if ($progress === null) {
            return $this->redirectToRoute('edit_competition', ['competitionId' => $competitionId]);
        }

        $competition = $this->competitionRepository->get($competitionId);
        $rounds = $this->competitionRoundRepository->ofCompetition($competition);
        $toolkit = $this->isGranted(CompetitionToolkitVoter::COMPETITION_TOOLKIT_MANAGE, $competitionId);
        $challenges = $toolkit ? self::challengesOf($request) : [];
        $form = $this->createGrid($competitionId, $rounds, $toolkit, $challenges);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            $this->checkUpFront($form, $request, $rounds);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $message = $this->message($form, $request, $competitionId, $rounds, $user->getUserIdentifier());

            if ($message->rounds !== []) {
                try {
                    $this->messageBus->dispatch($message);
                } catch (HandlerFailedException | HttpExceptionInterface $failure) {
                    if ($this->explain($failure, $form) === false) {
                        throw $failure;
                    }

                    // The handlers cleared the entity manager - the rounds are read again for the page
                    return $this->page($form, $progress, $this->competitionRoundRepository->ofCompetition($this->competitionRepository->get($competitionId)), $toolkit, $competitionId, $challenges);
                }

                // After the commit: the event's displays read the rounds again (competition toolkit D5)
                foreach ($message->rounds as $round) {
                    if ($round->puzzles !== []) {
                        $this->displayLiveUpdates->puzzlesChanged($round->round->roundId);
                    }
                }
            }

            $another = $request->request->getString('another');

            if ($another !== '' && Uuid::isValid($another)) {
                return $this->redirectToRoute('event_wizard_puzzles', array_filter([
                    'competitionId' => $competitionId,
                    'another' => strtolower($another),
                    // The Challenges plan stays for the rows not filled in yet (spws-wizard.md)
                    self::CHALLENGES => array_map(static fn (array $numbers): string => implode(',', $numbers), $challenges),
                    '_fragment' => 'round-' . strtolower($another),
                ], static fn (mixed $value): bool => $value !== []), Response::HTTP_SEE_OTHER);
            }

            $later = $form->get('later');
            if ($later instanceof ClickableInterface && $later->isClicked()) {
                $this->addFlash('success', $this->translator->trans('event_wizard.flash.saved_for_later'));

                return $this->redirect($this->competitionDetailUrl->of($competitionId), Response::HTTP_SEE_OTHER);
            }

            $next = $progress->next(EventWizardStep::Puzzles) ?? EventWizardStep::Review;

            return $this->redirectToRoute($next->route(), ['competitionId' => $competitionId], Response::HTTP_SEE_OTHER);
        }

        return $this->page($form, $progress, $rounds, $toolkit, $competitionId, $challenges);
    }

    /**
     * `?challenges[<round id>]=3,4` (and on the form's action, so a refused submit keeps it) - the Challenges template's
     * challenge numbers per round; anything else is ignored
     *
     * @return array<string, non-empty-list<int>>
     */
    private static function challengesOf(Request $request): array
    {
        $challenges = [];
        $given = $request->query->all(self::CHALLENGES);

        foreach ($given as $roundId => $numbers) {
            if (is_string($roundId) === false || Uuid::isValid($roundId) === false || is_string($numbers) === false) {
                continue;
            }

            $list = [];
            foreach (explode(',', $numbers) as $number) {
                if (preg_match('/^\d{1,3}$/', $number) === 1 && (int) $number > 0) {
                    $list[] = (int) $number;
                }
            }

            if ($list !== [] && count($list) <= EventWizardPuzzlesRowFormData::SLOTS) {
                $challenges[strtolower($roundId)] = $list;
            }
        }

        return $challenges;
    }

    /**
     * @param list<CompetitionRound> $rounds
     * @param array<string, non-empty-list<int>> $challenges the Challenges template's plan - prefills a round without
     *     puzzles yet: "Challenge k" labels and "Solve all n"
     * @return FormInterface<EventWizardPuzzlesFormData>
     */
    private function createGrid(string $competitionId, array $rounds, bool $toolkit, array $challenges = []): FormInterface
    {
        $rows = [];
        $rowOptions = [];
        $puzzlesCounts = [];

        foreach ($rounds as $round) {
            $roundId = $round->id->toString();
            $puzzlesCounts[$roundId] = $round->roundPuzzles->count();
            $rows[$roundId] = new EventWizardPuzzlesRowFormData();
            $rows[$roundId]->puzzlesToSolve = $round->puzzleFormat === RoundPuzzleFormat::Pick ? $round->puzzlesToSolve : null;
            // The round edit form's reading back (CompetitionRoundFormData): K = N is "Solve all"
            $rows[$roundId]->puzzleFormatChoice = match (true) {
                $round->puzzleFormat === RoundPuzzleFormat::Total => CompetitionRoundFormData::FORMAT_CHOICE_TOTAL,
                $round->puzzlesToSolve !== null && $round->puzzlesToSolve >= $puzzlesCounts[$roundId] => CompetitionRoundFormData::FORMAT_CHOICE_ALL,
                default => CompetitionRoundFormData::FORMAT_CHOICE_PICK,
            };
            // The round's format - a toolkit setting; a round using it keeps it (the edit round form's rule)
            $rowOptions[$roundId] = $toolkit || $round->puzzleFormat !== RoundPuzzleFormat::Total;

            // The Challenges plan of a round without puzzles yet: its challenges' labels, several = solved back to back
            $plan = $challenges[$roundId] ?? [];
            if ($plan !== [] && $puzzlesCounts[$roundId] === 0) {
                foreach ($plan as $index => $number) {
                    $rows[$roundId]->labels[$index] = $this->defaults->challengeLabel($number);
                }

                if (count($plan) > 1) {
                    $rows[$roundId]->puzzleFormatChoice = CompetitionRoundFormData::FORMAT_CHOICE_ALL;
                }
            }
        }

        $data = new EventWizardPuzzlesFormData($rows);
        // Most competitions keep their puzzles secret once they have one secret already
        $data->secret = $this->anySecret($rounds);

        return $this->formFactory->createNamed(self::FORM, EventWizardPuzzlesFormType::class, $data, [
            'action' => $this->generateUrl('event_wizard_puzzles', array_filter([
                'competitionId' => $competitionId,
                self::CHALLENGES => array_map(static fn (array $numbers): string => implode(',', $numbers), $challenges),
            ], static fn (mixed $value): bool => $value !== [])),
            'competition_id' => $competitionId,
            'stopwatch_reveal' => $toolkit,
            'slot_extras' => $toolkit,
            'rows' => $rowOptions,
            'puzzles_counts' => $puzzlesCounts,
        ]);
    }

    /**
     * The checks that need the rounds as they are now - added as row errors, so the form is invalid and nothing is
     * written: a puzzle twice in one round, a moved automatic reveal of a row with a secret puzzle, another
     * organiser's secret puzzle (refused outright), K out of range.
     *
     * @param FormInterface<EventWizardPuzzlesFormData> $form
     * @param list<CompetitionRound> $rounds
     */
    private function checkUpFront(FormInterface $form, Request $request, array $rounds): void
    {
        $data = $form->getData();

        foreach ($rounds as $round) {
            $roundId = $round->id->toString();
            $row = $data->rows[$roundId] ?? null;

            if ($row === null) {
                continue;
            }

            $rowForm = $form->get('rows')->get($roundId);
            $existing = $this->getRoundPuzzlesForManagement->ofRound($roundId);
            $existingIds = array_map(static fn ($puzzle): string => strtolower($puzzle->puzzleId), $existing);

            /** @var array<string, true> $inRound the round's puzzles - saved, or chosen on an earlier slot of the row */
            $inRound = array_fill_keys($existingIds, true);

            foreach ($row->puzzles as $index => $slot) {
                $puzzleId = $slot->catalogueId();

                if ($puzzleId === null) {
                    continue;
                }

                // Another organiser's secret puzzle is not theirs to use (the picker never offers it)
                $this->secretPuzzleAccess->assertVisible($puzzleId, alsoWhileImageHidden: true);

                // A round lists each puzzle once - another row of the same category may have it (shared puzzles)
                if (isset($inRound[$puzzleId])) {
                    $rowForm->get('puzzles')->get((string) $index)->get('puzzle')->addError(new FormError($this->translator->trans('competition.round_puzzles.already_in_round', [
                        '%round%' => $round->name,
                    ])));

                    continue;
                }

                $inRound[$puzzleId] = true;
            }

            $chosen = count($row->chosen());

            if ($chosen > 0 && $this->secretFor($data, $row) !== null && $this->shownAutomaticRevealAt($request, $roundId)?->getTimestamp() !== $round->automaticRevealAt()->getTimestamp()) {
                $rowForm->get('override')->addError(new FormError($this->automaticChangedMessage($round)));
            }

            if ($rowForm->has('puzzlesToSolve') && $row->puzzleFormatChoice === CompetitionRoundFormData::FORMAT_CHOICE_PICK && $row->puzzlesToSolve !== null && $row->puzzlesToSolve > count($existing) + $chosen) {
                $rowForm->get('puzzlesToSolve')->addError(new FormError($this->translator->trans('event_wizard.puzzles.to_solve_too_many', [
                    '%count%' => count($existing) + $chosen,
                ])));
            }
        }
    }

    /**
     * @param FormInterface<EventWizardPuzzlesFormData> $form
     * @param list<CompetitionRound> $rounds
     */
    private function message(FormInterface $form, Request $request, string $competitionId, array $rounds, string $userId): SetUpEventWizardPuzzles
    {
        $data = $form->getData();
        $setUps = [];

        foreach ($rounds as $round) {
            $roundId = $round->id->toString();
            $row = $data->rows[$roundId] ?? null;

            if ($row === null) {
                continue;
            }

            $reveal = $this->secretFor($data, $row);
            $shownAutomaticRevealAt = $this->shownAutomaticRevealAt($request, $roundId);
            $puzzles = [];
            $reveals = [];

            /** @var array<string, string> $labels new round puzzle id => its typed label */
            $labels = [];
            /** @var list<string> $unboxed new round puzzle ids ticked "Unboxed" */
            $unboxed = [];

            foreach ($row->puzzles as $index => $slot) {
                if ($slot->hasPuzzle() === false) {
                    continue;
                }

                $roundPuzzleId = Uuid::uuid7();

                $label = trim((string) ($row->labels[$index] ?? ''));
                if ($label !== '') {
                    $labels[$roundPuzzleId->toString()] = $label;
                }

                if ($row->unboxedSlots[$index] ?? false) {
                    $unboxed[] = $roundPuzzleId->toString();
                }

                $puzzles[] = new AddPuzzleToCompetitionRound(
                    roundPuzzleId: $roundPuzzleId,
                    roundId: $roundId,
                    userId: $userId,
                    brand: trim((string) $slot->brand),
                    puzzle: trim((string) $slot->puzzle),
                    piecesCount: $slot->piecesCount,
                    puzzlePhoto: null,
                    eans: EanList::fromInputs([]),
                    brandCodes: BrandCodeList::fromInputs([]),
                    hideUntilRoundStarts: $reveal !== null,
                    hideMode: $data->hideMode,
                    shownAutomaticRevealAt: $shownAutomaticRevealAt,
                );

                // Added with the automatic reveal the page showed, then switched in the same transaction - nothing is
                // ever visible with the automatic moment in between (W1's rule)
                if ($reveal !== null && $reveal !== RoundPuzzleReveal::Automatic) {
                    $reveals[] = new ChangeRoundPuzzleReveal(
                        roundPuzzleId: $roundPuzzleId->toString(),
                        hideMode: $data->hideMode,
                        revealMode: $reveal,
                        shownAutomaticRevealAt: $shownAutomaticRevealAt,
                    );
                }
            }

            $format = $this->formatChange($round, $competitionId, $row, $form->get('rows')->get($roundId)->has('puzzleFormat'), $round->roundPuzzles->count() + count($puzzles));

            if ($puzzles === [] && $format === null) {
                continue;
            }

            $setUps[] = new SetUpEventWizardRound(
                competitionId: $competitionId,
                // Nothing of the round itself changes on this step - every field kept as it is under the lock
                round: self::roundEdit($round, $competitionId, EditCompetitionRound::FIELDS),
                puzzles: $puzzles,
                reveals: $reveals,
                format: $format,
                // No ids listed: the saved puzzles keep their order, the new ones follow in slot order (spws-wizard.md)
                arrange: $labels !== [] ? new ArrangeRoundPuzzles($roundId, $competitionId, [], $labels) : null,
                unboxed: self::unboxedChange($round, $competitionId, $unboxed, count($puzzles)),
            );
        }

        return new SetUpEventWizardPuzzles($competitionId, $setUps);
    }

    /**
     * The new puzzles ticked "Unboxed" (spws-wizard.md): with the round's already unboxed ones, or the round's switch when
     * every puzzle of it is unboxed then (so a duplicate keeps it); nothing ticked, or the whole round unboxed already =
     * nothing to send (a plain bool could not say "keep" the switch)
     *
     * @param list<string> $ticked
     */
    private static function unboxedChange(CompetitionRound $round, string $competitionId, array $ticked, int $added): null|ChangeRoundUnboxed
    {
        if ($ticked === [] || $round->unboxed) {
            return null;
        }

        $already = [];
        foreach ($round->roundPuzzles as $roundPuzzle) {
            if ($roundPuzzle->unboxed) {
                $already[] = $roundPuzzle->id->toString();
            }
        }

        if (count($already) === $round->roundPuzzles->count() && count($ticked) === $added) {
            return new ChangeRoundUnboxed($competitionId, $round->id->toString(), true, []);
        }

        return new ChangeRoundUnboxed($competitionId, $round->id->toString(), false, [...$already, ...$ticked]);
    }

    private function formatChange(CompetitionRound $round, string $competitionId, EventWizardPuzzlesRowFormData $row, bool $asked, int $puzzlesAfterSave): null|EditCompetitionRound
    {
        // A round still without puzzles ("Decide later") keeps its format - "Solve all" prefilled by the Challenges plan
        // (spws-wizard.md) waits for its puzzles
        if ($asked === false || ($puzzlesAfterSave === 0 && $row->puzzleFormatChoice === CompetitionRoundFormData::FORMAT_CHOICE_ALL)) {
            return null;
        }

        // "Solve all": K = the round's puzzles once this save added the row's
        [$format, $toSolve] = match ($row->puzzleFormatChoice) {
            CompetitionRoundFormData::FORMAT_CHOICE_ALL => [RoundPuzzleFormat::Pick, max(1, $puzzlesAfterSave)],
            CompetitionRoundFormData::FORMAT_CHOICE_PICK => [RoundPuzzleFormat::Pick, $row->puzzlesToSolve],
            default => [RoundPuzzleFormat::Total, null],
        };

        if ($format === $round->puzzleFormat && $toSolve === $round->puzzlesToSolve) {
            return null;
        }

        return new EditCompetitionRound(
            roundId: $round->id->toString(),
            competitionId: $competitionId,
            name: $round->name,
            minutesLimit: $round->minutesLimit ?? EventWizardDefaults::MINUTES_LIMIT,
            startsAt: $round->startsAt,
            timezone: $round->timezone ?? $round->displayTimezone(),
            badgeBackgroundColor: $round->badgeBackgroundColor,
            badgeTextColor: $round->badgeTextColor,
            category: $round->category,
            resultsLink: $round->resultsLink,
            keepFields: EditCompetitionRound::FIELDS,
            puzzleFormat: $format,
            puzzlesToSolve: $toSolve,
        );
    }

    /**
     * @param list<string> $keepFields
     */
    private static function roundEdit(CompetitionRound $round, string $competitionId, array $keepFields): EditCompetitionRound
    {
        return new EditCompetitionRound(
            roundId: $round->id->toString(),
            competitionId: $competitionId,
            name: $round->name,
            minutesLimit: $round->minutesLimit ?? EventWizardDefaults::MINUTES_LIMIT,
            startsAt: $round->startsAt,
            timezone: $round->timezone ?? $round->displayTimezone(),
            badgeBackgroundColor: $round->badgeBackgroundColor,
            badgeTextColor: $round->badgeTextColor,
            category: $round->category,
            resultsLink: $round->resultsLink,
            keepFields: $keepFields,
        );
    }

    /**
     * How the row's new puzzles are revealed - null = not secret
     */
    private function secretFor(EventWizardPuzzlesFormData $data, EventWizardPuzzlesRowFormData $row): null|RoundPuzzleReveal
    {
        if ($row->override === 'public') {
            return null;
        }

        $override = RoundPuzzleReveal::tryFrom($row->override);

        if ($override !== null) {
            return $override;
        }

        return $data->secret ? $data->revealMode : null;
    }

    private function shownAutomaticRevealAt(Request $request, string $roundId): null|DateTimeImmutable
    {
        $posted = $request->request->all('automatic_reveal_at')[$roundId] ?? null;

        return is_string($posted) && preg_match('/^\d{1,12}\z/', $posted) === 1 ? new DateTimeImmutable('@' . $posted) : null;
    }

    /**
     * @param list<CompetitionRound> $rounds
     */
    private function anySecret(array $rounds): bool
    {
        foreach ($rounds as $round) {
            foreach ($round->roundPuzzles as $roundPuzzle) {
                if ($roundPuzzle->hideUntilRoundStarts) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * A refusal of the transaction the up-front checks could not see coming (another tab changed a round meanwhile) -
     * said above the grid; false for anything else (it is thrown on). The form error makes render() answer 422.
     *
     * @param FormInterface<EventWizardPuzzlesFormData> $form
     */
    private function explain(\Throwable $failure, FormInterface $form): bool
    {
        $inRound = EventWizardRefusal::find($failure, PuzzleAlreadyInCompetitionRound::class);
        if ($inRound !== null) {
            $form->addError(new FormError($this->translator->trans('competition.round_puzzles.already_in_round', ['%round%' => $inRound->roundName])));

            return true;
        }

        $messages = [
            AutomaticRevealChangedMeanwhile::class => 'event_wizard.puzzles.reveal_changed',
            PuzzleHiddenByHand::class => 'competition.reveal.flash.puzzle_hidden_by_hand',
            PuzzleNameAlreadyPublic::class => 'competition.reveal.flash.name_already_public',
            RoundPuzzleAlreadyRevealed::class => 'competition.reveal.flash.time_passed',
            RevealMomentAlreadyPassed::class => 'competition.reveal.flash.time_passed',
            RoundStopwatchAlreadyStarted::class => 'competition_toolkit.reveal_stopwatch.flash.already_started',
        ];

        foreach ($messages as $class => $key) {
            if (EventWizardRefusal::find($failure, $class) !== null) {
                $form->addError(new FormError($this->translator->trans($key)));

                return true;
            }
        }

        // A puzzle unboxed before was removed meanwhile (another tab) - nothing was written
        if (EventWizardRefusal::find($failure, CompetitionRoundPuzzleNotFound::class) !== null) {
            $form->addError(new FormError($this->translator->trans('competition.round_puzzles.unboxed.puzzle_gone')));

            return true;
        }

        $format = EventWizardRefusal::find($failure, RoundPuzzleFormatRefused::class);
        if ($format !== null) {
            $form->addError(new FormError($this->translator->trans($format->translationKey(), $format->translationParameters())));

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
     * @param FormInterface<EventWizardPuzzlesFormData> $form
     * @param list<CompetitionRound> $rounds
     * @param array<string, non-empty-list<int>> $challenges
     */
    private function page(FormInterface $form, EventWizardProgress $progress, array $rounds, bool $toolkit, string $competitionId, array $challenges = []): Response
    {
        $roundPuzzles = [];

        foreach ($rounds as $round) {
            $roundPuzzles[$round->id->toString()] = $this->getRoundPuzzlesForManagement->ofRound($round->id->toString());
        }

        return $this->render('event_wizard/puzzles.html.twig', [
            'form' => $form,
            'progress' => $progress,
            'step' => EventWizardStep::Puzzles,
            'competition_id' => $competitionId,
            'rounds' => $rounds,
            'round_puzzles' => $roundPuzzles,
            'toolkit' => $toolkit,
            // The Challenges plan: how many slots a round opens with (spws-wizard.md)
            'open_slots' => array_map(count(...), $challenges),
            // The brands of every picker, handed over once (event_wizard_puzzles_controller.js)
            'brand_choices' => $this->roundPuzzleFormType->brandChoices($competitionId),
        ]);
    }
}
