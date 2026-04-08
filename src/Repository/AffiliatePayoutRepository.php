<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\AffiliatePayout;
use SpeedPuzzling\Web\Exceptions\AffiliatePayoutNotFound;

readonly class AffiliatePayoutRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws AffiliatePayoutNotFound
     */
    public function get(string $payoutId): AffiliatePayout
    {
        if (!Uuid::isValid($payoutId)) {
            throw new AffiliatePayoutNotFound();
        }

        $payout = $this->entityManager->find(AffiliatePayout::class, $payoutId);

        if ($payout === null) {
            throw new AffiliatePayoutNotFound();
        }

        return $payout;
    }

    public function existsByStripeInvoiceId(string $stripeInvoiceId): bool
    {
        $queryBuilder = $this->entityManager->createQueryBuilder();

        $count = $queryBuilder->select('COUNT(payout.id)')
            ->from(AffiliatePayout::class, 'payout')
            ->where('payout.stripeInvoiceId = :invoiceId')
            ->setParameter('invoiceId', $stripeInvoiceId)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count > 0;
    }

    public function save(AffiliatePayout $payout): void
    {
        $this->entityManager->persist($payout);
    }
}
