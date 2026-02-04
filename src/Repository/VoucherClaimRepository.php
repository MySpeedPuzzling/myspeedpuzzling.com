<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\VoucherClaim;

readonly final class VoucherClaimRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(VoucherClaim $claim): void
    {
        $this->entityManager->persist($claim);
    }

    public function findByPlayerAndVoucher(string $playerId, string $voucherId): null|VoucherClaim
    {
        $queryBuilder = $this->entityManager->createQueryBuilder();

        /** @var null|VoucherClaim $result */
        $result = $queryBuilder->select('claim')
            ->from(VoucherClaim::class, 'claim')
            ->where('claim.player = :playerId')
            ->andWhere('claim.voucher = :voucherId')
            ->setParameter('playerId', $playerId)
            ->setParameter('voucherId', $voucherId)
            ->getQuery()
            ->getOneOrNullResult();

        return $result;
    }

    public function hasPlayerClaimedVoucher(string $playerId, string $voucherId): bool
    {
        return $this->findByPlayerAndVoucher($playerId, $voucherId) !== null;
    }

    /**
     * @return array<VoucherClaim>
     */
    public function getClaimsForVoucher(string $voucherId): array
    {
        $queryBuilder = $this->entityManager->createQueryBuilder();

        return $queryBuilder->select('claim')
            ->from(VoucherClaim::class, 'claim')
            ->where('claim.voucher = :voucherId')
            ->setParameter('voucherId', $voucherId)
            ->orderBy('claim.claimedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function countClaimsForVoucher(string $voucherId): int
    {
        $queryBuilder = $this->entityManager->createQueryBuilder();

        $count = $queryBuilder->select('COUNT(claim.id)')
            ->from(VoucherClaim::class, 'claim')
            ->where('claim.voucher = :voucherId')
            ->setParameter('voucherId', $voucherId)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count;
    }
}
