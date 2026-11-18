<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\ParticipantImport\Plan;

use SpeedPuzzling\Web\Value\SearchText;

/**
 * Two spellings of probably the same name (design doc D17): case, whitespace, diacritics and apostrophes do not
 * matter (SearchText::fold() - the one fold of the site), dashes of every kind are one.
 * "Zoë O’Brien-Novák" and "zoe o'brien–novak" have the same key.
 *
 * sql() is its twin in Postgres (docs/features/competitions-management/participant-linking.md §3.5) - the expression of
 * the index `custom_player_name_key` on `player (name)` and of the name matches in SQL (link suggestions, the join
 * page's match). The steps: invisible format characters out (first - `unaccent()` would make a soft hyphen a dash), dashes
 * to `-`, apostrophes out, diacritics (`immutable_unaccent()` - ß → ss, Ł → L, Ø → O), lower case, whitespace runs to one
 * space, trimmed.
 * ParticipantNameKeySqlTest pins that both give the same key for the names people are called. What only the PHP side
 * does: NFKC (full-width letters, ligatures), control characters, a combining mark without a letter, the case of
 * scripts the database's collation does not know - none of them in a real name list.
 *
 * Never change sql() alone: the index is built from its text (Version20261118120000, pinned by
 * ParticipantNameKeySqlTest) - a new expression is a new migration that builds the index again.
 */
final class ParticipantNameKey
{
    private const string DASHES = "/[\u{2010}\u{2011}\u{2012}\u{2013}\u{2014}\u{2015}\u{2212}]/u";

    // Postgres regular expressions (advanced syntax): `\uXXXX` is read by the regex engine itself, the SQL literal keeps
    // the backslash as it is (standard_conforming_strings). The classes of SearchText: its apostrophes, its whitespace
    // (\s and \p{Z}), the format characters it drops (soft hyphen, zero-width, direction marks, word joiners, BOM)
    private const string SQL_DASHES = '[\u2010-\u2015\u2212]';
    private const string SQL_APOSTROPHES = '[\'`\u00b4\u02b9\u02bc\u2018\u2019\u201b\u2032\uff07]+';
    private const string SQL_INVISIBLE = '[\u00ad\u200b-\u200f\u202a-\u202e\u2060-\u2064\u2066-\u2069\ufeff]';
    private const string SQL_WHITESPACE = '[[:space:]\u00a0\u1680\u2000-\u200a\u2028\u2029\u202f\u205f\u3000]+';

    public static function of(string $name): string
    {
        return SearchText::fold((string) preg_replace(self::DASHES, '-', $name));
    }

    /**
     * The key of a text column or expression (`p.name`, `CAST(:name AS TEXT)`) as a SQL expression - NULL for NULL.
     * One line, no parameters: the migration and tests/bootstrap.php build the index from sql('name').
     */
    public static function sql(string $column): string
    {
        $dashes = self::literal(self::SQL_DASHES);
        $apostrophes = self::literal(self::SQL_APOSTROPHES);
        $invisible = self::literal(self::SQL_INVISIBLE);
        $whitespace = self::literal(self::SQL_WHITESPACE);

        // Invisible characters go first: unaccent() turns a soft hyphen into "-", which would then stay
        return "btrim(regexp_replace(lower(immutable_unaccent(regexp_replace(regexp_replace(regexp_replace({$column}, {$invisible}, '', 'g'), {$dashes}, '-', 'g'), {$apostrophes}, '', 'g'))), {$whitespace}, ' ', 'g'), ' ')";
    }

    private static function literal(string $text): string
    {
        return "'" . str_replace("'", "''", $text) . "'";
    }
}
