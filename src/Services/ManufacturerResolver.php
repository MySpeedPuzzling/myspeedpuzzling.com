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
 * The brand of a new puzzle, from what the form sent: an id is that brand; a typed name is the existing
 * brand of that name ignoring case and spacing - approved or not, whoever added it - and only when there
 * is none a new, unapproved brand (docs/features/brand-duplicates.md). Every write path that takes a
 * brand as text goes through here: AddPuzzleHandler (add form, its correction, multiscan quick-add) and
 * AddPuzzleToCompetitionRoundHandler.
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
        if (Uuid::isValid($brand)) {
            return $this->manufacturerRepository->get($brand);
        }

        $name = self::cleanName($brand);
        $existing = $this->manufacturerRepository->findByNameIgnoringCase($name);

        if ($existing !== null) {
            return $existing;
        }

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
