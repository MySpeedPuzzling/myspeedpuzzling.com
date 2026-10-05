<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Manufacturer;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Exceptions\ManufacturerNotFound;
use SpeedPuzzling\Web\Repository\ManufacturerRepository;

/**
 * The brand of a new puzzle (or of a puzzle change proposal), from what the form sent: an id is that brand; a typed name is the existing
 * brand of that name ignoring case and spacing - approved or not, whoever added it - and only when there
 * is none a new, unapproved brand (docs/features/brand-duplicates.md). Every write path that takes a
 * brand as text goes through here: AddPuzzleHandler (add form, its correction, multiscan quick-add),
 * AddPuzzleToCompetitionRoundHandler and SubmitPuzzleChangeRequestHandler ("Suggest a change").
 *
 * Not race-safe: two players typing the same new brand in the same second still get two brands.
 */
readonly final class ManufacturerResolver
{
    public function __construct(
        private ManufacturerRepository $manufacturerRepository,
        private GenerateManufacturerSlug $generateManufacturerSlug,
    ) {
    }

    /**
     * @throws ManufacturerNotFound
     */
    public function resolve(string $brand, Player $addedBy, DateTimeImmutable $now): Manufacturer
    {
        return $this->findExisting($brand) ?? $this->create($brand, $addedBy, $now);
    }

    /**
     * The brand an id or a typed name stands for - null when a typed name matches no brand.
     *
     * @throws ManufacturerNotFound
     */
    public function findExisting(string $brand): null|Manufacturer
    {
        if (Uuid::isValid($brand)) {
            return $this->manufacturerRepository->get($brand);
        }

        return $this->manufacturerRepository->findByNameIgnoringCase(self::cleanName($brand));
    }

    /**
     * A new, unapproved brand of a typed name that findExisting() did not find.
     */
    public function create(string $brand, Player $addedBy, DateTimeImmutable $now): Manufacturer
    {
        $name = self::cleanName($brand);

        $manufacturer = new Manufacturer(
            Uuid::uuid7(),
            $name,
            false,
            $addedBy,
            $now,
            slug: $this->generateManufacturerSlug->fromName($name),
        );

        $this->manufacturerRepository->save($manufacturer);

        return $manufacturer;
    }

    /**
     * Trimmed, inner whitespace collapsed to one space - how a new brand's name is stored.
     */
    public static function cleanName(string $name): string
    {
        return trim((string) preg_replace('/' . ManufacturerRepository::WHITESPACE_PATTERN . '/', ' ', $name));
    }
}
