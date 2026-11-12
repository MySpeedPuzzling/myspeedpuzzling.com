<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\CompetitionRoundPuzzle;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Entity\PuzzlingTeam;
use SpeedPuzzling\Web\Entity\PuzzlingTeamMember;
use SpeedPuzzling\Web\Value\PuzzlersGroup;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\TeamComposition;

/**
 * Imported event results nobody has claimed yet (docs/features/event-results-import/README.md "Anonymous slots"): a
 * past, published one-time event with one team round on a puzzle, and team times without a tracker - each its own
 * anonymous puzzling_team. Through the entities and a flush, like the import will: the domain events run too.
 * DAMA rolls everything back with the test.
 */
trait TrackerlessTimeSeeding
{
    protected const string TRACKERLESS_EVENT_NAME = 'Maple Street Puzzle Night';
    protected const string TRACKERLESS_EVENT_SLUG = 'maple-street-puzzle-night';
    protected const string TRACKERLESS_ROUND_SLUG = 'teams';

    protected function seedTrackerlessEvent(
        string $puzzleId,
        string $name = self::TRACKERLESS_EVENT_NAME,
        string $slug = self::TRACKERLESS_EVENT_SLUG,
        RoundCategory $category = RoundCategory::Team,
    ): CompetitionRound {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $now = TestClock::now();

        $competition = new Competition(
            id: Uuid::uuid7(),
            name: $name,
            slug: $slug,
            shortcut: null,
            logo: null,
            description: null,
            link: null,
            registrationLink: null,
            resultsLink: null,
            location: 'Maple Street Library',
            locationCountryCode: 'us',
            dateFrom: $now->modify('-20 days')->setTime(19, 0),
            dateTo: $now->modify('-20 days')->setTime(22, 0),
            tag: null,
            approvedAt: $now->modify('-30 days'),
            createdAt: $now->modify('-30 days'),
        );
        $entityManager->persist($competition);

        $round = new CompetitionRound(
            id: Uuid::uuid7(),
            competition: $competition,
            name: $category === RoundCategory::Team ? 'Teams' : 'Pairs',
            minutesLimit: 90,
            startsAt: $now->modify('-20 days')->setTime(19, 0),
            category: $category,
            slug: self::TRACKERLESS_ROUND_SLUG,
            teamSize: $category === RoundCategory::Team ? 4 : null,
        );
        $entityManager->persist($round);

        $puzzle = $entityManager->find(Puzzle::class, $puzzleId);
        self::assertNotNull($puzzle);
        $entityManager->persist(new CompetitionRoundPuzzle(id: Uuid::uuid7(), round: $round, puzzle: $puzzle));

        $entityManager->flush();

        return $round;
    }

    /**
     * One team of $size anonymous slots, named $teamName, with its time in the round.
     */
    protected function seedTrackerlessTime(CompetitionRound $round, string $puzzleId, string $teamName, int $seconds, int $size = 4): PuzzleSolvingTime
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

        $time = PuzzleSolvingTime::importedFromEvent(
            id: Uuid::uuid7(),
            puzzle: $puzzle,
            competition: $round->competition,
            competitionRound: $round,
            anonymousGroup: $group,
            puzzlingTeam: $team,
            secondsToSolve: $seconds,
            solvedAt: $round->startsAt,
        );
        $entityManager->persist($time);
        $entityManager->flush();

        return $time;
    }
}
