<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DataImport;

use SpeedPuzzling\Web\Results\DataImportOverview;
use SpeedPuzzling\Web\Results\DataImportSheetOverview;
use SpeedPuzzling\Web\Results\ImportAddSummary;
use SpeedPuzzling\Web\Results\ImportFlowAction;
use SpeedPuzzling\Web\Results\ImportFlowStep;
use SpeedPuzzling\Web\Results\ImportFlowText;
use SpeedPuzzling\Web\Results\ImportPairingSummary;
use SpeedPuzzling\Web\Value\DataImportStatus;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Where an import stands in its guided flow (docs/features/data-import/guided-flow.md §2, §5.3): the five steps -
 * 1 File · 2 Check · 3 People · 4 Puzzles · 5 Add - each with its state and one status sentence, the ONE primary
 * action of "Where you are", and the quiet "add what is ready now" line.
 *
 * Pure: it reads nothing and stores nothing. Everything it says comes from what the import's page has loaded
 * already (the import, its sheets, the pairing summary, the add summary) - so "which step is the player at" is
 * never a stored state, it is worked out on every view. A step with nothing to ask is passed by itself; the
 * numbers of the steps never change.
 *
 * The people of a file (step 3) are known only where a page has counted them (GetImportPeople::counts()): without
 * them the step says what happens to names by default and never becomes the primary action.
 *
 * A value worked out per page view, not a service: it is built by ImportFlow::of().
 */
#[Exclude]
readonly final class ImportFlow
{
    public const int FILE = 1;
    public const int CHECK = 2;
    public const int PEOPLE = 3;
    public const int PUZZLES = 4;
    public const int ADD = 5;

    /** The file is not read (yet): only step 1 says something, and the page's own forms are its buttons */
    public const string STAGE_FILE = 'file';
    public const string STAGE_STEPS = 'steps';
    /** Finished by the player or the daily sweep, or its rows are gone: read-only */
    public const string STAGE_FINISHED = 'finished';

    /** The screens "Continue" leads through, in the flow's order */
    private const array SCREENS = ['check', 'rows', 'people', 'puzzles'];

    /**
     * @param array<int, ImportFlowStep> $steps by number, 1 to 5
     */
    private function __construct(
        public string $stage,
        public array $steps,
        public ImportFlowText $headline,
        public null|ImportFlowAction $primary,
        /** "Or add what is ready now (N)" - only while a step before 5 is open and something is ready */
        public null|ImportFlowAction $secondary,
        /** The import added something that is on the profile now */
        public bool $hasAdded,
        /** "I'm done with this import" is offered: it added something and nothing of it is being added right now */
        public bool $canFinish,
        /** "Discard this import" is offered: nothing was ever added from it */
        public bool $canDiscard,
        private string $importId,
        private int $rowsToRead,
    ) {
    }

    /**
     * @param list<DataImportSheetOverview> $sheets
     * @param null|array{people: int, players: int, unanswered: int} $people null where the page did not count them
     */
    public static function of(
        DataImportOverview $import,
        array $sheets,
        ImportPairingSummary $pairing,
        ImportAddSummary $add,
        null|array $people = null,
    ): self {
        $id = ['importId' => $import->importId];
        $stage = match ($import->status) {
            DataImportStatus::SettingUp, DataImportStatus::Reviewing => $add->wiped ? self::STAGE_FINISHED : self::STAGE_STEPS,
            DataImportStatus::Done, DataImportStatus::Discarded => self::STAGE_FINISHED,
            default => self::STAGE_FILE,
        };

        if ($stage === self::STAGE_FILE) {
            return self::notRead($import);
        }

        $open = $stage === self::STAGE_STEPS;
        $rows = $import->rowsRead > 0 ? $import->rowsRead : array_sum(array_map(static fn (DataImportSheetOverview $sheet): int => $sheet->rows, $sheets));

        // ---- 1 File ----
        $steps = [self::FILE => new ImportFlowStep(
            self::FILE,
            'file',
            ImportFlowStep::DONE,
            [count($sheets) > 1
                ? new ImportFlowText('data_import.flow.file.sheets_rows', count($sheets), ['rows' => $rows])
                : new ImportFlowText('data_import.flow.file.rows', $rows)],
            null,
            [],
        )];

        // ---- 2 Check ----
        $questions = 0;
        $look = 0;
        $failed = 0;
        $unsure = 0;
        $working = false;

        foreach ($sheets as $sheet) {
            $state = $sheet->state();
            $unsure += $sheet->isUnsure() && $sheet->asksThePlayer() === false ? 1 : 0;
            $working = $working || $state === 'recognising' || $state === 'parsing';
            $failed += $sheet->failed ? 1 : 0;
            $questions += $sheet->asksThePlayer() ? $sheet->questions : 0;
            $look += $state === 'parsed' || $state === 'waiting' ? $sheet->rowsNeedLook : 0;
        }

        $checkLines = [];

        if ($questions > 0) {
            $checkLines[] = new ImportFlowText('data_import.flow.check.questions', $questions);
        }

        if ($look > 0) {
            $checkLines[] = new ImportFlowText('data_import.flow.check.rows', $look);
        }

        if ($failed > 0) {
            $checkLines[] = new ImportFlowText('data_import.flow.check.failed', $failed);
        }

        if ($unsure > 0) {
            // "Please check" (§2): the step is not passed by itself - but it is never the one primary action either:
            // nothing records that the player has looked, so it would be asked for on every visit
            $checkLines[] = new ImportFlowText('data_import.flow.check.unsure', $unsure);
        }

        $checkState = match (true) {
            $checkLines !== [] => ImportFlowStep::OPEN,
            $working => ImportFlowStep::WORKING,
            default => ImportFlowStep::DONE,
        };

        if ($working) {
            $checkLines[] = new ImportFlowText('data_import.flow.check.working');
        }

        $steps[self::CHECK] = new ImportFlowStep(
            self::CHECK,
            'check',
            $checkState,
            $checkLines === [] ? [new ImportFlowText('data_import.flow.check.done')] : $checkLines,
            $open ? ($questions === 0 && $failed === 0 && $look > 0 ? 'data_import_to_fix' : 'data_import_columns') : null,
            $id,
            $questions + $look,
        );

        // ---- 3 People ----
        $steps[self::PEOPLE] = match (true) {
            $people === null => new ImportFlowStep(self::PEOPLE, 'people', ImportFlowStep::AHEAD, [new ImportFlowText('data_import.flow.people.default')], $open ? 'data_import_people' : null, $id),
            $people['people'] === 0 => new ImportFlowStep(self::PEOPLE, 'people', ImportFlowStep::DONE, [new ImportFlowText('data_import.flow.people.nobody')], $open ? 'data_import_people' : null, $id),
            $people['unanswered'] > 0 => new ImportFlowStep(self::PEOPLE, 'people', ImportFlowStep::OPEN, [new ImportFlowText('data_import.flow.people.tell_us', $people['unanswered'])], $open ? 'data_import_people' : null, $id, $people['unanswered']),
            default => new ImportFlowStep(
                self::PEOPLE,
                'people',
                ImportFlowStep::DONE,
                [new ImportFlowText('data_import.flow.people.named', $people['people'], ['players' => $people['players'], 'guests' => max(0, $people['people'] - $people['players'])])],
                $open ? 'data_import_people' : null,
                $id,
            ),
        };

        // ---- 4 Puzzles ----
        $looking = $pairing->paired === false || $pairing->searching > 0;
        $puzzleLines = [];

        if ($pairing->puzzles > 0) {
            $puzzleLines[] = $pairing->found === $pairing->puzzles
                ? new ImportFlowText('data_import.flow.puzzles.all_matched', $pairing->puzzles)
                : new ImportFlowText('data_import.flow.puzzles.matched', null, ['found' => $pairing->found, 'puzzles' => $pairing->puzzles]);
        }

        if ($add->undecided > 0) {
            $puzzleLines[] = new ImportFlowText('data_import.flow.puzzles.rows_wait', $add->undecided);
        }

        if ($looking) {
            $puzzleLines[] = $pairing->searching > 0
                ? new ImportFlowText('data_import.flow.puzzles.searching', $pairing->searching)
                : new ImportFlowText('data_import.flow.puzzles.working');
        }

        $steps[self::PUZZLES] = new ImportFlowStep(
            self::PUZZLES,
            'puzzles',
            match (true) {
                $add->undecided > 0 => ImportFlowStep::OPEN,
                $looking => ImportFlowStep::WORKING,
                default => ImportFlowStep::DONE,
            },
            $puzzleLines === [] ? [new ImportFlowText('data_import.flow.puzzles.none')] : $puzzleLines,
            $open ? 'data_import_puzzles' : null,
            $id,
            $add->undecided,
        );

        // ---- 5 Add ----
        $ready = $open && $add->canAdd ? $add->toAdd() : 0;
        $firstOpen = null;

        foreach ([self::CHECK, self::PEOPLE, self::PUZZLES] as $number) {
            if ($steps[$number]->state === ImportFlowStep::OPEN || $steps[$number]->state === ImportFlowStep::WORKING) {
                $firstOpen ??= $number;
            }
        }

        $addLines = [];

        if ($add->runningBatchId !== null) {
            $addLines[] = new ImportFlowText('data_import.flow.add.running');
        } elseif ($ready > 0) {
            $addLines[] = new ImportFlowText('data_import.flow.add.ready', $ready);
        }

        if ($add->notAdded > 0) {
            $addLines[] = new ImportFlowText('data_import.flow.add.rows_wait', $add->notAdded);
        }

        if ($open && $add->paused) {
            // The safety switch: said calmly, nothing is lost (docs/features/data-import/adding-screens.md)
            $addLines[] = new ImportFlowText('data_import.add.paused');
        }

        if ($addLines === [] && $add->hasAdded()) {
            $addLines[] = $add->lastAddedAt === null
                ? new ImportFlowText('data_import.flow.add.added', $add->rowsAdded)
                : new ImportFlowText('data_import.flow.add.added_on', $add->rowsAdded, [], $add->lastAddedAt);
        }

        $steps[self::ADD] = new ImportFlowStep(
            self::ADD,
            'add',
            match (true) {
                $add->runningBatchId !== null => ImportFlowStep::WORKING,
                $ready > 0 || $add->notAdded > 0 => ImportFlowStep::OPEN,
                $add->hasAdded() => ImportFlowStep::DONE,
                default => ImportFlowStep::AHEAD,
            },
            $addLines !== [] ? $addLines : [$firstOpen === null
                ? new ImportFlowText('data_import.flow.add.nothing')
                : new ImportFlowText('data_import.flow.add.nothing_step', null, ['step' => $firstOpen])],
            $open ? 'data_import_add' : null,
            $id,
            $ready,
        );

        // ---- The one primary action, and the quiet line beside it ----
        $seeAdded = new ImportFlowAction(new ImportFlowText('data_import.flow.primary.see_added'), 'data_import_added', $id, self::ADD);
        $checkAndAdd = new ImportFlowAction(new ImportFlowText('data_import.flow.primary.check_and_add'), 'data_import_add', $id, self::ADD);

        $primary = match (true) {
            $open === false => $add->hasAdded() ? $seeAdded : null,
            $add->runningBatchId !== null => new ImportFlowAction(new ImportFlowText('data_import.flow.primary.follow'), 'data_import_batch', $id + ['batchId' => $add->runningBatchId], self::ADD),
            $questions > 0 => new ImportFlowAction(new ImportFlowText('data_import.flow.primary.questions', $questions), 'data_import_columns', $id, self::CHECK),
            $look > 0 => new ImportFlowAction(new ImportFlowText('data_import.flow.primary.rows', $look), 'data_import_to_fix', $id, self::CHECK),
            $failed > 0 => new ImportFlowAction(new ImportFlowText('data_import.flow.primary.how_read'), 'data_import_columns', $id, self::CHECK),
            $steps[self::PEOPLE]->isOpen() => new ImportFlowAction(new ImportFlowText('data_import.flow.primary.people'), 'data_import_people', $id, self::PEOPLE),
            $steps[self::PUZZLES]->isOpen() => new ImportFlowAction(new ImportFlowText('data_import.flow.primary.puzzles'), 'data_import_puzzles', $id, self::PUZZLES),
            $add->notAdded > 0 => new ImportFlowAction(new ImportFlowText('data_import.flow.primary.answer', $add->notAdded), 'data_import_not_added', $id, self::ADD),
            // While adding is paused nothing leads to the button that is not there
            $ready > 0 && $add->paused === false => $checkAndAdd,
            $add->hasAdded() => $seeAdded,
            default => null,
        };

        $secondary = $primary !== null && $primary->route !== 'data_import_add' && $primary->route !== 'data_import_added' && $ready > 0 && $add->runningBatchId === null && $add->paused === false
            ? new ImportFlowAction(new ImportFlowText('data_import.flow.add_ready_now', $ready), 'data_import_add', $id, self::ADD)
            : null;

        return new self(
            $stage,
            $steps,
            $open
                ? new ImportFlowText('data_import.flow.headline.read', $rows)
                : new ImportFlowText($add->wiped && $import->status !== DataImportStatus::Done ? 'data_import.flow.headline.gone' : 'data_import.flow.headline.finished'),
            $primary,
            $secondary,
            $add->hasAdded(),
            $open && $add->latestBatchId !== null && $add->runningBatchId === null,
            $add->latestBatchId === null,
            $import->importId,
            $look,
        );
    }

    /**
     * The step the player is at: the primary action's, else the first one where something waits or is worked on,
     * else step 5 while nothing was added from it - null when every step is passed (or the import is finished).
     */
    public function current(): null|int
    {
        if ($this->stage === self::STAGE_FINISHED) {
            return null;
        }

        if ($this->primary !== null) {
            return $this->primary->step;
        }

        foreach ($this->steps as $step) {
            if ($step->state === ImportFlowStep::OPEN || $step->state === ImportFlowStep::WORKING) {
                return $step->number;
            }
        }

        return $this->steps[self::ADD]->isDone() ? null : self::ADD;
    }

    /**
     * Where "Continue" at the end of a screen leads: the next screen of the flow, after the one it stands on, that
     * has something waiting - else step 5, which is never passed. A screen the player chose to leave unfinished is
     * never offered again by its own Continue.
     *
     * @param string $screen `check` (how the file was read), `rows` (rows that could not be read), `people`, `puzzles`
     *
     * @return array{route: string, parameters: array<string, string>}
     */
    public function nextAfter(string $screen): array
    {
        $position = array_search($screen, self::SCREENS, true);
        $id = ['importId' => $this->importId];

        if ($this->stage !== self::STAGE_STEPS || $position === false) {
            return ['route' => 'data_import_detail', 'parameters' => $id];
        }

        if ($position < 1 && $this->rowsToRead > 0) {
            return ['route' => 'data_import_to_fix', 'parameters' => $id];
        }

        if ($position < 2 && $this->steps[self::PEOPLE]->isOpen()) {
            return ['route' => 'data_import_people', 'parameters' => $id];
        }

        if ($position < 3 && $this->steps[self::PUZZLES]->isOpen()) {
            return ['route' => 'data_import_puzzles', 'parameters' => $id];
        }

        return ['route' => 'data_import_add', 'parameters' => $id];
    }

    private static function notRead(DataImportOverview $import): self
    {
        [$state, $line] = match ($import->status) {
            DataImportStatus::Uploading => [ImportFlowStep::OPEN, 'data_import.flow.file.upload_not_finished'],
            DataImportStatus::FetchFailed, DataImportStatus::ReadFailed => [ImportFlowStep::OPEN, 'data_import.flow.file.not_read'],
            default => [ImportFlowStep::WORKING, 'data_import.flow.file.being_read'],
        };

        $steps = [self::FILE => new ImportFlowStep(self::FILE, 'file', $state, [new ImportFlowText($line)], null, [])];

        foreach ([self::CHECK => 'check', self::PEOPLE => 'people', self::PUZZLES => 'puzzles', self::ADD => 'add'] as $number => $key) {
            $steps[$number] = new ImportFlowStep($number, $key, ImportFlowStep::AHEAD, [], null, []);
        }

        return new self(self::STAGE_FILE, $steps, new ImportFlowText($line), null, null, false, false, true, $import->importId, 0);
    }
}
