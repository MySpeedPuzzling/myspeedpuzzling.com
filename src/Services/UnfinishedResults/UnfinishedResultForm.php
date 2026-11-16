<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\UnfinishedResults;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\InvalidPiecesPlaced;
use SpeedPuzzling\Web\Exceptions\UnfinishedResultNeedsTimeLimit;
use SpeedPuzzling\Web\Exceptions\UnfinishedTotalTimeWithinLimit;
use SpeedPuzzling\Web\FormType\UnfinishedResultFields;
use SpeedPuzzling\Web\Results\UnfinishedFormState;
use SpeedPuzzling\Web\Value\PuzzleAddMode;
use SpeedPuzzling\Web\Value\UnfinishedResult;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "I didn't finish within the time limit" in the add and the edit-time form (docs/features/competitions-management/
 * unfinished-results-plan.md, step 4): the state the form is rendered with, and the checks of an unfinished submit made
 * before anything is dispatched - by the same rule the handler applies afterwards (UnfinishedResult with the round
 * UnfinishedResultHint finds), so a refusal lands on its field and a refused save leaves nothing behind.
 *
 * A form without a competition costs no statement: nothing to resolve.
 */
readonly final class UnfinishedResultForm
{
    public function __construct(
        private UnfinishedResultHint $hint,
        private Connection $database,
        private TranslatorInterface $translator,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->hint->isEnabled();
    }

    /**
     * Null = the form shows nothing of it: the kill switch is off and the result is no unfinished one.
     *
     * @param array<string> $groupPlayers the co-puzzler inputs as posted (empty ones are not counted)
     */
    public function state(
        PuzzleAddMode $mode,
        null|string $competition,
        null|string $puzzleId,
        array $groupPlayers,
        null|DateTimeImmutable $solveDay,
        bool $on,
        null|int $typedSeconds,
    ): null|UnfinishedFormState {
        $on = $on && $mode === PuzzleAddMode::SpeedPuzzling;

        if ($this->hint->isEnabled() === false && $on === false) {
            return null;
        }

        $round = $mode === PuzzleAddMode::SpeedPuzzling
            ? $this->hint->forPick($competition, $puzzleId, 1 + self::coPuzzlers($groupPlayers), $solveDay)
            : null;

        return new UnfinishedFormState(
            round: $round,
            on: $on,
            typedSeconds: $typedSeconds !== null && $typedSeconds > 0 ? $typedSeconds : null,
            piecesTotal: $round !== null || $on ? $this->piecesCount($puzzleId) : null,
            enabled: $this->hint->isEnabled(),
        );
    }

    /**
     * An unfinished submit: a round with a limit must resolve, the pieces and the total must fit it - errors on their
     * fields. With the kill switch off nothing is checked here (an unfinished result being edited is decided by its
     * handler alone).
     *
     * @template TData
     * @param FormInterface<TData> $form
     */
    public function check(FormInterface $form, null|UnfinishedFormState $state, null|int $piecesPlaced, null|int $totalSeconds): void
    {
        if ($state === null || $state->enabled === false) {
            return;
        }

        if ($state->round === null) {
            $form->get('unfinished')->addError(new FormError($this->translator->trans('unfinished_result.form.needs_round')));

            return;
        }

        // No pieces placed: the form's own rule said so already (UnfinishedResultFields::validate())
        if ($piecesPlaced === null || $piecesPlaced < 1 || $state->piecesTotal === null) {
            return;
        }

        try {
            UnfinishedResult::fromInput(
                piecesPlaced: $piecesPlaced,
                totalSeconds: $totalSeconds !== null && $totalSeconds > 0 ? $totalSeconds : null,
                puzzlePiecesCount: $state->piecesTotal,
                roundMinutesLimit: $state->round->minutesLimit,
            );
        } catch (InvalidPiecesPlaced | UnfinishedTotalTimeWithinLimit | UnfinishedResultNeedsTimeLimit $refusal) {
            UnfinishedResultFields::refused($refusal, $form, $this->translator);
        }
    }

    /**
     * @param array<string> $groupPlayers
     */
    private static function coPuzzlers(array $groupPlayers): int
    {
        return count(array_filter($groupPlayers, static fn (string $groupPlayer): bool => trim($groupPlayer) !== ''));
    }

    private function piecesCount(null|string $puzzleId): null|int
    {
        if ($puzzleId === null || Uuid::isValid($puzzleId) === false) {
            return null;
        }

        $piecesCount = $this->database->fetchOne('SELECT pieces_count FROM puzzle WHERE id = :id', ['id' => strtolower($puzzleId)]);

        return is_int($piecesCount) || is_numeric($piecesCount) ? (int) $piecesCount : null;
    }
}
