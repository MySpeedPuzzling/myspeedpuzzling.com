<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\Badge;
use SpeedPuzzling\Web\Exceptions\BadgeNotFound;
use SpeedPuzzling\Web\Value\BadgeType;

readonly class BadgeRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(Badge $badge): void
    {
        $this->entityManager->persist($badge);
    }

    /**
     * @throws BadgeNotFound
     */
    public function get(string $badgeId): Badge
    {
        $badge = $this->entityManager->find(Badge::class, $badgeId);

        if ($badge === null) {
            throw new BadgeNotFound();
        }

        return $badge;
    }

    /**
     * @return list<Badge>
     */
    public function findByPlayerAndType(string $playerId, BadgeType $type): array
    {
        /** @var list<Badge> $badges */
        $badges = $this->entityManager->getRepository(Badge::class)->findBy([
            'player' => $playerId,
            'type' => $type,
        ]);

        return $badges;
    }
}
