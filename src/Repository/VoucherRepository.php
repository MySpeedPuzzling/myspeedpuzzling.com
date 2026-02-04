<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\NoResultException;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Voucher;
use SpeedPuzzling\Web\Exceptions\VoucherNotFound;

readonly final class VoucherRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws VoucherNotFound
     */
    public function get(string $voucherId): Voucher
    {
        if (!Uuid::isValid($voucherId)) {
            throw new VoucherNotFound();
        }

        $voucher = $this->entityManager->find(Voucher::class, $voucherId);

        if ($voucher === null) {
            throw new VoucherNotFound();
        }

        return $voucher;
    }

    /**
     * @throws VoucherNotFound
     */
    public function getByCode(string $code): Voucher
    {
        $queryBuilder = $this->entityManager->createQueryBuilder();

        try {
            $voucher = $queryBuilder->select('voucher')
                ->from(Voucher::class, 'voucher')
                ->where('voucher.code = :code')
                ->setParameter('code', strtoupper(trim($code)))
                ->getQuery()
                ->getSingleResult();

            assert($voucher instanceof Voucher);
            return $voucher;
        } catch (NoResultException) {
            throw new VoucherNotFound();
        }
    }

    public function codeExists(string $code): bool
    {
        $queryBuilder = $this->entityManager->createQueryBuilder();

        $count = $queryBuilder->select('COUNT(voucher.id)')
            ->from(Voucher::class, 'voucher')
            ->where('voucher.code = :code')
            ->setParameter('code', strtoupper(trim($code)))
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    public function save(Voucher $voucher): void
    {
        $this->entityManager->persist($voucher);
    }
}
