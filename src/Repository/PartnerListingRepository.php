<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\PartnerListing;
use SpeedPuzzling\Web\Exceptions\PartnerListingNotFound;

readonly final class PartnerListingRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(PartnerListing $listing): void
    {
        $this->entityManager->persist($listing);
    }

    /**
     * @throws PartnerListingNotFound
     */
    public function get(string $listingId): PartnerListing
    {
        $listing = Uuid::isValid($listingId) ? $this->entityManager->find(PartnerListing::class, $listingId) : null;

        if ($listing === null) {
            throw new PartnerListingNotFound();
        }

        return $listing;
    }

    /**
     * The player's open listing for the event - at most one (custom_partner_listing_one_open).
     */
    public function findOpenFor(string $competitionId, string $playerId): null|PartnerListing
    {
        if (Uuid::isValid($competitionId) === false || Uuid::isValid($playerId) === false) {
            return null;
        }

        /** @var null|PartnerListing $listing */
        $listing = $this->entityManager->createQueryBuilder()
            ->select('listing')
            ->from(PartnerListing::class, 'listing')
            ->where('listing.competition = :competitionId')
            ->andWhere('listing.player = :playerId')
            ->andWhere('listing.closedAt IS NULL')
            ->setParameter('competitionId', $competitionId)
            ->setParameter('playerId', $playerId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $listing;
    }
}
