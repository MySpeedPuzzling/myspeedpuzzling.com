<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * How a typed search matches the codes of a puzzle - the brand code (`identification_number`) and the EAN - in
 * SearchPuzzle and GetMarketplaceListings. A part of a code matches only from 5 letters/digits on: "1000" is a piece
 * count or a name, and as a part of any EAN it found half of the catalogue. A shorter search matches a whole code
 * only (the brand code case-insensitively). EANs are compared without leading zeros, which scanners and boxes add or
 * drop.
 *
 * Patterns are ILIKE patterns with the typed `\ % _` escaped. A null pattern never matches (nothing typed, zeros
 * only): Postgres drops `ILIKE NULL` from the plan, the trigram index scans of the other columns stay.
 */
readonly final class PuzzleCodeSearch
{
    public const int MIN_PART_LENGTH = 5;

    private function __construct(
        private null|string $code,
        private null|string $ean,
    ) {
    }

    public static function fromUserInput(null|string $search): self
    {
        $code = trim($search ?? '');
        $ean = ltrim($code, '0');

        return new self(
            code: $code !== '' ? $code : null,
            ean: $ean !== '' ? $ean : null,
        );
    }

    /**
     * `identification_number ILIKE :pattern` - a part of the brand code, or for a short search the whole code
     */
    public function codePattern(): null|string
    {
        return self::pattern($this->code);
    }

    /**
     * `ean ILIKE :pattern` - a part of the EAN (list), or for a short search the whole column
     */
    public function eanPattern(): null|string
    {
        return self::pattern($this->ean);
    }

    /**
     * The match score of the catalogue and marketplace search: the whole code (`identification_number ILIKE
     * :codeSearchQuery`, `ltrim(ean, '0') = :eanSearchQuery`), then a code ending or starting with the search - for
     * 5+ letters/digits only, like any other part of a code.
     *
     * @return array{
     *     codeSearchQuery: null|string,
     *     codeSearchStartLikeQuery: null|string,
     *     codeSearchEndLikeQuery: null|string,
     *     eanSearchQuery: null|string,
     *     eanSearchStartLikeQuery: null|string,
     *     eanSearchEndLikeQuery: null|string,
     * }
     */
    public function scoreParameters(): array
    {
        $codePart = $this->code !== null && self::isPartLongEnough($this->code) ? self::escape($this->code) : null;
        $eanPart = $this->ean !== null && self::isPartLongEnough($this->ean) ? self::escape($this->ean) : null;

        return [
            'codeSearchQuery' => $this->code !== null ? self::escape($this->code) : null,
            'codeSearchStartLikeQuery' => $codePart !== null ? '%' . $codePart : null,
            'codeSearchEndLikeQuery' => $codePart !== null ? $codePart . '%' : null,
            'eanSearchQuery' => $this->ean,
            'eanSearchStartLikeQuery' => $eanPart !== null ? '%' . $eanPart : null,
            'eanSearchEndLikeQuery' => $eanPart !== null ? $eanPart . '%' : null,
        ];
    }

    private static function pattern(null|string $value): null|string
    {
        if ($value === null) {
            return null;
        }

        return self::isPartLongEnough($value) ? '%' . self::escape($value) . '%' : self::escape($value);
    }

    private static function isPartLongEnough(string $value): bool
    {
        return mb_strlen(preg_replace('/[^\p{L}\p{N}]/u', '', $value) ?? '') >= self::MIN_PART_LENGTH;
    }

    private static function escape(string $value): string
    {
        return addcslashes($value, '\\%_');
    }
}
