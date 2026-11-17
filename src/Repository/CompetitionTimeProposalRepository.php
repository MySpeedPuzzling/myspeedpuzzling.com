<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\CompetitionTimeProposal;
use SpeedPuzzling\Web\Value\RoundEntryRef;
use SpeedPuzzling\Web\Value\TimeProposalStatus;

readonly final class CompetitionTimeProposalRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function find(string $proposalId): null|CompetitionTimeProposal
    {
        if (!Uuid::isValid($proposalId)) {
            return null;
        }

        return $this->entityManager->find(CompetitionTimeProposal::class, strtolower($proposalId));
    }

    /**
     * The entry's pending proposal - one at most
     */
    public function findPendingOfEntry(RoundEntryRef $ref): null|CompetitionTimeProposal
    {
        $column = $ref->isTeam() ? 'proposal.team' : 'proposal.participantRound';

        /** @var null|CompetitionTimeProposal $proposal */
        $proposal = $this->entityManager->createQueryBuilder()
            ->select('proposal')
            ->from(CompetitionTimeProposal::class, 'proposal')
            ->where($column . ' = :entryId')
            ->andWhere('proposal.status = :pending')
            ->setParameter('entryId', $ref->id)
            ->setParameter('pending', TimeProposalStatus::Pending->value)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $proposal;
    }

    /**
     * What a change set of RecordRoundResults may resolve (TimeProposalResolver): the round's pending and confirmed
     * proposals (a confirmed one loses its confirmation when its result is cleared or changed) and the proposals the set
     * names by id (of any status - "no longer pending" is an answer too). One statement.
     *
     * @param list<string> $proposalIds
     * @return array<string, CompetitionTimeProposal> lower-cased id => proposal
     */
    public function forResolving(CompetitionRound $round, array $proposalIds): array
    {
        $proposalIds = array_values(array_filter($proposalIds, static fn (string $id): bool => Uuid::isValid($id)));

        $query = $this->entityManager->createQueryBuilder()
            ->select('proposal')
            ->from(CompetitionTimeProposal::class, 'proposal')
            ->where('proposal.round = :round')
            ->setParameter('round', $round->id->toString())
            ->setParameter('open', [TimeProposalStatus::Pending->value, TimeProposalStatus::Confirmed->value])
            ->orderBy('proposal.tappedAtMs')
            ->addOrderBy('proposal.id');

        if ($proposalIds === []) {
            $query->andWhere('proposal.status IN (:open)');
        } else {
            $query->andWhere('proposal.status IN (:open) OR proposal.id IN (:ids)')->setParameter('ids', $proposalIds);
        }

        /** @var list<CompetitionTimeProposal> $proposals */
        $proposals = $query->getQuery()->getResult();

        $byId = [];
        foreach ($proposals as $proposal) {
            $byId[strtolower($proposal->id->toString())] = $proposal;
        }

        return $byId;
    }

    /**
     * @return list<CompetitionTimeProposal>
     */
    public function findPendingOfRound(CompetitionRound $round): array
    {
        /** @var list<CompetitionTimeProposal> $proposals */
        $proposals = $this->entityManager->createQueryBuilder()
            ->select('proposal')
            ->from(CompetitionTimeProposal::class, 'proposal')
            ->where('proposal.round = :round')
            ->andWhere('proposal.status = :pending')
            ->setParameter('round', $round->id->toString())
            ->setParameter('pending', TimeProposalStatus::Pending->value)
            ->getQuery()
            ->getResult();

        return $proposals;
    }

    public function save(CompetitionTimeProposal $proposal): void
    {
        $this->entityManager->persist($proposal);
    }
}
