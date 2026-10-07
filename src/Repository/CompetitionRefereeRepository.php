<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionReferee;
use SpeedPuzzling\Web\Entity\Player;

readonly final class CompetitionRefereeRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(CompetitionReferee $referee): void
    {
        $this->entityManager->persist($referee);
    }

    public function delete(CompetitionReferee $referee): void
    {
        $this->entityManager->remove($referee);
    }

    public function findOne(Competition $competition, Player $player): null|CompetitionReferee
    {
        return $this->entityManager->getRepository(CompetitionReferee::class)->findOneBy([
            'competition' => $competition,
            'player' => $player,
        ]);
    }

    public function countOf(Competition $competition): int
    {
        return $this->entityManager->getRepository(CompetitionReferee::class)->count(['competition' => $competition]);
    }
}
