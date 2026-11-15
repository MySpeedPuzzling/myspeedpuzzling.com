<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\DataImport;

/**
 * What the import proposes for one sheet (ImportSheetAnalyser): its mapping, how its values are read, the words of
 * its columns that mean something - and a signature of its headers, by which a later stage knows the layout again.
 */
final readonly class ImportSheetProposal
{
    /**
     * @param array<int, array<string, string>> $valueAnswers column number => folded word => ImportWordMeaning's value
     */
    public function __construct(
        public ImportMapping $mapping,
        public ImportSheetOptions $options,
        public array $valueAnswers = [],
        public null|string $headerSignature = null,
    ) {
    }

    /**
     * @param list<ImportQuestion> $questions
     */
    public function with(ImportMapping $mapping, ImportSheetOptions $options, array $questions = []): self
    {
        return new self($mapping->withQuestions($questions), $options, $this->valueAnswers, $this->headerSignature);
    }
}
