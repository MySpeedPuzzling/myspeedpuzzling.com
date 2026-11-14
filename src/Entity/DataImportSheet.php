<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Index;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Value\DataImportRowMeaning;
use SpeedPuzzling\Web\Value\DataImportSheetKind;

/**
 * A sheet (tab) of an import (docs/features/data-import/README.md "Data model"). Written by the reader, which is not
 * built yet - the table exists so the later stages add little.
 */
#[Entity]
#[Index(columns: ['import_id', 'position'])]
class DataImportSheet
{
    #[Column(length: 100, nullable: true)]
    public null|string $googleGid = null;

    #[Column]
    public bool $included = true;

    #[Column(length: 16, nullable: true, enumType: DataImportSheetKind::class)]
    public null|DataImportSheetKind $kind = null;

    /** The table or range that was read */
    #[Column(type: Types::TEXT, nullable: true)]
    public null|string $region = null;

    #[Column(nullable: true)]
    public null|int $headerRow = null;

    #[Column(length: 80, nullable: true)]
    public null|string $headerSignature = null;

    #[Column(length: 16, nullable: true, enumType: DataImportRowMeaning::class)]
    public null|DataImportRowMeaning $rowMeaning = null;

    /** @var null|array<mixed> */
    #[Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    public null|array $mapping = null;

    /** @var null|array<mixed> date order, year, sheet-wide values, the lookup sheet */
    #[Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    public null|array $options = null;

    /** @var null|array<mixed> */
    #[Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    public null|array $valueAnswers = null;

    #[Column]
    public int $rowsTotal = 0;

    #[Column]
    public int $rowsLeftOut = 0;

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(name: 'import_id', nullable: false, onDelete: 'CASCADE')]
        public DataImport $import,
        #[Immutable]
        #[Column]
        public int $position,
        #[Immutable]
        #[Column(type: Types::TEXT)]
        public string $title,
    ) {
    }
}
