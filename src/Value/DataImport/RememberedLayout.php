<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\DataImport;

use SpeedPuzzling\Web\Exceptions\InvalidImportMapping;

/**
 * The mark on a sheet's mapping that says a layout the player saved earlier was applied to it
 * (docs/features/data-import/layouts.md): which one, under which name it was saved, and whether it fitted the whole
 * sheet or only the columns both have. The screen says so and offers "Start over for this sheet".
 *
 * The name is a copy: the mark must still read well after the layout was renamed or forgotten.
 */
final readonly class RememberedLayout
{
    /** The same headers: every column is as the player set it up */
    public const string WHOLE = 'remembered';
    /** Most of the headers: the columns both have are as the player set them up, the rest is proposed as usual */
    public const string PARTLY = 'remembered_partly';

    public function __construct(
        /** self::WHOLE or self::PARTLY */
        public string $how,
        public string $id,
        public string $name,
        /**
         * What the layout said the sheet is (results / owned / wish list, what a row is, solo / pair / team) was NOT
         * taken over, because this sheet's own name or columns say something else - the screen asks the player to check
         */
        public bool $meaningKept = false,
    ) {
    }

    public function isWhole(): bool
    {
        return $this->how === self::WHOLE;
    }

    /**
     * @return array{how: string, id: string, name: string, meaningKept: bool}
     */
    public function toArray(): array
    {
        return ['how' => $this->how, 'id' => $this->id, 'name' => $this->name, 'meaningKept' => $this->meaningKept];
    }

    /**
     * @param array<mixed> $data
     *
     * @throws InvalidImportMapping
     */
    public static function fromArray(array $data): self
    {
        $remembered = new self(
            StoredShape::string($data, 'how', 30),
            StoredShape::string($data, 'id', 36),
            StoredShape::string($data, 'name', 300),
            StoredShape::bool($data, 'meaningKept'),
        );

        if (in_array($remembered->how, [self::WHOLE, self::PARTLY], true) === false) {
            throw new InvalidImportMapping('A remembered layout fits the whole sheet or a part of it.');
        }

        return $remembered;
    }
}
