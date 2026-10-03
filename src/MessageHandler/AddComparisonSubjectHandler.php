<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\ComparisonSubject;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Exceptions\CanNotRemoveYourselfFromComparison;
use SpeedPuzzling\Web\Exceptions\ComparisonLineUpFull;
use SpeedPuzzling\Web\Exceptions\ComparisonSubjectNotAvailable;
use SpeedPuzzling\Web\Exceptions\ComparisonSubjectNotFound;
use SpeedPuzzling\Web\Exceptions\MembershipNotFound;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\AddComparisonSubject;
use SpeedPuzzling\Web\Query\GetPlayerMembership;
use SpeedPuzzling\Web\Repository\ComparisonSubjectRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzlingTeamRepository;
use SpeedPuzzling\Web\Services\ComparisonSubjectVisibility;
use SpeedPuzzling\Web\Value\ComparisonKind;
use SpeedPuzzling\Web\Value\ComparisonLimits;
use SpeedPuzzling\Web\Value\ComparisonSubjectRef;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Puts a player or a pair/team into the owner's line-up of its kind (docs/features/player-comparison.md).
 *
 * - Already there → nothing happens.
 * - The first Solo subject brings the owner in too, as the first row of that line-up.
 * - At the cap (ComparisonLimits, per kind) a named row of the same line-up makes room - the swap; without one the
 *   line-up is full. A swap never grows a line-up, so one that is over the cap (a membership ended) stays as it is.
 * - Without a membership the owner's own Solo row is never swapped out.
 *
 * Everything is checked before anything is changed - a refused add leaves the unit of work untouched.
 */
#[AsMessageHandler]
readonly final class AddComparisonSubjectHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private PuzzlingTeamRepository $puzzlingTeamRepository,
        private ComparisonSubjectRepository $comparisonSubjectRepository,
        private ComparisonSubjectVisibility $comparisonSubjectVisibility,
        private GetPlayerMembership $getPlayerMembership,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws PlayerNotFound
     * @throws ComparisonSubjectNotAvailable
     * @throws ComparisonLineUpFull
     * @throws ComparisonSubjectNotFound
     * @throws CanNotRemoveYourselfFromComparison
     */
    public function __invoke(AddComparisonSubject $message): void
    {
        $owner = $this->playerRepository->get($message->playerId);
        $ownerId = $owner->id->toString();
        $ref = ComparisonSubjectRef::tryFromString($message->subjectRef);

        if ($ref === null) {
            throw new ComparisonSubjectNotAvailable();
        }

        $kind = $this->comparisonSubjectVisibility->availableKind($ownerId, $ref);

        if ($kind === null) {
            throw new ComparisonSubjectNotAvailable();
        }

        $lineUp = [];

        foreach ($this->comparisonSubjectRepository->listByOwner($owner) as $row) {
            if ($row->ref()->equals($ref)) {
                return;
            }

            if ($row->kind() === $kind) {
                $lineUp[] = $row;
            }
        }

        $selfRef = ComparisonSubjectRef::player($ownerId);
        $addSelf = $kind === ComparisonKind::Solo && $lineUp === [] && $ref->equals($selfRef) === false;
        $isMember = $this->isMember($ownerId);
        $cap = ComparisonLimits::forMembership($isMember);
        $replaced = null;

        if (count($lineUp) + ($addSelf ? 2 : 1) > $cap) {
            if ($message->replaceSubjectId === null) {
                throw new ComparisonLineUpFull($kind, $cap);
            }

            $replaced = $this->findRow($lineUp, $message->replaceSubjectId);

            if ($replaced->isSelf() && $isMember === false) {
                throw new CanNotRemoveYourselfFromComparison();
            }
        }

        $subject = $ref->isPlayer()
            ? $this->playerRepository->get($ref->id)
            : $this->puzzlingTeamRepository->get($ref->id);

        if ($replaced !== null) {
            $this->comparisonSubjectRepository->delete($replaced);
        }

        $now = $this->clock->now();

        // Same second, so the order is kept by the ids (uuid7 grows within a process): the owner comes first
        if ($addSelf) {
            $this->comparisonSubjectRepository->save(ComparisonSubject::ofPlayer(Uuid::uuid7(), $owner, $owner, $now));
        }

        $this->comparisonSubjectRepository->save(
            $subject instanceof Player
                ? ComparisonSubject::ofPlayer(Uuid::uuid7(), $owner, $subject, $now)
                : ComparisonSubject::ofTeam(Uuid::uuid7(), $owner, $subject, $now),
        );
    }

    /**
     * @param list<ComparisonSubject> $lineUp
     * @throws ComparisonSubjectNotFound
     */
    private function findRow(array $lineUp, string $comparisonSubjectId): ComparisonSubject
    {
        foreach ($lineUp as $row) {
            if ($row->id->toString() === strtolower($comparisonSubjectId)) {
                return $row;
            }
        }

        // Somebody else's row, a row of another line-up or a made-up id
        throw new ComparisonSubjectNotFound();
    }

    private function isMember(string $playerId): bool
    {
        try {
            return $this->getPlayerMembership->byId($playerId)->isActive($this->clock->now());
        } catch (MembershipNotFound) {
            return false;
        }
    }
}
