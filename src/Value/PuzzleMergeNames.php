<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The names of puzzles merged into one (docs/features/puzzle-names/README.md, "Writing names and codes"): what a merge
 * gives the survivor when the reviewer leaves the names alone, and what the merge review's names editor starts from.
 * Nothing is lost - every name of every puzzle stays, each main title in the language the reporter said it is in
 * (PuzzleMergeRequest::$reportedNameLanguages), else in the one the puzzle has.
 */
readonly final class PuzzleMergeNames
{
    /**
     * @param list<NamedPuzzle> $merged The puzzles merged into the survivor (deleted by the merge)
     * @param array<string, string> $reportedNameLanguages Puzzle id => the language the reporter gave its name
     */
    public function __construct(
        private NamedPuzzle $survivor,
        private array $merged,
        private array $reportedNameLanguages = [],
    ) {
    }

    /**
     * The survivor's other names after the merge: its own first, then every merged puzzle's other names, then their
     * main titles, then its own main title (dropped again by Puzzle::changeNames() when it stays the main title). The
     * other names come before the main titles on purpose: the first name of a language is the one shown, and the
     * first one at all is the old single alternative name (PuzzleNames::legacyAlternativeName()) - a box name, not a
     * duplicate's English title. Two names folding equal are one (PuzzleNames::union()).
     */
    public function alternativeNames(): PuzzleNames
    {
        $names = $this->survivor->alternativeNames;

        foreach ($this->merged as $puzzle) {
            $names = $names->union($puzzle->alternativeNames);
        }

        foreach ($this->merged as $puzzle) {
            $names = $names->union(new PuzzleNames([$this->mainTitleOf($puzzle)]));
        }

        return $names->union(new PuzzleNames([$this->mainTitleOf($this->survivor)]));
    }

    /**
     * The language of the main title the merge keeps, as the puzzles knew it: a main title's language (the reporter's
     * first), else the language of an other name it is - the survivor's own for a title none of them has. English
     * is no language of a main title (null = English or not known).
     */
    public function nameLanguageOf(string $mainTitle): null|string
    {
        $mainTitleKey = SearchText::fold($mainTitle);
        $puzzles = [$this->survivor, ...$this->merged];

        foreach ($puzzles as $puzzle) {
            if (SearchText::fold($puzzle->name) === $mainTitleKey) {
                return self::asMainTitleLanguage($this->mainTitleOf($puzzle)->language);
            }
        }

        foreach ($puzzles as $puzzle) {
            foreach ($puzzle->alternativeNames->all() as $alternativeName) {
                if (SearchText::fold($alternativeName->name) === $mainTitleKey) {
                    return self::asMainTitleLanguage($alternativeName->language);
                }
            }
        }

        return self::asMainTitleLanguage($this->survivor->nameLanguage);
    }

    /**
     * Where the merge review starts: the survivor's main title, then every other name of all the puzzles.
     */
    public function forReview(): NamedPuzzle
    {
        return new NamedPuzzle(
            puzzleId: $this->survivor->puzzleId,
            name: $this->survivor->name,
            nameLanguage: $this->nameLanguageOf($this->survivor->name),
            alternativeNames: $this->alternativeNames()->cleanedFor($this->survivor->name),
        );
    }

    private function mainTitleOf(NamedPuzzle $puzzle): PuzzleName
    {
        return new PuzzleName($puzzle->name, $this->reportedNameLanguages[$puzzle->puzzleId] ?? $puzzle->nameLanguage);
    }

    private static function asMainTitleLanguage(null|string $language): null|string
    {
        return $language !== null && LanguageTag::base($language) === 'en' ? null : $language;
    }
}
