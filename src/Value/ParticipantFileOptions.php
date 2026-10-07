<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * How a CSV is read - "auto" unless the organiser overrides it on the preview (design doc D1).
 */
readonly final class ParticipantFileOptions
{
    public const string AUTO = 'auto';

    /** @var list<string> */
    public const array ENCODINGS = [self::AUTO, 'UTF-8', 'UTF-16', 'Windows-1250', 'Windows-1252'];

    /** @var array<string, string> query value => separator */
    public const array SEPARATORS = ['comma' => ',', 'semicolon' => ';', 'tab' => "\t"];

    public function __construct(
        public string $encoding = self::AUTO,
        /** A key of SEPARATORS or AUTO */
        public string $separator = self::AUTO,
    ) {
    }

    public static function fromQuery(mixed $encoding, mixed $separator): self
    {
        return new self(
            encoding: is_string($encoding) && in_array($encoding, self::ENCODINGS, true) ? $encoding : self::AUTO,
            separator: is_string($separator) && array_key_exists($separator, self::SEPARATORS) ? $separator : self::AUTO,
        );
    }

    /**
     * Part of the stash's cache key for a parsed sheet.
     */
    public function key(): string
    {
        return strtolower($this->encoding) . '-' . $this->separator;
    }
}
