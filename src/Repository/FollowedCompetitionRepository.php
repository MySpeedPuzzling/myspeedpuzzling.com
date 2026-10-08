<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\FollowedCompetition;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Value\FollowTarget;
use SpeedPuzzling\Web\Value\FollowTargetKind;

readonly final class FollowedCompetitionRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function find(Player $player, FollowTarget $target): null|FollowedCompetition
    {
        $column = $target->kind === FollowTargetKind::Series ? 'series' : 'competition';

        return $this->entityManager->getRepository(FollowedCompetition::class)->findOneBy([
            'player' => $player,
            $column => $target->id,
        ]);
    }

    public function save(FollowedCompetition $followedCompetition): void
    {
        $this->entityManager->persist($followedCompetition);
    }

    public function delete(FollowedCompetition $followedCompetition): void
    {
        $this->entityManager->remove($followedCompetition);
    }

    /**
     * @return list<FollowedCompetition>
     */
    public function listForCompetition(Competition $competition): array
    {
        /** @var list<FollowedCompetition> $rows */
        $rows = $this->entityManager->getRepository(FollowedCompetition::class)->findBy([
            'competition' => $competition,
        ]);

        return $rows;
    }
}
