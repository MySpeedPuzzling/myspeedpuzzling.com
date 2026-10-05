<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use Normalizer;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * The brand code field of a puzzle (`identification_number`): one or more of the brand's own article numbers, stored
 * as the canonical list `"14709, 12000-199"` - each code trimmed, its whitespace collapsed, in upper case, each once,
 * in the order given (docs/features/puzzle-names/README.md, "Data model"). Two codes are the same only when they read
 * the same so ("12-345" and "123-45" are two codes - search may ignore the dash, storage never). The one parser of the
 * stored value and of the form's inputs. Codes are separated by `,` `;` `|` - a slash or a dash belongs to the code
 * ("12000/199").
 */
readonly final class BrandCodeList
{
    // A form takes at most this many codes per field
    public const int FORM_MAX_CODES = 10;

    // The column's length (varchar 255)
    public const int MAX_STORED_LENGTH = 255;

    /**
     * @param list<array{text: string, key: string}> $items Each code as typed (trimmed, whitespace collapsed) and as
     *        stored (in upper case - also what makes two codes one)
     */
    private function __construct(
        private array $items,
    ) {
    }

    public static function fromStored(null|string $value): self
    {
        return self::fromInputs([$value]);
    }

    /**
     * One entry per input of a form - an entry may still hold several codes (pasted with commas), blank ones are
     * dropped.
     *
     * @param array<null|string> $inputs
     */
    public static function fromInputs(array $inputs): self
    {
        $items = [];

        foreach ($inputs as $input) {
            foreach (self::parts($input ?? '') as $code) {
                $key = mb_strtoupper($code);
                // Keyed with a prefix: a numeric string key would become an integer
                $items['k' . $key] ??= ['text' => $code, 'key' => $key];
            }
        }

        return new self(array_values($items));
    }

    /**
     * Every code of both lists, each once, this list's first (a merge: the survivor's codes first).
     */
    public function union(self $other): self
    {
        $items = [];

        foreach ([...$this->items, ...$other->items] as $item) {
            $items['k' . $item['key']] ??= $item;
        }

        return new self(array_values($items));
    }

    /**
     * The same codes in the same order - whatever the case they are stored in.
     */
    public function equals(self $other): bool
    {
        return array_column($this->items, 'key') === array_column($other->items, 'key');
    }

    /**
     * @return list<string> Every code as stored - in upper case
     */
    public function codes(): array
    {
        return array_column($this->items, 'key');
    }

    /**
     * Each code as typed (trimmed, whitespace collapsed) - the stored value of a cleaned list is in upper case already.
     *
     * @return list<string>
     */
    public function display(): array
    {
        return array_column($this->items, 'text');
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /**
     * The column value, `", "`-separated - null without any code.
     */
    public function toStored(): null|string
    {
        return $this->isEmpty() ? null : implode(', ', $this->codes());
    }

    public function fitsColumn(): bool
    {
        return mb_strlen($this->toStored() ?? '') <= self::MAX_STORED_LENGTH;
    }

    /**
     * Whether this list (built from $stored) only writes $stored in its canonical form: the same codes in the same
     * order - whitespace around and inside them, separators between them, case and the same code twice aside.
     */
    public function isFormatOnlyChangeOf(null|string $stored): bool
    {
        return self::fromStored($stored)->equals($this);
    }

    /**
     * A violation on the field when the codes of its inputs together do not fit the column (messages in the
     * validators domain - Symfony's own).
     *
     * @param array<null|string> $inputs
     */
    public static function addViolations(ExecutionContextInterface $context, string $path, array $inputs): void
    {
        if (self::fromInputs($inputs)->fitsColumn() === false) {
            $context->buildViolation((new Length(max: self::MAX_STORED_LENGTH))->maxMessage)
                ->setParameter('{{ limit }}', (string) self::MAX_STORED_LENGTH)
                ->setPlural(self::MAX_STORED_LENGTH)
                ->atPath($path)
                ->addViolation();
        }
    }

    /**
     * The codes of a stored value, separated by `,` `;` `|` (a slash or a dash belongs to the code: "12000/199"),
     * trimmed, as typed - the search key's reading (PuzzleSearchKeys).
     *
     * @return list<string>
     */
    public static function tokens(null|string $value): array
    {
        $tokens = [];

        foreach (preg_split('/[,;|]/', $value ?? '') ?: [] as $token) {
            $token = trim($token);

            if ($token !== '') {
                $tokens[] = $token;
            }
        }

        return $tokens;
    }

    /**
     * The codes of a stored value or an input: full-width forms as ASCII, every whitespace run one space, trimmed.
     *
     * @return list<string>
     */
    private static function parts(string $value): array
    {
        $parts = [];

        foreach (preg_split('/[,;|]/u', self::normalized($value)) ?: [] as $part) {
            $part = trim(preg_replace('/[\s\p{Z}]+/u', ' ', $part) ?? '');

            // A code holds a letter or a digit - "-" or "." alone is a placeholder
            if (preg_match('/[\p{L}\p{N}]/u', $part) === 1) {
                $parts[] = $part;
            }
        }

        return $parts;
    }

    /**
     * Full-width letters, digits and separators as ASCII (NFKC) - the rest of the text as it is.
     */
    private static function normalized(string $value): string
    {
        // ASCII is its own NFKC - nearly every stored value, and the lists are parsed for every option of a picker
        if (preg_match('/[^\x00-\x7F]/', $value) !== 1) {
            return $value;
        }

        $value = mb_scrub($value, 'UTF-8');
        $normalized = Normalizer::normalize($value, Normalizer::FORM_KC);

        return is_string($normalized) ? $normalized : $value;
    }
}
