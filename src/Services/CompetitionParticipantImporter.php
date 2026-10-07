<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\Exception as SpreadsheetException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use SpeedPuzzling\Web\Exceptions\ParticipantFileUnreadable;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Results\ParticipantImportPlan;
use SpeedPuzzling\Web\Results\ParticipantImportResult;
use SpeedPuzzling\Web\Services\ParticipantImport\ParticipantFileReader;
use SpeedPuzzling\Web\Services\ParticipantImport\ParticipantImportApplier;
use SpeedPuzzling\Web\Services\ParticipantImport\ParticipantImportPlanner;
use SpeedPuzzling\Web\Services\ParticipantImport\Plan\PlanBuilder;
use SpeedPuzzling\Web\Value\ColumnMapping;
use SpeedPuzzling\Web\Value\ParticipantFileFormat;
use SpeedPuzzling\Web\Value\ParticipantImportMode;
use SpeedPuzzling\Web\Value\ParticipantImportRound;
use SpeedPuzzling\Web\Value\ParticipantSheet;
use Symfony\Component\Translation\TranslatableMessage;

// PORT-TODO: PR #136 imported a `registration_status` column (reserved/paid/waitlisted, applyRegistrationStatus()) and
// registered new participants of a managed-registration competition as reserved. Main replaced this importer with the
// planner/applier pipeline - port it there (ColumnMapping field, PlanBuilder change, ParticipantImportApplier) together
// with the export column (see CompetitionParticipantExporter)

/**
 * The import without a preview - the console command (myspeedpuzzling:import-competition-participants) and
 * ImportCompetitionParticipants: the file's detected columns, planned in "Update only" and applied, the same code
 * path as the web preview (ParticipantImportPlanner + ParticipantImportApplier, design doc D20).
 *
 * Rules: docs/features/competitions-management/participants.md §Excel Import - they live in PlanBuilder.
 */
readonly final class CompetitionParticipantImporter
{
    /**
     * Every column the importer reads. `round_names` (a list) is what the template and the export write;
     * `round_name` (one round, never split) is kept for files made before.
     */
    public const array KNOWN_COLUMNS = ['name', 'country', 'external_id', 'msp_player_id', 'status', 'round_names', 'round_name', 'team_name', 'participant_id'];

    /** A column "team_name: <round>" holds the team in that one round (the export writes one per pair/team round). */
    public const string TEAM_COLUMN_PREFIX = 'team_name:';

    /** Row messages that were errors (not warnings) of the import - the console prints them as such */
    private const array ERROR_MESSAGES = ['missing_name', 'invalid_player_id', 'unknown_player_id'];

    public function __construct(
        private CompetitionRepository $competitionRepository,
        private ParticipantImportPlanner $planner,
        private ParticipantImportApplier $applier,
        private ParticipantFileReader $fileReader,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Flushes itself: tests and ImportCompetitionParticipantsHandler call it directly (inside the handler the
     * transaction middleware's own flush then has nothing left to do).
     */
    public function import(string $competitionId, string $filePath): ParticipantImportResult
    {
        $this->competitionRepository->get($competitionId);

        try {
            $sheet = $this->readSheet($filePath);
        } catch (SpreadsheetException | ParticipantFileUnreadable) {
            return new ParticipantImportResult(errors: [PlanBuilder::message('unreadable_file')]);
        }

        if ($sheet === null) {
            return new ParticipantImportResult(errors: [PlanBuilder::message('empty_file')]);
        }

        $rounds = $this->planner->rounds($competitionId);
        $mapping = ColumnMapping::detect($sheet->headers, $rounds);

        if (!$mapping->hasName()) {
            return new ParticipantImportResult(errors: [PlanBuilder::message('name_column_missing')]);
        }

        $rows = $mapping->toRows($sheet);
        $plan = $this->planner->plan($competitionId, $rows, ParticipantImportMode::Update);

        $result = $this->applier->apply($competitionId, $plan);
        $this->entityManager->flush();

        [$warnings, $errors] = $this->messages($plan, $sheet, $mapping, $rounds);

        return new ParticipantImportResult(
            added: $result->added,
            updated: $result->updated,
            softDeleted: $result->softDeleted,
            warnings: $warnings,
            errors: [...$errors, ...$plan->errors],
            unchanged: $result->unchanged,
            restored: $result->restored,
        );
    }

    /**
     * Today's messages, in today's words: columns nobody reads, the row messages, the grouped round messages.
     * The preview's new warnings (`import.warning.*` - names written two ways, team sizes) stay on the preview.
     *
     * @param list<ParticipantImportRound> $rounds
     * @return array{list<TranslatableMessage>, list<TranslatableMessage>} warnings, errors
     */
    private function messages(ParticipantImportPlan $plan, ParticipantSheet $sheet, ColumnMapping $mapping, array $rounds): array
    {
        $warnings = [];
        $errors = [];

        $planWarnings = array_values(array_filter(
            $plan->warnings,
            static fn (TranslatableMessage $message): bool => !str_starts_with($message->getMessage(), PlanBuilder::MESSAGE_PREFIX . 'warning.'),
        ));
        $collisions = array_values(array_filter($planWarnings, static fn (TranslatableMessage $message): bool => $message->getMessage() === PlanBuilder::MESSAGE_PREFIX . 'round_case_collision'));
        $others = array_values(array_filter($planWarnings, static fn (TranslatableMessage $message): bool => $message->getMessage() !== PlanBuilder::MESSAGE_PREFIX . 'round_case_collision'));

        $warnings = [...$warnings, ...$collisions];

        $roundKeys = [];
        foreach ($rounds as $round) {
            $roundKeys[PlanBuilder::roundKey($round->name)] = true;
        }

        $unknownColumns = [];
        $teamColumnPrefix = ColumnMapping::normaliseHeader(self::TEAM_COLUMN_PREFIX);
        foreach ($sheet->headers as $index => $header) {
            if ($header === '' || isset($mapping->fields[$index])) {
                continue;
            }

            // "team_name: <round>" of a round the event does not have (a solo round's column is just not read)
            if (str_starts_with(ColumnMapping::normaliseHeader($header), $teamColumnPrefix)) {
                $roundLabel = trim(substr($header, strlen(self::TEAM_COLUMN_PREFIX)));

                if (!isset($roundKeys[PlanBuilder::roundKey($roundLabel)])) {
                    $warnings[] = PlanBuilder::message('unknown_team_round_column', ['%column%' => $header, '%round%' => $roundLabel]);
                }

                continue;
            }

            $unknownColumns[] = $header;
        }

        if ($unknownColumns !== []) {
            $warnings[] = PlanBuilder::message('unknown_columns', [
                '%columns%' => PlanBuilder::quotedList(array_values(array_unique($unknownColumns))),
                '%known%' => implode(', ', [...self::KNOWN_COLUMNS, self::TEAM_COLUMN_PREFIX . ' <round>']),
            ]);
        }

        foreach ($plan->rows as $row) {
            foreach ($row->messages as $message) {
                $key = substr($message->getMessage(), strlen(PlanBuilder::MESSAGE_PREFIX));

                if (in_array($key, self::ERROR_MESSAGES, true)) {
                    $errors[] = $message;
                } else {
                    $warnings[] = $message;
                }
            }
        }

        return [[...$warnings, ...$others], $errors];
    }

    /**
     * The first row is the header (as the import has always read it). CSV goes through the preview's reader.
     *
     * @throws SpreadsheetException|ParticipantFileUnreadable
     */
    private function readSheet(string $filePath): null|ParticipantSheet
    {
        if (ParticipantFileFormat::fromFileName($filePath) === ParticipantFileFormat::Csv) {
            return $this->fileReader->read($filePath, ParticipantFileFormat::Csv);
        }

        try {
            $spreadsheet = IOFactory::load($filePath);
        } catch (\ValueError $e) {
            throw new SpreadsheetException('Not a spreadsheet', previous: $e);
        }

        try {
            $cells = $spreadsheet->getActiveSheet()->toArray();
        } finally {
            $spreadsheet->disconnectWorksheets();
        }

        if ($cells === []) {
            return null;
        }

        /** @var array<null|scalar> $headerRow */
        $headerRow = array_shift($cells);
        $headers = array_values(array_map(static fn (mixed $header): string => trim((string) $header), $headerRow));

        $rows = [];
        foreach ($cells as $index => $cellRow) {
            /** @var array<null|scalar> $cellRow */
            $row = [];
            foreach (array_keys($headers) as $column) {
                $row[] = trim((string) ($cellRow[$column] ?? ''));
            }

            if (implode('', $row) !== '') {
                $rows[$index + 2] = $row;
            }
        }

        return new ParticipantSheet($headers, $rows);
    }
}
