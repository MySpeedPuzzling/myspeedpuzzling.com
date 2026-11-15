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
use SpeedPuzzling\Web\Value\DataImport\ImportPersonChoice;
use SpeedPuzzling\Web\Value\DataImport\ImportPersonDecidedHow;

/**
 * One distinct person an import's file names among the people of its results
 * (docs/features/data-import/people.md): the name as written, how many rows name them, and who they are - a guest
 * (the default), a registered player (a code of the file that resolved by itself, or the importing player's answer),
 * or the importing player themself. Built with the import's puzzles and kept across a re-parse: a key that is still
 * named keeps its decision. One per import and key (ImportPersonText::$key).
 *
 * **A decision is read when a row is added, never copied onto the rows**: a result that is added already is not
 * changed by a later answer.
 */
#[Entity]
#[UniqueConstraint(columns: ['import_id', 'person_key'])]
class DataImportPerson
{
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(length: 200)]
    public string $name;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column]
    public int $rowsCount = 0;

    /**
     * Up to two rows that name the person - what the page shows as examples.
     *
     * @var list<string>
     */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '[]'])]
    public array $exampleRowIds = [];

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(length: 8, enumType: ImportPersonChoice::class)]
    public ImportPersonChoice $choice = ImportPersonChoice::Guest;

    /** The player the person is - gone with the player's account: the person is a guest again */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[ManyToOne]
    #[JoinColumn(nullable: true, onDelete: 'SET NULL')]
    public null|Player $player = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(length: 16, enumType: ImportPersonDecidedHow::class)]
    public ImportPersonDecidedHow $decidedHow = ImportPersonDecidedHow::ByDefault;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $decidedAt = null;

    /**
     * @param list<string> $exampleRowIds
     */
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
        #[Column(length: 160)]
        public string $personKey,
        string $name,
        /** The file writes a player's code, id or profile address - not a name */
        #[Immutable]
        #[Column]
        public bool $writtenAsPlayer,
        int $rowsCount,
        array $exampleRowIds,
    ) {
        $this->seen($name, $rowsCount, $exampleRowIds);
    }

    /**
     * What the file says of the person as its rows are parsed now.
     *
     * @param list<string> $exampleRowIds
     */
    public function seen(string $name, int $rowsCount, array $exampleRowIds): void
    {
        $this->name = mb_substr($name, 0, 200);
        $this->rowsCount = $rowsCount;
        $this->exampleRowIds = array_slice($exampleRowIds, 0, 2);
    }

    public function decide(ImportPersonChoice $choice, null|Player $player, ImportPersonDecidedHow $how, DateTimeImmutable $now): void
    {
        if (($choice === ImportPersonChoice::Player) !== ($player !== null)) {
            throw new \LogicException('A person of an import is a player with that player, or a guest / the importing player without one.');
        }

        $this->choice = $choice;
        $this->player = $player;
        $this->decidedHow = $how;
        $this->decidedAt = $how === ImportPersonDecidedHow::ByDefault ? null : $now;
    }

    /**
     * Whether nobody - no person, no rule - has decided anything about the person yet.
     */
    public function isUndecided(): bool
    {
        return $this->decidedHow === ImportPersonDecidedHow::ByDefault;
    }

    /**
     * The registered player the person stands for now - null: a guest (also when the player's account is gone).
     */
    public function playerNow(): null|Player
    {
        return $this->choice === ImportPersonChoice::Player ? $this->player : null;
    }
}
