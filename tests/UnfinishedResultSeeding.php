<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Entity\PuzzlingTeam;
use SpeedPuzzling\Web\Entity\PuzzlingTeamMember;
use SpeedPuzzling\Web\Services\PuzzlingTeamResolver;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Value\Puzzler;
use SpeedPuzzling\Web\Value\PuzzlersGroup;
use SpeedPuzzling\Web\Value\SolvingTimeSource;
use SpeedPuzzling\Web\Value\TeamComposition;

/**
 * Unfinished results - pieces placed when a competition round's time ran out, no time
 * (docs/features/competitions-management/unfinished-results-plan.md) - added inside a test, never as global fixtures:
 * step 1 counts their pieces and time spent in every global and personal sum, so a global row would move numbers in
 * unrelated tests. Through the entity's constructor (its invariant checks the row) and a flush; DAMA rolls everything
 * back with the test. Nothing writes such a row in the application yet (the form comes in step 4).
 */
trait UnfinishedResultSeeding
{
    /**
     * A solo result, or a pair's/team's when $puzzlers is given: player ids, or names of guests
     *
     * @param null|list<string> $puzzlers every member of the pair/team, the tracker included
     */
    protected function seedUnfinishedResult(
        string $trackerId,
        string $puzzleId,
        int $piecesPlaced,
        null|int $finishedLaterSeconds = null,
        null|array $puzzlers = null,
        int $daysAgo = 3,
        bool $firstAttempt = false,
        bool $unboxed = false,
        string $competitionId = CompetitionFixture::COMPETITION_WJPC_2024,
        null|CompetitionRound $round = null,
        int $secondsSpent = 5400,
    ): PuzzleSolvingTime {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $tracker = $entityManager->find(Player::class, $trackerId);
        self::assertNotNull($tracker);
        $puzzle = $entityManager->find(Puzzle::class, $puzzleId);
        self::assertNotNull($puzzle);
        $competition = $round !== null ? $round->competition : $entityManager->find(Competition::class, $competitionId);
        self::assertNotNull($competition);

        $group = null;

        if ($puzzlers !== null) {
            $members = [];

            foreach ($puzzlers as $puzzler) {
                $player = Uuid::isValid($puzzler) ? $entityManager->find(Player::class, $puzzler) : null;
                $members[] = $player !== null
                    ? new Puzzler(playerId: $player->id->toString(), playerName: $player->name, playerCode: $player->code, playerCountry: null, isPrivate: false)
                    : new Puzzler(playerId: null, playerName: $puzzler, playerCode: null, playerCountry: null, isPrivate: false);
            }

            self::assertNotEmpty($members);
            $group = new PuzzlersGroup(teamId: null, puzzlers: $members);
        }

        $solvedAt = TestClock::now()->modify("-{$daysAgo} days");

        $time = new PuzzleSolvingTime(
            id: Uuid::uuid7(),
            secondsToSolve: null,
            player: $tracker,
            puzzle: $puzzle,
            trackedAt: $solvedAt,
            verified: false,
            team: $group,
            finishedAt: $solvedAt,
            comment: null,
            finishedPuzzlePhoto: null,
            firstAttempt: $firstAttempt,
            unboxed: $unboxed,
            competitionRound: $round,
            competition: $competition,
            piecesPlaced: $piecesPlaced,
            finishedLaterSeconds: $finishedLaterSeconds,
            puzzlingTeam: self::getContainer()->get(PuzzlingTeamResolver::class)->resolve($group),
            secondsSpent: $finishedLaterSeconds ?? $secondsSpent,
        );
        $entityManager->persist($time);
        $entityManager->flush();

        return $time;
    }

    /**
     * An imported event result nobody has claimed yet (docs/features/event-results-import/README.md) that did not
     * finish: $size anonymous slots, named $teamName, no tracker - like TrackerlessTimeSeeding::seedTrackerlessTime()
     */
    protected function seedUnfinishedImportedTeam(CompetitionRound $round, string $puzzleId, string $teamName, int $piecesPlaced, int $size = 4): PuzzleSolvingTime
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $puzzle = $entityManager->find(Puzzle::class, $puzzleId);
        self::assertNotNull($puzzle);

        $slots = [TeamComposition::ANONYMOUS_PREFIX . Uuid::uuid7()->toString()];
        for ($i = 1; $i < $size; $i++) {
            $slots[] = TeamComposition::ANONYMOUS_PREFIX . Uuid::uuid7()->toString();
        }

        $group = PuzzlersGroup::anonymous($slots);
        $composition = TeamComposition::fromGroup($group);

        $team = new PuzzlingTeam(Uuid::uuid7(), $composition->key, $composition->size(), TestClock::now(), $teamName);
        $entityManager->persist($team);

        foreach ($composition->members as $member) {
            $entityManager->persist(new PuzzlingTeamMember(Uuid::uuid7(), $team, $member->memberKey, null, null, $member->position));
        }

        $time = new PuzzleSolvingTime(
            id: Uuid::uuid7(),
            secondsToSolve: null,
            player: null,
            puzzle: $puzzle,
            trackedAt: $round->startsAt,
            verified: false,
            team: $group,
            finishedAt: $round->startsAt,
            comment: null,
            finishedPuzzlePhoto: null,
            firstAttempt: false,
            unboxed: false,
            competitionRound: $round,
            competition: $round->competition,
            piecesPlaced: $piecesPlaced,
            puzzlingTeam: $team,
            createdVia: SolvingTimeSource::EventImport,
            secondsSpent: ($round->minutesLimit ?? 90) * 60,
        );
        $entityManager->persist($time);
        $entityManager->flush();

        return $time;
    }
}
