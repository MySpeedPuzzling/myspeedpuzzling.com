<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\UniqueConstraint;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Value\DataImport\ImportLayoutContent;

/**
 * A layout of a spreadsheet a player set up once (docs/features/data-import/layouts.md): the columns and answers
 * they confirmed for a sheet, kept by the shape of its header row, and applied by themselves the next time a sheet
 * of that shape arrives. One per player and signature; private, and gone with the player.
 */
#[Entity]
#[UniqueConstraint(columns: ['player_id', 'signature'])]
class ImportLayout
{
    public const int MAX_NAME_LENGTH = 200;

    /** How often it was applied to a sheet of a later import */
    #[Column(options: ['default' => 0])]
    public int $timesUsed = 0;

    /** Saved, saved again or applied */
    #[Column(type: Types::DATETIME_IMMUTABLE)]
    public DateTimeImmutable $lastUsedAt;

    /**
     * @param list<string> $headers the header keys of the sheet (ImportLayoutMatcher::headerKeys()), left to right -
     *        what a sheet with most of the same headers is known by
     * @param array<string, mixed> $content ImportLayoutContent's stored shape
     */
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Player $player,
        /** `L1:` + sha256 of the header keys in their order (ImportLayoutMatcher::signature()) */
        #[Immutable]
        #[Column(length: 80)]
        public string $signature,
        /** The file's and the sheet's name when it was saved - the player may rename it */
        #[Column(type: Types::TEXT)]
        public string $name,
        #[Column(type: Types::JSON, options: ['jsonb' => true])]
        public array $headers,
        #[Column(type: Types::JSON, options: ['jsonb' => true])]
        public array $content,
        /** The player changed what a known export's columns are (docs/features/data-import/layouts.md "Known exports") */
        #[Column(options: ['default' => false])]
        public bool $changesKnownExport,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $createdAt,
    ) {
        $this->lastUsedAt = $createdAt;
    }

    public function isOwnedBy(string $playerId): bool
    {
        return $this->player->id->toString() === strtolower($playerId);
    }

    /**
     * The player confirmed a sheet of this shape again: their newest word replaces the older one. The name stays -
     * it may be the player's own.
     *
     * @param list<string> $headers
     */
    public function savedAgain(array $headers, ImportLayoutContent $content, bool $changesKnownExport, DateTimeImmutable $now): void
    {
        $this->headers = $headers;
        $this->content = $content->toArray();
        $this->changesKnownExport = $this->changesKnownExport || $changesKnownExport;
        $this->lastUsedAt = $now;
    }

    public function applied(DateTimeImmutable $now): void
    {
        $this->timesUsed++;
        $this->lastUsedAt = $now;
    }

    public function rename(string $name): void
    {
        $this->name = $name;
    }
}
