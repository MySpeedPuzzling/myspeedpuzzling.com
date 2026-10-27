<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The `requires` filter of the image analysis work list and claims (docs/features/internal-api.md, "Puzzle image
 * analysis" - requires): only puzzles whose **current** picture already has a result of every listed analyzer, of any
 * analyzer version. A result made from an older picture (another imageFingerprint) does not count - its crop or OCR
 * lines describe a picture the puzzle no longer has.
 *
 * Which analyzer needs which is the pipeline's business (its profiles); the API only filters. No analyzers = no filter.
 * The SQL is one EXISTS per analyzer, each a probe of the unique (analyzer, puzzle_id) index.
 */
readonly final class RequiredImageAnalyses
{
    /**
     * @param list<ImageAnalyzer> $analyzers each once
     */
    private function __construct(
        public array $analyzers,
    ) {
    }

    public static function none(): self
    {
        return new self([]);
    }

    /**
     * @param list<ImageAnalyzer> $analyzers
     */
    public static function of(array $analyzers): self
    {
        $unique = [];

        foreach ($analyzers as $analyzer) {
            $unique[$analyzer->value] = $analyzer;
        }

        return new self(array_values($unique));
    }

    /**
     * Analyzer keys as the API received them (empty ones already dropped). Answers what is wrong with them instead,
     * as the field error: an unknown key, or the analyzer the work is for (it would never hand anything out).
     *
     * @param list<string> $values
     */
    public static function fromValues(array $values, null|ImageAnalyzer $for): self|string
    {
        $analyzers = [];
        $unknown = [];

        foreach ($values as $value) {
            $analyzer = ImageAnalyzer::catalogue($value);

            if ($analyzer === null) {
                $unknown[] = $value;

                continue;
            }

            $analyzers[] = $analyzer;
        }

        if ($unknown !== []) {
            return sprintf(
                'names an unknown analyzer (%s) - each must be one of: %s.',
                implode(', ', $unknown),
                implode(', ', ImageAnalyzer::catalogueValues()),
            );
        }

        if ($for !== null && in_array($for, $analyzers, true)) {
            return sprintf('must not name the analyzer itself (%s).', $for->value);
        }

        return self::of($analyzers);
    }

    public function isEmpty(): bool
    {
        return $this->analyzers === [];
    }

    /**
     * The condition for the puzzle aliased `$puzzleAlias` - '' when nothing is required. Its parameters: parameters().
     */
    public function sqlCondition(string $puzzleAlias): string
    {
        $fingerprint = PuzzleImageFingerprint::sql($puzzleAlias . '.image');
        $conditions = [];

        foreach (array_keys($this->analyzers) as $index) {
            $conditions[] = sprintf(
                'EXISTS (SELECT 1 FROM puzzle_image_analysis required_%1$d WHERE required_%1$d.analyzer = :required_%1$d AND required_%1$d.puzzle_id = %2$s.id AND required_%1$d.image_fingerprint = %3$s)',
                $index,
                $puzzleAlias,
                $fingerprint,
            );
        }

        return implode(' AND ', $conditions);
    }

    /**
     * @return array<string, string>
     */
    public function parameters(): array
    {
        $parameters = [];

        foreach ($this->analyzers as $index => $analyzer) {
            $parameters['required_' . $index] = $analyzer->value;
        }

        return $parameters;
    }

    /**
     * @return list<string>
     */
    public function values(): array
    {
        return array_map(static fn (ImageAnalyzer $analyzer): string => $analyzer->value, $this->analyzers);
    }
}
