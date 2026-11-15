<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\PuzzleSearchQuery;
use SpeedPuzzling\Web\Value\SearchText;

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
     * How well a row matches, for `ORDER BY … DESC`: 6 the exact barcode or brand code, 5 a whole name, 4 a name holds
     * it as a whole word (anywhere: "Puzzle Moment: Parrot" for "parrot"), 3 a name or a word of it only starts with it
     * ("Parrots"), 2 a name contains it, 1 a part of a code (5+ letters or digits). 0 when nothing was typed. Within a
     * rank the queries order by the most solved, so a popular whole-word match is not buried under barely solved
     * names that merely begin with the word.
     *
     * The whole-word regex runs only on the rows a name or word start already matched (the LIKE first) - the index
     * still serves only the condition, the score costs 1-3 ms more on a one- or two-letter search over the whole catalogue.
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
        $tiers[] = "WHEN {$alias}.search_names LIKE :ptsNamesStart OR {$alias}.search_names LIKE :ptsNamesWordStart"
            . " THEN CASE WHEN {$alias}.search_names ~ :ptsNamesWholeWord THEN 4 ELSE 3 END";
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
     * **Batch lookups** - many texts against the keys in one statement (the data import's pairing,
     * docs/features/data-import/pairing.md). Not through the trigram indexes: on production a whole-name `LIKE`
     * costs ~8.5 ms and a barcode `LIKE` ~3.7 ms each, so thousands of them are a minute. Instead the catalogue's keys
     * are split into their lines once (sqlNameLines() / sqlCodeLines() - a sequential scan of `puzzle`, ~10 ms) and
     * joined by **equality** with texts written exactly as the lines are (nameLine() / barcodeLine() /
     * brandCodeLine()): 5,000 puzzles in ~150 ms. The line format lives here and in PuzzleSearchKeys only -
     * PuzzleTextSearchLinesTest pins that the two agree. Whether a puzzle may be shown at all (hidden, secret) is the
     * caller's condition.
     *
     * One row per name of every puzzle: `id`, `pieces_count`, `line` (the folded name).
     */
    public static function sqlNameLines(): string
    {
        return "SELECT pts_puzzle.id, pts_puzzle.pieces_count, pts_line AS line"
            . " FROM puzzle pts_puzzle"
            . " CROSS JOIN LATERAL unnest(string_to_array(btrim(pts_puzzle.search_names, E'\\n'), E'\\n')) AS pts_line"
            . " WHERE pts_line <> ''";
    }

    /**
     * One row per code of every puzzle: `id`, `tag` (`e` a barcode number without leading zeros, `c` a brand code as
     * letters and digits - the check-digit aliases among them), `code` (the line without its tag).
     */
    public static function sqlCodeLines(): string
    {
        return "SELECT pts_puzzle.id, substring(pts_line from 1 for 1) AS tag, substring(pts_line from 3) AS code"
            . " FROM puzzle pts_puzzle"
            . " CROSS JOIN LATERAL unnest(string_to_array(btrim(pts_puzzle.search_codes, E'\\n'), E'\\n')) AS pts_line"
            . " WHERE pts_line LIKE 'e:%' OR pts_line LIKE 'c:%'";
    }

    /**
     * A name as a line of the names key holds it - '' when nothing is left.
     */
    public static function nameLine(string $name): string
    {
        return SearchText::fold($name);
    }

    /**
     * A barcode as an `e:` line holds it: its digits without leading zeros. Null when the text is no number (digits
     * with spaces, dashes or dots at most) - exactly what the typed search takes for a barcode.
     */
    public static function barcodeLine(string $number): null|string
    {
        $line = PuzzleSearchQuery::fromUserInput($number)->eanLine;

        return $line === null ? null : substr($line, 3, -1);
    }

    /**
     * A brand code as a `c:` line holds it: letters and digits only. Null without any.
     */
    public static function brandCodeLine(string $code): null|string
    {
        $line = SearchText::code($code);

        return $line === '' ? null : $line;
    }

    /**
     * A text as the similar-name lookup compares it: the search's fold. '' when nothing is left.
     */
    public static function foldedName(string $name): string
    {
        return SearchText::fold($name);
    }

    /**
     * The index part of "a name of the puzzle is similar to this text": the folded text is similar to some stretch
     * of the names key (pg_trgm's word similarity, served by custom_puzzle_search_names_trgm). The stretch need not
     * be one name - nameSimilarity() is the measure, this only narrows the rows. The threshold is the session's
     * `pg_trgm.word_similarity_threshold`: set it with similarNameThresholdStatement() in the same transaction.
     *
     * `$foldedExpression` is an SQL expression holding a foldedName().
     */
    public static function batchSimilarNameCondition(string $alias, string $foldedExpression): string
    {
        return "{$foldedExpression} <% {$alias}.search_names";
    }

    /**
     * How similar the puzzle's most similar name is to the text (0 - 1, pg_trgm's similarity of two whole texts):
     * every line of the names key is compared on its own, so a puzzle's other names neither help nor dilute.
     */
    public static function nameSimilarity(string $alias, string $foldedExpression): string
    {
        return "(SELECT MAX(similarity(pts_name_line, {$foldedExpression}))"
            . " FROM unnest(string_to_array(btrim({$alias}.search_names, E'\\n'), E'\\n')) AS pts_name_line)";
    }

    /**
     * Sets the threshold batchSimilarNameCondition() reads, for the running transaction only. Lower than the
     * similarity asked of a name: the stretch the index finds is measured a little differently than a whole name.
     */
    public static function similarNameThresholdStatement(float $similarity): string
    {
        return sprintf("SELECT set_config('pg_trgm.word_similarity_threshold', '%.2F', true)", max(0.1, $similarity - 0.1));
    }

    /**
     * @return array<string, string>
     */
    public function parameters(null|DateTimeImmutable $now = null): array
    {
        // The code tests compare with :ptsNow - pass the clock's now whenever condition() or score() is used
        $codesNow = $now !== null && $this->query->isEmpty() === false
            ? $now->format('Y-m-d H:i:s')
            : null;

        return array_filter([
            'ptsNow' => $codesNow,
            'ptsNamesContains' => $this->query->namesContains,
            'ptsNamesWhole' => $this->query->namesWhole,
            'ptsNamesStart' => $this->query->namesStart,
            'ptsNamesWordStart' => $this->query->namesWordStart,
            'ptsNamesWholeWord' => $this->query->namesWholeWord,
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
     * box away (PuzzleSecrecy). :ptsNow is the clock's now, bound by parameters($now) like every other surface.
     */
    private static function codesPublic(string $alias): string
    {
        $now = ':ptsNow::timestamp';

        return "(({$alias}.hide_image_until IS NULL OR {$alias}.hide_image_until <= {$now})"
            . " AND ({$alias}.hide_until IS NULL OR {$alias}.hide_until <= {$now}))";
    }
}
