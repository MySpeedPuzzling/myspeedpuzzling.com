<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use Normalizer;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * The brand code field of a puzzle (`identification_number`): one or more of the brand's own article numbers, stored
 * as the canonical list `"14709, 12000-199"` - each code trimmed, its whitespace collapsed, in upper case, each once
 * (two codes differing only in separators count as one), in the order given (docs/features/puzzle-names/README.md,
 * "Data model"). The one parser of the stored value and of the form's inputs. Codes are separated by `,` `;` `|` - a
 * slash or a dash belongs to the code ("12000/199").
 */
readonly final class BrandCodeList
{
    // A form takes at most this many codes per field
    public const int FORM_MAX_CODES = 10;

    // The column's length (varchar 255)
    private const int MAX_STORED_LENGTH = 255;

    /**
     * @param list<string> $codes
     */
    private function __construct(
        private array $codes,
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
        $codes = [];

        foreach ($inputs as $input) {
            foreach (self::parts($input ?? '') as $token) {
                $key = self::key($token);

                if ($key !== '') {
                    // Keyed with a prefix: a numeric string key would become an integer
                    $codes['k' . $key] ??= mb_strtoupper($token);
                }
            }
        }

        return new self(array_values($codes));
    }

    /**
     * Every code of both lists, each once, this list's first (a merge: the survivor's codes first).
     */
    public function union(self $other): self
    {
        return self::fromInputs([$this->toStored(), $other->toStored()]);
    }

    /**
     * @return list<string>
     */
    public function codes(): array
    {
        return $this->codes;
    }

    /**
     * Each code as shown - as stored.
     *
     * @return list<string>
     */
    public function display(): array
    {
        return $this->codes;
    }

    public function isEmpty(): bool
    {
        return $this->codes === [];
    }

    /**
     * The column value, `", "`-separated - null without any code.
     */
    public function toStored(): null|string
    {
        return $this->isEmpty() ? null : implode(', ', $this->codes);
    }

    /**
     * Whether this list (built from $stored) only writes $stored in its canonical form: the same codes in the same
     * order once spaces, separators and case are ignored (each code counted once) - nothing dropped, nothing split.
     */
    public function isFormatOnlyChangeOf(null|string $stored): bool
    {
        $storedKeys = [];

        foreach (self::parts($stored ?? '') as $token) {
            $key = self::key($token);

            if ($key !== '') {
                $storedKeys['k' . $key] = true;
            }
        }

        $listKeys = [];

        foreach ($this->codes as $code) {
            $listKeys['k' . self::key($code)] = true;
        }

        return array_keys($storedKeys) === array_keys($listKeys);
    }

    /**
     * A violation on the field when the codes of its inputs together do not fit the column (messages in the
     * validators domain - Symfony's own).
     *
     * @param array<null|string> $inputs
     */
    public static function addViolations(ExecutionContextInterface $context, string $path, array $inputs): void
    {
        if (mb_strlen(self::fromInputs($inputs)->toStored() ?? '') > self::MAX_STORED_LENGTH) {
            $context->buildViolation((new Length(max: self::MAX_STORED_LENGTH))->maxMessage)
                ->setParameter('{{ limit }}', (string) self::MAX_STORED_LENGTH)
                ->setPlural(self::MAX_STORED_LENGTH)
                ->atPath($path)
                ->addViolation();
        }
    }

    /**
     * The codes of a stored value, separated by `,` `;` `|` (a slash or a dash belongs to the code: "12000/199"),
     * trimmed - the search key's reading (PuzzleSearchKeys).
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

            if ($part !== '') {
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

    /**
     * What makes two codes the same: their letters and digits, in lower case.
     */
    private static function key(string $code): string
    {
        return mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', $code) ?? '');
    }
}
