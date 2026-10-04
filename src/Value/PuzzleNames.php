<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use Countable;
use SpeedPuzzling\Web\Exceptions\InvalidPuzzleValues;

/**
 * The other names of a puzzle, in order - `puzzle.alternative_names` (docs/features/puzzle-names/README.md).
 * Within one language the first name is the one shown.
 *
 * Two names that fold equal (SearchText) are one name: the language-tagged one is kept over an untagged one, else the
 * one with more accented letters, else the first - at the earlier of the two positions.
 */
readonly final class PuzzleNames implements Countable
{
    // What a form may send - a merge is never capped, it must not fail on names it only collects
    public const int FORM_MAX_NAMES = 20;
    public const int MAX_NAME_LENGTH = 255;

    // Letters only Czech uses among the catalogue's languages - the migration tags such names `cs` the same way
    private const string CZECH_ONLY_LETTERS = '/[ěščřžůťďňĚŠČŘŽŮŤĎŇ]/u';

    /**
     * @param list<PuzzleName> $names
     */
    public function __construct(
        private array $names = [],
    ) {
    }

    /**
     * The entity's column value (or any decoded `alternative_names`) - entries without a name are skipped, unknown
     * keys ignored.
     *
     * @param array<mixed> $rows
     */
    public static function fromArray(array $rows): self
    {
        $names = [];

        foreach ($rows as $row) {
            if (is_array($row) === false || is_string($row['name'] ?? null) === false) {
                continue;
            }

            $language = $row['language'] ?? null;
            $names[] = new PuzzleName($row['name'], is_string($language) && $language !== '' ? $language : null);
        }

        return new self($names);
    }

    /**
     * `alternative_names` as a raw SQL row holds it.
     */
    public static function fromJson(null|string $json): self
    {
        if ($json === null || $json === '') {
            return new self();
        }

        $rows = json_decode($json, true);

        return is_array($rows) ? self::fromArray($rows) : new self();
    }

    /**
     * @return list<array{name: string, language: null|string}>
     */
    public function toArray(): array
    {
        return array_map(
            static fn (PuzzleName $name): array => ['name' => $name->name, 'language' => $name->language],
            $this->names,
        );
    }

    /**
     * @return list<PuzzleName>
     */
    public function all(): array
    {
        return $this->names;
    }

    public function isEmpty(): bool
    {
        return $this->names === [];
    }

    public function count(): int
    {
        return count($this->names);
    }

    /**
     * The name to show in a language: the first one in the same base language (`pt-BR` matches `pt`), never one
     * without a language.
     */
    public function shownFor(string $language): null|PuzzleName
    {
        $base = LanguageTag::base($language);

        foreach ($this->names as $name) {
            if ($name->language !== null && LanguageTag::base($name->language) === $base) {
                return $name;
            }
        }

        return null;
    }

    /**
     * What the single alternative name of old carries (the `alternative_name` column until it is dropped, API v1
     * `alternative_name`): the first Czech name, else the first name.
     */
    public function legacyAlternativeName(): null|string
    {
        $index = $this->legacyIndex();

        return $index !== null ? $this->names[$index]->name : null;
    }

    /**
     * The single "Alternative name" field of the moderator forms applied onto the list: unchanged value = unchanged
     * list, blank = that name removed, another value = that name replaced - keeping its language when it is the same
     * name re-spelled (accents, case), otherwise in Czech when it has a letter only Czech uses, else without one. A
     * puzzle without names gets it as its first, tagged the same way.
     */
    public function withLegacyAlternativeName(null|string $value): self
    {
        $value = self::cleanName($value ?? '');
        $index = $this->legacyIndex();

        if ($index === null) {
            return $value === '' ? $this : new self([new PuzzleName($value, self::guessedLanguage($value))]);
        }

        $replaced = $this->names[$index];

        if ($value === self::cleanName($replaced->name)) {
            return $this;
        }

        $names = $this->names;

        if ($value === '') {
            unset($names[$index]);

            return new self(array_values($names));
        }

        $names[$index] = new PuzzleName(
            $value,
            SearchText::fold($value) === SearchText::fold($replaced->name) ? $replaced->language : self::guessedLanguage($value),
        );

        return new self(array_values($names));
    }

    /**
     * This list, then the other's names it does not hold yet.
     */
    public function union(self $other): self
    {
        return new self(self::deduplicated([...$this->names, ...$other->names]));
    }

    /**
     * What turns $before into this list - applied later onto the list as it is then (PuzzleNamesDiff::applyTo()).
     */
    public function diff(self $before): PuzzleNamesDiff
    {
        $added = $this->names;
        $removed = $before->names;
        $changed = [];

        // Untouched names first, then the same name re-tagged or re-spelled (accents, case), then another name taking
        // the place of one in the same language - whatever is left was added or removed
        foreach (['unchanged', 'respelled', 'replaced'] as $pairing) {
            foreach ($added as $addedIndex => $new) {
                foreach ($removed as $removedIndex => $old) {
                    if (self::pairs($pairing, $new, $old) === false) {
                        continue;
                    }

                    if ($pairing !== 'unchanged') {
                        $changed[] = ['from' => $old, 'to' => $new];
                    }

                    unset($added[$addedIndex], $removed[$removedIndex]);

                    continue 2;
                }
            }
        }

        return new PuzzleNamesDiff(array_values($added), array_values($removed), $changed);
    }

    /**
     * @param int $loadedCount How many names the puzzle had - only a growing list can break the cap: a merge may have
     *                         left more than a form may add, and removing or editing one of them must still work
     *
     * @throws InvalidPuzzleValues
     */
    public function assertFormLimits(int $loadedCount = 0): void
    {
        if (count($this->names) > self::FORM_MAX_NAMES && count($this->names) > $loadedCount) {
            throw new InvalidPuzzleValues(sprintf('A puzzle can have at most %d other names.', self::FORM_MAX_NAMES));
        }

        foreach ($this->names as $name) {
            if (mb_strlen($name->name) > self::MAX_NAME_LENGTH) {
                throw new InvalidPuzzleValues(sprintf('A name can be at most %d characters long.', self::MAX_NAME_LENGTH));
            }
        }
    }

    /**
     * The list as a puzzle stores it next to its main title: names cleaned (cleanName()), empty ones and the ones
     * folding equal to the main title dropped, languages normalised (unknown = none), duplicates merged.
     */
    public function cleanedFor(string $mainTitle): self
    {
        $mainTitleKey = SearchText::fold($mainTitle);
        $names = [];

        foreach ($this->names as $name) {
            $cleaned = self::cleanName($name->name);

            if ($cleaned === '' || SearchText::fold($cleaned) === $mainTitleKey) {
                continue;
            }

            $names[] = new PuzzleName($cleaned, $name->language !== null ? LanguageTag::normalize($name->language) : null);
        }

        return new self(self::deduplicated($names));
    }

    /**
     * A name as stored: control and format characters removed, every whitespace run one space, trimmed.
     */
    public static function cleanName(string $name): string
    {
        $name = preg_replace('/[\s\p{Z}]+/u', ' ', mb_scrub($name, 'UTF-8')) ?? '';
        $name = preg_replace('/[\p{Cc}\p{Cf}]+/u', '', $name) ?? '';
        $name = preg_replace('/ {2,}/', ' ', $name) ?? '';

        return trim($name, ' ');
    }

    private static function pairs(string $pairing, PuzzleName $new, PuzzleName $old): bool
    {
        return match ($pairing) {
            'unchanged' => $new->name === $old->name && $new->language === $old->language,
            'respelled' => SearchText::fold($new->name) === SearchText::fold($old->name),
            default => $new->language === $old->language,
        };
    }

    private static function guessedLanguage(string $name): null|string
    {
        return preg_match(self::CZECH_ONLY_LETTERS, $name) === 1 ? 'cs' : null;
    }

    private function legacyIndex(): null|int
    {
        foreach ($this->names as $index => $name) {
            if ($name->language !== null && LanguageTag::base($name->language) === 'cs') {
                return $index;
            }
        }

        return $this->names !== [] ? 0 : null;
    }

    /**
     * @param list<PuzzleName> $names
     *
     * @return list<PuzzleName>
     */
    private static function deduplicated(array $names): array
    {
        $kept = [];

        foreach ($names as $name) {
            // A prefix keeps numeric keys strings - PHP would turn "1000" into an int key
            $key = 'k' . SearchText::fold($name->name);

            if (array_key_exists($key, $kept) === false) {
                $kept[$key] = $name;
            } elseif (self::isPreferred($name, $kept[$key])) {
                // The same key: it keeps the position of the first one
                $kept[$key] = $name;
            }
        }

        return array_values($kept);
    }

    private static function isPreferred(PuzzleName $candidate, PuzzleName $current): bool
    {
        if (($candidate->language === null) !== ($current->language === null)) {
            return $candidate->language !== null;
        }

        return self::accentedLetters($candidate->name) > self::accentedLetters($current->name);
    }

    private static function accentedLetters(string $name): int
    {
        return (int) preg_match_all('/(?![\x00-\x7F])\p{L}/u', $name);
    }
}
