<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\DataImport;

use SpeedPuzzling\Web\Value\DataImportRowKind;

/**
 * One row of a sheet as the import reads it under a mapping (ImportRowParser; docs/features/data-import/columns.md
 * "What a parsed row holds"): what the row is, its typed values, what is unclear about it, and its keys.
 */
final readonly class ParsedImportRow
{
    /**
     * @param array<string, mixed> $parsed
     * @param list<array{field: null|string, code: string, value?: string, choices?: list<int|string>, waiting?: bool}> $problems
     */
    public function __construct(
        public DataImportRowKind $kind,
        public array $parsed,
        public array $problems = [],
        public null|string $puzzleKey = null,
        /** The key of what the row first of all makes: its result, else its owned puzzle, else its wish */
        public null|string $sourceKey = null,
        public null|string $sourceRowId = null,
        public null|ImportRowLeftOut $leftOut = null,
    ) {
    }

    public static function leftOut(ImportRowLeftOut $why): self
    {
        return new self(DataImportRowKind::NotAPuzzle, ['leftOut' => $why->value], leftOut: $why);
    }

    public function isData(): bool
    {
        return $this->leftOut === null;
    }

    public function isUnderstood(): bool
    {
        return $this->leftOut === null && $this->problems === [];
    }

    /**
     * Whether a question of the sheet that is not answered yet decides the row (PendingQuestionRows): it is neither
     * ready nor "needs a look" until the player answers.
     */
    public function isWaiting(): bool
    {
        foreach ($this->problems as $problem) {
            if (($problem['waiting'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * The row of a sheet whose columns the player has not checked yet (Services\DataImport\ImportCheckFirst): it is
     * read - the sheet's page shows what we made of it - but it waits, and it stands for nothing: no puzzle, no key,
     * nobody it was solved with. So no puzzle is built or matched from it, nobody is asked about, and it can not be
     * added - until the player has looked and the sheet is read again.
     */
    public function heldForColumnCheck(string $code): self
    {
        if ($this->leftOut !== null) {
            return $this;
        }

        $parsed = $this->parsed;
        unset($parsed['people']);

        return new self($this->kind, $parsed, [['field' => null, 'code' => $code, 'waiting' => true]], null, null, $this->sourceRowId, null);
    }

    /**
     * @param list<array{field: null|string, code: string, value?: string, choices?: list<int|string>, waiting?: bool}> $problems
     */
    public function withProblems(array $problems): self
    {
        return new self($this->kind, $this->parsed, $problems, $this->puzzleKey, $this->sourceKey, $this->sourceRowId, $this->leftOut);
    }

    /**
     * What travels to the handler that stores it.
     *
     * @return array{kind: string, parsed: array<string, mixed>, problems: list<array<string, mixed>>, puzzleKey: null|string, sourceKey: null|string, sourceRowId: null|string, leftOut: bool}
     */
    public function toRecord(): array
    {
        return [
            'kind' => $this->kind->value,
            'parsed' => $this->parsed,
            'problems' => $this->problems,
            'puzzleKey' => $this->puzzleKey,
            'sourceKey' => $this->sourceKey,
            'sourceRowId' => $this->sourceRowId,
            'leftOut' => $this->leftOut !== null,
        ];
    }
}
