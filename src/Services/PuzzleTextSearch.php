<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Value\PuzzleSearchQuery;

/**
 * The one SQL fragment of the puzzle text search (docs/features/puzzle-names/README.md, "Search"): what a typed
 * search matches and how well, on the stored search keys `search_names` / `search_codes` - served by the trigram
 * indexes custom_puzzle_search_names_trgm and custom_puzzle_search_codes_trgm. Used by SearchPuzzle (count, page,
 * barcode lookup), GetMarketplaceListings and FindPuzzlesByExactEan, so every search agrees on what matches.
 *
 * A pattern the query has none of (PuzzleSearchQuery: null) leaves its test out of the SQL. DBAL binds only the
 * named parameters a statement holds, so `parameters()` serves every fragment.
 */
readonly final class PuzzleTextSearch
{
    public function __construct(
        private PuzzleSearchQuery $query,
    ) {
    }

    public static function fromUserInput(null|string $search): self
    {
        return new self(PuzzleSearchQuery::fromUserInput($search));
    }

    public function isEmpty(): bool
    {
        return $this->query->isEmpty();
    }

    /**
     * The match as a condition in parentheses - '' when nothing was typed (no text filter at all, browsing).
     *
     * A part of a code (5+ letters or digits) contains every whole code the query could be, so with one the
     * whole-code tests are left out: one index scan less, the same rows.
     */
    public function condition(string $alias): string
    {
        if ($this->query->isEmpty()) {
            return '';
        }

        $tests = ["{$alias}.search_names LIKE :ptsNamesContains"];

        if ($this->query->codePart !== null) {
            $tests[] = "({$alias}.search_codes LIKE :ptsCodePart AND " . self::codesPublic($alias) . ')';
        } else {
            $tests = [...$tests, ...$this->wholeCodeTests($alias)];
        }

        return '(' . implode(' OR ', $tests) . ')';
    }

    /**
     * How well a row matches, for `ORDER BY … DESC`: 6 the exact barcode or brand code, 5 a whole name, 4 a name
     * starts with it, 3 a word of a name starts with it, 2 a name contains it, 1 a part of a code (5+ letters or
     * digits). 0 when nothing was typed.
     */
    public function score(string $alias): string
    {
        if ($this->query->isEmpty()) {
            return '0';
        }

        $tiers = [];
        $wholeCode = $this->wholeCodeTests($alias);

        if ($wholeCode !== []) {
            $tiers[] = 'WHEN ' . implode(' OR ', $wholeCode) . ' THEN 6';
        }

        $tiers[] = "WHEN {$alias}.search_names LIKE :ptsNamesWhole THEN 5";
        $tiers[] = "WHEN {$alias}.search_names LIKE :ptsNamesStart THEN 4";
        $tiers[] = "WHEN {$alias}.search_names LIKE :ptsNamesWordStart THEN 3";
        $tiers[] = "WHEN {$alias}.search_names LIKE :ptsNamesContains THEN 2";

        if ($this->query->codePart !== null) {
            $tiers[] = "WHEN {$alias}.search_codes LIKE :ptsCodePart AND " . self::codesPublic($alias) . ' THEN 1';
        }

        return 'CASE ' . implode(' ', $tiers) . ' ELSE 0 END';
    }

    /**
     * The barcode lookup: the typed number is one of the puzzle's EANs, leading zeros tolerated - never a part of a
     * longer code. Null when the input is no barcode number (nothing can match).
     *
     * The index is asked for a line ending with the number (no tag, no newline before it: their trigrams are in
     * nearly every key and only cost index pages), the whole `e:` line is checked on the few rows it finds.
     */
    public function barcodeCondition(string $alias): null|string
    {
        if ($this->query->eanLine === null) {
            return null;
        }

        return "({$alias}.search_codes LIKE :ptsEanLineEnd AND strpos({$alias}.search_codes, :ptsEanLine) > 0)";
    }

    /**
     * @return array<string, string>
     */
    public function parameters(): array
    {
        return array_filter([
            'ptsNamesContains' => $this->query->namesContains,
            'ptsNamesWhole' => $this->query->namesWhole,
            'ptsNamesStart' => $this->query->namesStart,
            'ptsNamesWordStart' => $this->query->namesWordStart,
            'ptsEanExact' => $this->query->eanExact,
            'ptsCodeExact' => $this->query->codeExact,
            'ptsCodePart' => $this->query->codePart,
            'ptsEanLine' => $this->query->eanLine,
            'ptsEanLineEnd' => $this->query->eanLineEnd,
        ], static fn (null|string $pattern): bool => $pattern !== null);
    }

    /**
     * @return list<string>
     */
    private function wholeCodeTests(string $alias): array
    {
        $tests = [];

        if ($this->query->eanExact !== null) {
            $tests[] = "({$alias}.search_codes LIKE :ptsEanExact AND " . self::codesPublic($alias) . ')';
        }

        if ($this->query->codeExact !== null) {
            $tests[] = "({$alias}.search_codes LIKE :ptsCodeExact AND " . self::codesPublic($alias) . ')';
        }

        return $tests;
    }

    /**
     * A puzzle whose picture a competition keeps secret is never found by its codes - an EAN or brand code gives the
     * box away (PuzzleSecrecy). The database's own clock in UTC, like every stored instant: no caller binds a time.
     */
    private static function codesPublic(string $alias): string
    {
        $now = "(now() AT TIME ZONE 'UTC')";

        return "(({$alias}.hide_image_until IS NULL OR {$alias}.hide_image_until <= {$now})"
            . " AND ({$alias}.hide_until IS NULL OR {$alias}.hide_until <= {$now}))";
    }
}
