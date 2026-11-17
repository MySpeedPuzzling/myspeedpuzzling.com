<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\EventWizard;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Results\EventWizardState;
use SpeedPuzzling\Web\Services\EventWizard\EventWizardSteps;
use SpeedPuzzling\Web\Value\EventWizard\EventWizardPath;
use SpeedPuzzling\Web\Value\EventWizard\EventWizardStep;

/**
 * The wizard's path and its steps' "done" are derived from the draft alone - nothing stored (resume).
 */
final class EventWizardStepsTest extends TestCase
{
    public function testTheOneRoundPathResumesAtTheFirstStepNotDone(): void
    {
        $steps = new EventWizardSteps();

        $noPuzzle = $steps->of(self::state(roundsCount: 1, puzzles: 0));
        self::assertSame(EventWizardPath::OneRound, $noPuzzle->path);
        self::assertSame([EventWizardStep::Basics, EventWizardStep::Puzzle, EventWizardStep::Extras, EventWizardStep::Review], $noPuzzle->steps);
        self::assertSame(EventWizardStep::Puzzle, $noPuzzle->firstNotDone());
        self::assertSame(2, $noPuzzle->position(EventWizardStep::Puzzle));
        self::assertSame(EventWizardStep::Extras, $noPuzzle->next(EventWizardStep::Puzzle));
        self::assertSame(EventWizardStep::Extras, $noPuzzle->previous(EventWizardStep::Review));
        self::assertSame(EventWizardStep::Basics, $noPuzzle->previous(EventWizardStep::Puzzle));
        self::assertNull($noPuzzle->previous(EventWizardStep::Basics));

        $withPuzzle = $steps->of(self::state(roundsCount: 1, puzzles: 1));
        self::assertTrue($withPuzzle->isDone(EventWizardStep::Puzzle));
        // Extras is done once one of its features is on - else resuming shows it (one click to skip)
        self::assertSame(EventWizardStep::Extras, $withPuzzle->firstNotDone());
        $withExtras = $steps->of(self::state(roundsCount: 1, puzzles: 1, extrasOn: true));
        self::assertTrue($withExtras->isDone(EventWizardStep::Extras));
        self::assertSame(EventWizardStep::Review, $withExtras->firstNotDone());

    }

    /**
     * Several rounds (W5): a draft without any round is on this path too (the one-round path creates its round with
     * the draft) - between Basics and Structure, or "not sure yet"; Structure is done with two rounds or more, Puzzles
     * once no round is left without a puzzle.
     */
    public function testTheSeveralRoundsPathResumesAtTheFirstStepNotDone(): void
    {
        $steps = new EventWizardSteps();

        $noRounds = $steps->of(self::state(roundsCount: 0, puzzles: 0));
        self::assertSame(EventWizardPath::SeveralRounds, $noRounds->path);
        self::assertSame([EventWizardStep::Basics, EventWizardStep::Structure, EventWizardStep::Puzzles, EventWizardStep::Review], $noRounds->steps);
        self::assertSame(EventWizardStep::Structure, $noRounds->firstNotDone());

        $withoutPuzzles = $steps->of(self::state(roundsCount: 3, puzzles: 1, roundsWithoutPuzzle: 2));
        self::assertTrue($withoutPuzzles->isDone(EventWizardStep::Structure));
        self::assertSame(EventWizardStep::Puzzles, $withoutPuzzles->firstNotDone());
        self::assertSame(EventWizardStep::Review, $withoutPuzzles->next(EventWizardStep::Puzzles));

        self::assertSame(EventWizardStep::Review, $steps->of(self::state(roundsCount: 3, puzzles: 3))->firstNotDone());
        self::assertSame([EventWizardStep::Basics, EventWizardStep::Structure, EventWizardStep::Puzzles, EventWizardStep::Review], $steps->forNewEvent(EventWizardPath::SeveralRounds)->steps);
    }

    public function testTheSeriesPathHasNoStepsYet(): void
    {
        $steps = new EventWizardSteps();

        $edition = $steps->of(self::state(roundsCount: 1, puzzles: 0, isEdition: true));
        self::assertSame(EventWizardPath::Series, $edition->path);
        self::assertFalse($edition->has(EventWizardStep::Basics));

        self::assertSame([EventWizardStep::Basics, EventWizardStep::Puzzle, EventWizardStep::Extras, EventWizardStep::Review], $steps->forNewEvent(EventWizardPath::OneRound)->steps);
    }

    private static function state(int $roundsCount, int $puzzles, bool $isEdition = false, bool $extrasOn = false, int $roundsWithoutPuzzle = 0): EventWizardState
    {
        return new EventWizardState(
            competitionId: '018d0042-0000-0000-0000-000000000099',
            isDraft: true,
            isEdition: $isEdition,
            roundsCount: $roundsCount,
            singleRoundId: $roundsCount === 1 ? '018d0042-0000-0000-0000-000000000098' : null,
            roundPuzzlesCount: $puzzles,
            extrasOn: $extrasOn,
            roundsWithoutPuzzle: $roundsWithoutPuzzle,
        );
    }
}
