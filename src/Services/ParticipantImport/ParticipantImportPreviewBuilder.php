<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\ParticipantImport;

use SpeedPuzzling\Web\Exceptions\ParticipantFileUnreadable;
use SpeedPuzzling\Web\Results\ParticipantImportPreview;
use SpeedPuzzling\Web\Value\ColumnMapping;
use SpeedPuzzling\Web\Value\ParticipantFileOptions;
use SpeedPuzzling\Web\Value\ParticipantFileSheetInfo;
use SpeedPuzzling\Web\Value\ParticipantImportMode;
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

        $sheetIndex = self::chosenSheet($sheets, $input['sheet'] ?? null);

        try {
            $sheet = $this->stash->sheet($stashed->token, $competitionId, $sheetIndex, $options);
        } catch (ParticipantFileUnreadable $e) {
            return new ParticipantImportPreview($stashed, $sheets, $sheetIndex, $options, $mode, fileError: self::errorOf($e));
        }

        $rounds = $this->planner->rounds($competitionId);
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
     * The asked sheet when the file has it, otherwise the first visible one (a hidden sheet is never preselected).
     *
     * @param list<ParticipantFileSheetInfo> $sheets
     */
    private static function chosenSheet(array $sheets, mixed $asked): int
    {
        if (is_numeric($asked)) {
            foreach ($sheets as $sheet) {
                if ($sheet->index === (int) $asked) {
                    return $sheet->index;
                }
            }
        }

        foreach ($sheets as $sheet) {
            if ($sheet->hidden === false) {
                return $sheet->index;
            }
        }

        return $sheets[0]->index ?? 0;
    }
}
