<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\ParticipantImport;

use SpeedPuzzling\Web\Exceptions\ParticipantFileUnreadable;
use SpeedPuzzling\Web\Results\ParticipantImportPreview;
use SpeedPuzzling\Web\Value\ColumnMapping;
use SpeedPuzzling\Web\Value\ParticipantFileOptions;
use SpeedPuzzling\Web\Value\ParticipantFileSheetInfo;
use SpeedPuzzling\Web\Value\ParticipantImportMode;
use SpeedPuzzling\Web\Value\ParticipantImportRound;
use SpeedPuzzling\Web\Value\StashedParticipantImport;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Turns the import preview's form state (query of the preview, hidden fields of the confirm) into what the page shows
 * and what the confirm applies - one code path for both, so the confirm never differs from what was shown.
 * Reads only: the stash, the event's rounds and the plan. A token the stash does not keep for this event throws
 * StashedParticipantImportNotFound (404) - left to bubble.
 */
readonly final class ParticipantImportPreviewBuilder
{
    public function __construct(
        private ParticipantImportStash $stash,
        private ParticipantImportPlanner $planner,
    ) {
    }

    /**
     * @param array<mixed> $input `sheet`, `encoding`, `separator`, `headers`, `map`, `mode`
     */
    public function build(StashedParticipantImport $stashed, string $competitionId, array $input): ParticipantImportPreview
    {
        $options = ParticipantFileOptions::fromQuery($input['encoding'] ?? null, $input['separator'] ?? null);
        $mode = ParticipantImportMode::tryFrom(is_string($input['mode'] ?? null) ? $input['mode'] : '') ?? ParticipantImportMode::Update;

        try {
            $sheets = $this->stash->sheets($stashed->token, $competitionId);
        } catch (ParticipantFileUnreadable $e) {
            return new ParticipantImportPreview($stashed, [], 0, $options, $mode, fileError: self::errorOf($e));
        }

        $rounds = $this->planner->rounds($competitionId);
        $sheetIndex = $this->chosenSheet($stashed, $competitionId, $sheets, $input['sheet'] ?? null, $options, $rounds);

        try {
            $sheet = $this->stash->sheet($stashed->token, $competitionId, $sheetIndex, $options);
        } catch (ParticipantFileUnreadable $e) {
            return new ParticipantImportPreview($stashed, $sheets, $sheetIndex, $options, $mode, fileError: self::errorOf($e));
        }

        $map = $input['map'] ?? null;
        $headersKey = ParticipantImportPreview::keyOfHeaders($sheet->headers);
        $mappingFromInput = is_array($map) && ($input['headers'] ?? null) === $headersKey;

        $mapping = $mappingFromInput
            ? ColumnMapping::fromQuery($map, $sheet->headers, $rounds)
            : ColumnMapping::detect($sheet->headers, $rounds);

        $mappingErrors = $mapping->errors();

        if ($mappingErrors !== []) {
            return new ParticipantImportPreview(
                stashed: $stashed,
                sheets: $sheets,
                sheetIndex: $sheetIndex,
                options: $options,
                mode: $mode,
                sheet: $sheet,
                rounds: $rounds,
                mapping: $mapping,
                mappingFromInput: $mappingFromInput,
                mappingErrors: $mappingErrors,
            );
        }

        $rows = $mapping->toRows($sheet);

        return new ParticipantImportPreview(
            stashed: $stashed,
            sheets: $sheets,
            sheetIndex: $sheetIndex,
            options: $options,
            mode: $mode,
            sheet: $sheet,
            rounds: $rounds,
            mapping: $mapping,
            mappingFromInput: $mappingFromInput,
            rows: $rows,
            plan: $this->planner->plan($competitionId, $rows, $mode),
        );
    }

    private static function errorOf(ParticipantFileUnreadable $e): TranslatableMessage
    {
        return new TranslatableMessage($e->translationKey, $e->translationParameters);
    }

    /**
     * The asked sheet when the file has it. Otherwise (the first visit) the first visible sheet with a column of
     * names (Name, or First + Last name, as ColumnMapping::detect() finds them) - a workbook often starts with an
     * "Info" sheet; then the visible sheet with the most rows; then the first visible one. A hidden sheet is never
     * preselected. Reading a sheet here caches it, so the chosen one is not parsed twice.
     *
     * @param list<ParticipantFileSheetInfo> $sheets
     * @param list<ParticipantImportRound> $rounds
     */
    private function chosenSheet(StashedParticipantImport $stashed, string $competitionId, array $sheets, mixed $asked, ParticipantFileOptions $options, array $rounds): int
    {
        if (is_numeric($asked)) {
            foreach ($sheets as $sheet) {
                if ($sheet->index === (int) $asked) {
                    return $sheet->index;
                }
            }
        }

        $visible = array_values(array_filter($sheets, static fn (ParticipantFileSheetInfo $sheet): bool => $sheet->hidden === false));

        if ($visible === []) {
            return $sheets[0]->index ?? 0;
        }

        if (count($visible) === 1) {
            return $visible[0]->index;
        }

        foreach ($visible as $info) {
            try {
                $sheet = $this->stash->sheet($stashed->token, $competitionId, $info->index, $options);
            } catch (ParticipantFileUnreadable) {
                continue;
            }

            if (ColumnMapping::detect($sheet->headers, $rounds)->hasName()) {
                return $info->index;
            }
        }

        $longest = $visible[0];
        foreach ($visible as $info) {
            if ($info->rows > $longest->rows) {
                $longest = $info;
            }
        }

        return $longest->index;
    }
}
