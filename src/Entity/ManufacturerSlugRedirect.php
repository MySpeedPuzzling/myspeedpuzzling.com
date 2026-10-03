<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use JetBrains\PhpStorm\Immutable;

/**
 * The slug of a brand that was merged away. Brand hub pages are public and in the
 * sitemap, so the old address answers 301 to the brand it was merged into
 * (ManufacturerSlugRedirectSubscriber) instead of 404 - and no new brand may take it.
 */
#[Entity]
class ManufacturerSlugRedirect
{
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column]
        public string $slug,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Manufacturer $manufacturer,
        #[Immutable]
        #[Column]
        public DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * The brand this slug points at was merged too - point straight at the survivor,
     * so a redirect never chains.
     */
    public function manufacturerMergedInto(Manufacturer $into): void
    {
        $this->manufacturer = $into;
    }
}
