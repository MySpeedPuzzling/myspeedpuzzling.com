<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DataImport;

use SpeedPuzzling\Web\Results\ImportRowDetail;
use SpeedPuzzling\Web\Value\DataImport\ImportMapping;

/**
 * The row sheet of one import row as its page shows it (docs/features/data-import/pairing-screens.md "The row
 * sheet"): every field its sheet's mapping gives the row and a player can answer, with the file's own text beside
 * an input - the field editors a later "My data" row sheet reuses. Pure: the row and what was typed in, the fields
 * out.
 */
final class ImportRowSheet
{
    private const array CHOICES = [
        'type' => ['solo', 'duo', 'team'],
        'yes_no' => ['yes', 'no'],
        'status' => ['result', 'owned', 'both', 'wish_list'],
    ];

    /**
     * @param array<string, string> $typed what the refused form held, by field - shown again
     * @param array<string, string> $errors by field
     *
     * @return list<array{field: string, input: string, choices: list<string>, header: string, original: string, answer: string, understood: null|string, problems: list<array{code: string, value: string}>, error: null|string}>
     */
    public static function fields(ImportRowDetail $detail, array $typed = [], array $errors = []): array
    {
        if ($detail->mapping === null) {
            return [];
        }

        $fields = [];

        foreach (ImportMapping::fromArray($detail->mapping)->columns as $column) {
            $field = $column->field?->value;
            $input = $field === null ? null : ImportRowAnswers::INPUTS[$field] ?? null;

            // A field several columns hold (two "solved with" columns): the first stands for it
            if ($field === null || $input === null || isset($fields[$field])) {
                continue;
            }

            $problems = [];

            foreach ($detail->row->problems as $problem) {
                if ($problem['field'] === $field && $problem['waiting'] === false) {
                    $problems[] = ['code' => $problem['code'], 'value' => $problem['value']];
                }
            }

            $fields[$field] = [
                'field' => $field,
                'input' => $input,
                'choices' => self::CHOICES[$input] ?? [],
                'header' => $column->header,
                'original' => StoredCells::shown($detail->cells[$column->column] ?? null),
                'answer' => $typed[$field] ?? ImportRowAnswers::typed($detail->answers, $field) ?? '',
                'understood' => self::understood($detail, $field),
                'problems' => $problems,
                'error' => $errors[$field] ?? null,
            ];
        }

        // A "Completed" column that carries a date we could not read, on a sheet without a date column: the day is
        // asked here - an answer about the status would never settle it
        if (isset($fields['solved_on']) === false) {
            foreach ($detail->row->problems as $problem) {
                if ($problem['field'] === 'status' && $problem['code'] === 'date_unreadable') {
                    $fields['solved_on'] = [
                        'field' => 'solved_on',
                        'input' => 'date',
                        'choices' => [],
                        'header' => '',
                        'original' => '',
                        'answer' => $typed['solved_on'] ?? ImportRowAnswers::typed($detail->answers, 'solved_on') ?? '',
                        'understood' => self::understood($detail, 'solved_on'),
                        'problems' => [['code' => $problem['code'], 'value' => $problem['value']]],
                        'error' => $errors['solved_on'] ?? null,
                    ];

                    break;
                }
            }
        }

        return array_values($fields);
    }

    /**
     * What the form changes: the fields whose text is not what the row's answer already is. An emptied field takes
     * its answer back.
     *
     * @param array<array-key, mixed> $submitted `answers[<field>]` of the form
     *
     * @return array<string, string> AnswerImportRows::$answers
     */
    public static function changed(ImportRowDetail $detail, array $submitted): array
    {
        $changed = [];

        foreach (self::fields($detail) as $field) {
            $text = $submitted[$field['field']] ?? null;

            if (is_string($text) && trim($text) !== trim($field['answer'])) {
                $changed[$field['field']] = mb_substr(trim($text), 0, 500);
            }
        }

        return $changed;
    }

    private static function understood(ImportRowDetail $detail, string $field): null|string
    {
        $row = $detail->row;
        $parsed = $row->parsed;

        return match ($field) {
            'puzzle_name' => $row->text('name'),
            'brand' => $row->text('brand'),
            'pieces' => $row->text('pieces'),
            'barcode' => is_array($parsed['barcodes'] ?? null) && $parsed['barcodes'] !== [] ? implode(', ', array_filter($parsed['barcodes'], is_string(...))) : null,
            'solved_on' => $row->text('date'),
            'time' => $row->time(),
            'attempt_number' => $row->text('attempt'),
            'public_comment' => $row->text('comment'),
            default => null,
        };
    }
}
