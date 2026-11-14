<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\DataImport;

/**
 * What YesNoReader made of one cell (docs/features/data-import/value-readers.md "Yes / no").
 */
final readonly class ReadYesNo
{
    private function __construct(
        public YesNoCertainty $certainty,
        public null|bool $value = null,
        /** An unknown value as typed (cleaned), so the player can say once what it means */
        public null|string $word = null,
    ) {
    }

    public static function blank(): self
    {
        return new self(YesNoCertainty::Blank);
    }

    public static function known(bool $value): self
    {
        return new self(YesNoCertainty::Known, $value);
    }

    public static function unknown(string $word): self
    {
        return new self(YesNoCertainty::Unknown, null, $word);
    }

    public function isYes(): bool
    {
        return $this->value === true;
    }

    public function isNo(): bool
    {
        return $this->value === false;
    }
}
