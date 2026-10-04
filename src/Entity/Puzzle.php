<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use DateTimeImmutable;
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
use SpeedPuzzling\Web\Exceptions\InvalidPuzzleValues;
use SpeedPuzzling\Web\Value\LanguageTag;
use SpeedPuzzling\Web\Value\PuzzleNames;
use SpeedPuzzling\Web\Value\PuzzleSearchKeys;

#[Entity]
#[Index(columns: ['pieces_count'])]
#[Index(columns: ['identification_number'])]
#[Index(columns: ['ean'])]
class Puzzle
{
    /**
     * The other names, in order: [{name, language}] - read them as alternativeNames(), change them with changeNames()
     *
     * @var list<array{name: string, language: null|string}>
     */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::JSONB, options: ['default' => '[]'])]
    public array $alternativeNames = [];

    // BCP 47 language of the main title when it is not English (a brand selling in one language only), null = English or not known
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(length: 16, nullable: true)]
    public null|string $nameLanguage = null;

    // The last change of any name (sitemap lastmod) - null until the first change after the puzzle was added
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $namesChangedAt = null;

    // PuzzleSearchKeys::names() of the names - maintained here, never written by SQL
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::TEXT, nullable: true)]
    public null|string $searchNames = null;

    // PuzzleSearchKeys::codes() of the EAN and brand code - maintained here, never written by SQL
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::TEXT, nullable: true)]
    public null|string $searchCodes = null;

    // The single alternative name of old: PuzzleNames::legacyAlternativeName(), written along until the column is dropped
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true)]
    public null|string $alternativeName = null;

    /**
     * @throws InvalidPuzzleValues
     */
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Column]
        public int $piecesCount,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column]
        public string $name,
        #[Column]
        public bool $approved,
        #[Column(nullable: true)]
        public null|string $image = null,
        #[Column(nullable: true)]
        public null|float $imageRatio = null,
        #[ManyToOne]
        public null|Manufacturer $manufacturer = null,
        PuzzleNames $alternativeNames = new PuzzleNames(),
        #[Immutable]
        #[ManyToOne]
        public null|Player $addedByUser = null,
        #[Immutable]
        #[Column(nullable: true)]
        public null|DateTimeImmutable $addedAt = null,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(nullable: true)]
        public null|string $identificationNumber = null,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(nullable: true)]
        public null|string $ean = null,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column]
        public bool $isAvailable = false,
        #[Column(nullable: true)]
        public null|DateTimeImmutable $hideImageUntil = null,
        #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
        public null|DateTimeImmutable $hideUntil = null,
        // Who approved the puzzle in the approval queue, and when. Both stay null for
        // puzzles approved before the queue existed (by SQL) or added approved.
        #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
        public null|DateTimeImmutable $approvedAt = null,
        #[ManyToOne]
        #[JoinColumn(onDelete: 'SET NULL')]
        public null|Player $approvedBy = null,
        null|string $nameLanguage = null,
    ) {
        $this->applyNames($name, $nameLanguage, $alternativeNames);
        $this->searchCodes = PuzzleSearchKeys::codes($this->ean, $this->identificationNumber);
    }

    public function alternativeNames(): PuzzleNames
    {
        return PuzzleNames::fromArray($this->alternativeNames);
    }

    /**
     * The only way names change. Names are cleaned (PuzzleNames::cleanName()), other names folding equal to the main
     * title or to each other are dropped (PuzzleNames::cleanedFor()), languages normalised. The number of other names
     * is not capped here - a merge collects them and must not fail; form-driven writers check
     * PuzzleNames::assertFormLimits() first. `namesChangedAt` moves only when something really changed.
     *
     * @throws InvalidPuzzleValues An empty main title or one longer than 255 characters - nothing is changed then
     */
    public function changeNames(
        string $name,
        null|string $nameLanguage,
        PuzzleNames $alternatives,
        DateTimeImmutable $now,
    ): void {
        $before = [$this->name, $this->nameLanguage, $this->alternativeNames];

        $this->applyNames($name, $nameLanguage, $alternatives);

        if ($before !== [$this->name, $this->nameLanguage, $this->alternativeNames]) {
            $this->namesChangedAt = $now;
        }
    }

    /**
     * Both search keys built again from the names and codes as they are - after SearchText::VERSION changed
     * (myspeedpuzzling:rebuild-puzzle-search-keys). Nothing else changes.
     */
    public function refreshSearchKeys(): void
    {
        $this->searchNames = PuzzleSearchKeys::names($this->name, $this->alternativeNames());
        $this->searchCodes = PuzzleSearchKeys::codes($this->ean, $this->identificationNumber);
    }

    public function approve(Player $approvedBy, DateTimeImmutable $approvedAt): void
    {
        $this->approved = true;
        $this->approvedBy = $approvedBy;
        $this->approvedAt = $approvedAt;
    }

    /**
     * The add form sent again after the result in it was refused (e.g. a mistyped piece count made the time
     * impossible): the player who just added the puzzle corrects it. AddPuzzleHandler allows it only while the
     * puzzle is unapproved and nothing uses it yet (docs/features/duplicate-results.md, Layer 1).
     */
    public function correctNewlyAdded(
        string $name,
        PuzzleNames $alternativeNames,
        int $piecesCount,
        Manufacturer $manufacturer,
        string $image,
        null|float $imageRatio,
        null|string $ean,
        null|string $identificationNumber,
        DateTimeImmutable $now,
    ): void {
        $this->changeNames($name, $this->nameLanguage, $alternativeNames, $now);
        $this->piecesCount = $piecesCount;
        $this->manufacturer = $manufacturer;
        $this->image = $image;
        $this->imageRatio = $imageRatio;
        $this->updateProductIdentifiers($ean, $identificationNumber);
    }

    public function updateProductIdentifiers(null|string $ean, null|string $identificationNumber): void
    {
        $this->ean = $ean;
        $this->identificationNumber = $identificationNumber;
        $this->searchCodes = PuzzleSearchKeys::codes($ean, $identificationNumber);
    }

    /**
     * Validates first, then writes the names, the name search key and the old single alternative name.
     *
     * @throws InvalidPuzzleValues
     */
    private function applyNames(string $name, null|string $nameLanguage, PuzzleNames $alternatives): void
    {
        $name = PuzzleNames::cleanName($name);

        if ($name === '') {
            throw new InvalidPuzzleValues('The puzzle needs a name.');
        }

        if (mb_strlen($name) > PuzzleNames::MAX_NAME_LENGTH) {
            throw new InvalidPuzzleValues(sprintf('The name can be at most %d characters long.', PuzzleNames::MAX_NAME_LENGTH));
        }

        $alternatives = $alternatives->cleanedFor($name);
        $legacyAlternativeName = $alternatives->legacyAlternativeName();

        $this->name = $name;
        $this->nameLanguage = $nameLanguage !== null ? LanguageTag::normalize($nameLanguage) : null;
        $this->alternativeNames = $alternatives->toArray();
        $this->alternativeName = $legacyAlternativeName !== null ? mb_substr($legacyAlternativeName, 0, PuzzleNames::MAX_NAME_LENGTH) : null;
        $this->searchNames = PuzzleSearchKeys::names($name, $alternatives);
    }
}
