<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\NoResultException;
use Nette\Utils\Random;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Exceptions\CouldNotGenerateUniqueCode;

readonly final class GenerateUniquePlayerCode
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws CouldNotGenerateUniqueCode
     */
    public function generate(): string
    {
        $attempts = 0;

        do {
            $randomCode = Random::generate(6);
            $queryBuilder = $this->entityManager->createQueryBuilder();

            try {
                $queryBuilder->select('player')
                    ->from(Player::class, 'player')
                    ->where('LOWER(player.code) = :code')
                    ->setParameter('code', strtolower($randomCode))
                    ->getQuery()
                    ->getSingleResult();
            } catch (NoResultException) {
                return $randomCode;
            }

            $attempts++;
        } while($attempts <= 5);

        throw new CouldNotGenerateUniqueCode('Could not generate unique code, max attempts reached');
    }
}
