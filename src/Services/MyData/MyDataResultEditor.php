<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\MyData;

use DateTimeImmutable;
use SpeedPuzzling\Web\Exceptions\CanNotAssembleEmptyGroup;
use SpeedPuzzling\Web\Exceptions\FirstTryAlreadyTaken;
use SpeedPuzzling\Web\Exceptions\PuzzleSolvingTimeNotFound;
use SpeedPuzzling\Web\Exceptions\SuspiciousPpm;
use SpeedPuzzling\Web\Message\EditPuzzleSolvingTime;
use SpeedPuzzling\Web\Message\RecordDuplicatePrevention;
use SpeedPuzzling\Web\Query\GetPlayerSolvedPuzzles;
use SpeedPuzzling\Web\Results\MyData\MyDataResultEdit;
use SpeedPuzzling\Web\Results\MyData\MyDataResultEditOutcome;
use SpeedPuzzling\Web\Results\PlayerProfile;
use SpeedPuzzling\Web\Results\SolvedPuzzleDetail;
use SpeedPuzzling\Web\Services\FirstTry\FirstTryFormCheck;
use SpeedPuzzling\Web\Services\SuspiciousTimes\SuspiciousTimeFormCheck;
use SpeedPuzzling\Web\Value\CompetitionPick;
use SpeedPuzzling\Web\Value\DatePrecision;
use SpeedPuzzling\Web\Value\DuplicatePreventionKind;
use SpeedPuzzling\Web\Value\FirstTryResolution;
use SpeedPuzzling\Web\Value\Puzzler;
use SpeedPuzzling\Web\Value\ResultEntryCheck;
use SpeedPuzzling\Web\Value\SolvingTime;
use SpeedPuzzling\Web\Value\SolvingTimeSource;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Editing one result from "My data" (docs/features/my-data.md "Editing one row") - the row sheet and a cell edited in
 * place both save here. **It is the edit form's save for the fields My data edits**: the same checks in the same
 * order as EditTimeController, asked of the same services, answered in the same words, and then the edit form's own
 * message (`EditPuzzleSolvingTime`) with everything else of the result as it is stored.
 *
 *  1. the time (0-999 h, 0-59 min, 0-59 s, never empty for a result with a time) and the day (needed for a result
 *     with a time) - the form's rules;
 *  2. `FirstTryFormCheck::forEditedResult()` - the same time saved already (asked, and the warning recorded like the
 *     form records it), then the first-try rules;
 *  3. `SuspiciousTimeFormCheck::forEditedResult()` - a time far off the tracker's own needs "Yes, it's right";
 *  4. the message; the handler's own refusals (a first try taken meanwhile, an impossible pace) in the form's words.
 *
 * Who solved the result never changes here: the co-puzzlers are the stored ones, read the way the edit form reads
 * them when it opens. `MyDataEditParityTest` runs the same cases through the edit form and through this.
 */
readonly final class MyDataResultEditor
{
    public function __construct(
        private GetPlayerSolvedPuzzles $getPlayerSolvedPuzzles,
        private FirstTryFormCheck $firstTryFormCheck,
        private SuspiciousTimeFormCheck $suspiciousTimeFormCheck,
        private MessageBusInterface $messageBus,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * A result of the viewer's own table: one they tracked or are a registered member of. Anybody else's is not there.
     *
     * @throws PuzzleSolvingTimeNotFound
     */
    public function rowOf(PlayerProfile $viewer, string $timeId): SolvedPuzzleDetail
    {
        $time = $this->getPlayerSolvedPuzzles->byTimeId($timeId);

        if ($time->playerId !== $viewer->playerId && Puzzler::listContainsPlayer($time->players, $viewer->playerId) === false) {
            throw new PuzzleSolvingTimeNotFound();
        }

        return $time;
    }

    /**
     * The co-puzzler inputs of the stored result, as the edit form fills them when it opens.
     *
     * @return list<string>
     */
    public static function storedGroupPlayers(SolvedPuzzleDetail $time): array
    {
        $groupPlayers = [];

        foreach ($time->players ?? [] as $groupPlayer) {
            $groupPlayers[] = $groupPlayer->playerCode ? "#$groupPlayer->playerCode" : $groupPlayer->playerName ?? '';
        }

        return $groupPlayers;
    }

    /**
     * @param string $paceAnswer the key "Yes, it's right" was chosen for (PaceFormCheck) - '' = not answered
     */
    public function save(
        PlayerProfile $viewer,
        SolvedPuzzleDetail $time,
        MyDataResultEdit $edit,
        FirstTryResolution $firstTryResolution = FirstTryResolution::None,
        bool $duplicateConfirmed = false,
        string $paceAnswer = '',
    ): MyDataResultEditOutcome {
        // Only who may edit it today (SolvedPuzzleDetail::isEditableBy() - never an imported event result); the
        // handler checks again
        if ($time->isEditableBy($viewer->playerId) === false || $viewer->userId === null) {
            return self::refused(['' => $this->translator->trans('my_data.edit.read_only')]);
        }

        if ($edit->equals(MyDataResultEdit::ofStored($time))) {
            return MyDataResultEditOutcome::nothingToSave();
        }

        $timed = $time->time !== null;
        $errors = [];
        $seconds = null;

        if ($timed) {
            $seconds = $this->seconds($edit);

            if ($seconds === null) {
                $errors[MyDataResultEdit::TIME] = $this->translator->trans('my_data.edit.time_invalid');
            } elseif ($seconds === 0) {
                $errors[MyDataResultEdit::TIME] = $this->translator->trans('forms.time_required');
            }
        }

        // The day typed (null = none): the checks fall back to the stored day themselves, like for the edit form
        $typedDay = null;
        $finishedAt = $time->finishedAt;

        if (trim($edit->day) !== '') {
            $typedDay = self::day($edit->day);

            if ($typedDay === null) {
                $errors[MyDataResultEdit::DATE] = $this->translator->trans('Please enter a valid date.', domain: 'validators');
            } elseif ($time->finishedAt === null || $time->finishedAt->format('Y-m-d') !== $typedDay->format('Y-m-d')) {
                // Another day: exact from now on (PuzzleSolvingTime::modify()). The same day keeps its stored moment
                $finishedAt = $typedDay;
            }
        } elseif ($time->datePrecision === DatePrecision::Day) {
            // No day typed for a result that had an exact one - a result that knows only its month or year keeps it
            $finishedAt = null;

            if ($timed) {
                $errors[MyDataResultEdit::DATE] = $this->translator->trans('finished_at_required_for_speed', domain: 'validators');
            }
        }

        if ($errors !== []) {
            return self::refused($errors);
        }

        $groupPlayers = self::storedGroupPlayers($time);
        $asksAQuestion = false;

        // docs/features/first-try-integrity.md + docs/features/duplicate-results.md (Layer 2)
        $check = $this->firstTryFormCheck->forEditedResult(
            $viewer->playerId,
            $time,
            $groupPlayers,
            $typedDay,
            $edit->firstAttempt,
            $seconds,
            $time->puzzleId,
        );

        if ($check->duplicateBlocks($duplicateConfirmed)) {
            $errors[''] = $this->translator->trans('duplicate_check.form_error');
            $asksAQuestion = true;

            $this->messageBus->dispatch(new RecordDuplicatePrevention(
                playerId: $viewer->playerId,
                kind: DuplicatePreventionKind::WarningShown,
                timeId: $time->timeId,
                puzzleId: $time->puzzleId,
                via: SolvingTimeSource::Form,
            ));
        } elseif ($check->firstTryBlocks($firstTryResolution, $duplicateConfirmed)) {
            $errors[''] = $this->translator->trans('first_try.form_error');
            $asksAQuestion = true;
        }

        // Time verification, the form's question (docs/features/suspicious-time-review.md, "Catch it while typing")
        $paceCheck = null;
        $paceConfirmed = false;

        if ($seconds !== null) {
            $paceCheck = $this->suspiciousTimeFormCheck->forEditedResult(
                $viewer->playerId,
                $viewer->code,
                $time,
                $groupPlayers,
                $typedDay,
                $seconds,
                $time->puzzleId,
            );

            $paceConfirmed = $paceCheck?->isConfirmedBy($paceAnswer) === true;

            if ($paceCheck?->isRaised() === true && $paceConfirmed === false) {
                $errors[MyDataResultEdit::TIME] = $this->translator->trans('suspicious_time.form.error');
                $asksAQuestion = true;
            }
        }

        if ($errors !== []) {
            return new MyDataResultEditOutcome(false, $errors, $check, $paceCheck, $paceConfirmed, $check->firstTry, $asksAQuestion);
        }

        // What the result is linked to stays (CompetitionPick::ofTime() - what the edit form's picker would send back)
        $competitionPick = CompetitionPick::ofTime($time->competitionId, $time->seriesPickId, $time->competitionIsEdition);
        $comment = trim($edit->comment);

        try {
            $this->messageBus->dispatch(new EditPuzzleSolvingTime(
                currentUserId: $viewer->userId,
                puzzleSolvingTimeId: $time->timeId,
                competitionId: $competitionPick?->competitionId(),
                time: $seconds !== null ? new SolvingTime($seconds)->toTimeString() : null,
                comment: $comment !== '' ? $comment : null,
                groupPlayers: $groupPlayers,
                finishedAt: $finishedAt,
                finishedPuzzlesPhoto: null,
                firstAttempt: $edit->firstAttempt,
                unboxed: $time->unboxed,
                firstTryResolution: $firstTryResolution,
                // Only an answer to a same-day twin the check found counts as "saved anyway"
                duplicateConfirmed: $duplicateConfirmed && $check->duplicates?->needsConfirmation() === true,
                puzzleId: $time->puzzleId,
                // Only an answer to a time the check raised is a confirmation
                paceConfirmedExpectedSeconds: $paceCheck !== null && $paceConfirmed ? SuspiciousTimeFormCheck::confirmedExpectation($paceCheck->assessment) : null,
                seriesId: $competitionPick?->seriesId(),
            ));
        } catch (HandlerFailedException $exception) {
            $refusal = $exception->getPrevious();

            if ($refusal instanceof FirstTryAlreadyTaken) {
                // Somebody saved a first try in the meantime - the notice explains what the handler saw
                return new MyDataResultEditOutcome(false, ['' => $this->translator->trans('first_try.form_error')], $check, $paceCheck, $paceConfirmed, $refusal->assessment, true);
            }

            if ($refusal instanceof CanNotAssembleEmptyGroup) {
                return new MyDataResultEditOutcome(false, ['' => $this->translator->trans('forms.empty_group_error')], $check, $paceCheck, $paceConfirmed, $check->firstTry);
            }

            if ($refusal instanceof SuspiciousPpm) {
                return new MyDataResultEditOutcome(false, [MyDataResultEdit::TIME => $this->translator->trans('forms.too_high_ppm')], $check, $paceCheck, $paceConfirmed, $check->firstTry);
            }

            throw $exception;
        }

        return new MyDataResultEditOutcome(true, [], $check, $paceCheck, $paceConfirmed, $check->firstTry);
    }

    /**
     * The edit form's three boxes: whole numbers, 0-999 hours, 0-59 minutes, 0-59 seconds (an empty box is 0).
     */
    private function seconds(MyDataResultEdit $edit): null|int
    {
        $parts = [];

        foreach ([[$edit->hours, 999], [$edit->minutes, 59], [$edit->seconds, 59]] as [$typed, $max]) {
            $typed = trim($typed);

            if ($typed === '') {
                $typed = '0';
            }

            if (preg_match('/^\d{1,3}$/', $typed) !== 1 || (int) $typed > $max) {
                return null;
            }

            $parts[] = (int) $typed;
        }

        return $parts[0] * 3600 + $parts[1] * 60 + $parts[2];
    }

    /**
     * `d.m.Y` as the edit form's date field reads it: midnight of a day that exists. A two-digit year stays as typed -
     * the handler reads it like the form's (MistypedYearNormalizer).
     */
    private static function day(string $typed): null|DateTimeImmutable
    {
        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{1,4})$/', trim($typed), $parts) !== 1) {
            return null;
        }

        if ((int) $parts[3] === 0 || checkdate((int) $parts[2], (int) $parts[1], (int) $parts[3]) === false) {
            return null;
        }

        return new DateTimeImmutable(sprintf('%04d-%02d-%02d 00:00:00', (int) $parts[3], (int) $parts[2], (int) $parts[1]));
    }

    /**
     * @param array<string, string> $errors
     */
    private static function refused(array $errors): MyDataResultEditOutcome
    {
        return new MyDataResultEditOutcome(false, $errors, ResultEntryCheck::nothing(), null, false, null);
    }
}
