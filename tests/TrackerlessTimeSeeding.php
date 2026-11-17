<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\CompetitionRoundPuzzle;
use SpeedPuzzling\Web\Entity\EventImportedResult;
use SpeedPuzzling\Web\Entity\EventResultsSheet;
use SpeedPuzzling\Web\Entity\EventResultsSheetTab;
use SpeedPuzzling\Web\Entity\Organization;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Entity\PuzzlingTeam;
use SpeedPuzzling\Web\Entity\PuzzlingTeamMember;
use SpeedPuzzling\Web\Entity\RelayResult;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\PuzzlersGroup;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\SearchText;
use SpeedPuzzling\Web\Value\TeamComposition;
use SpeedPuzzling\Web\Value\UnfinishedResult;

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
        // A relay round (solo-and-relays.md §2): its second puzzle
        null|string $secondPuzzleId = null,
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
            name: match ($category) {
                RoundCategory::Team => 'Teams',
                RoundCategory::Duo => 'Pairs',
                RoundCategory::Solo => 'Individual',
            },
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

        if ($secondPuzzleId !== null) {
            $secondPuzzle = $entityManager->find(Puzzle::class, $secondPuzzleId);
            self::assertNotNull($secondPuzzle);
            $entityManager->persist(new CompetitionRoundPuzzle(id: Uuid::uuid7(), round: $round, puzzle: $secondPuzzle));
        }

        $entityManager->flush();

        return $round;
    }

    /**
     * A relay team nobody has claimed yet (docs/features/event-results-import/solo-and-relays.md §2): one result per puzzle
     * of the round, linked by one RelayResult with the total, sharing one anonymous pair/team of $size (a single person's
     * results without a team for $size 1), each with its share of the total as time spent - as the import writes them.
     *
     * @param non-empty-list<string> $puzzleIds
     * @return non-empty-list<PuzzleSolvingTime> in the order of the puzzles
     */
    protected function seedTrackerlessRelay(CompetitionRound $round, array $puzzleIds, string $teamName, int $totalSeconds, int $size = 4): array
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $group = null;
        $team = null;

        if ($size > 1) {
            $slots = [];
            for ($i = 0; $i < $size; $i++) {
                $slots[] = TeamComposition::ANONYMOUS_PREFIX . Uuid::uuid7()->toString();
            }

            $group = PuzzlersGroup::anonymous($slots);
            $composition = TeamComposition::fromGroup($group);

            $team = new PuzzlingTeam(Uuid::uuid7(), $composition->key, $composition->size(), TestClock::now(), $teamName);
            $entityManager->persist($team);

            foreach ($composition->members as $member) {
                $entityManager->persist(new PuzzlingTeamMember(Uuid::uuid7(), $team, $member->memberKey, null, null, $member->position));
            }
        }

        $relay = new RelayResult(Uuid::uuid7(), $totalSeconds, TestClock::now());
        $entityManager->persist($relay);
        $shares = RelayResult::shares($totalSeconds, count($puzzleIds));
        $times = [];

        foreach ($puzzleIds as $leg => $puzzleId) {
            $puzzle = $entityManager->find(Puzzle::class, $puzzleId);
            self::assertNotNull($puzzle);

            $time = PuzzleSolvingTime::importedRelayFromEvent(
                id: Uuid::uuid7(),
                puzzle: $puzzle,
                competition: $round->competition,
                competitionRound: $round,
                anonymousGroup: $group,
                puzzlingTeam: $team,
                relayResult: $relay,
                secondsSpent: $shares[$leg],
                solvedAt: $round->startsAt,
            );
            $entityManager->persist($time);
            $entityManager->persist(new EventImportedResult(Uuid::uuid7(), $this->trackerlessSheetTab(), SearchText::fold($teamName), $time, $teamName, $leg));
            $times[] = $time;
        }

        $entityManager->flush();

        return $times;
    }

    /**
     * One team of $size anonymous slots, named $teamName, with its time in the round.
     */
    /**
     * $piecesPlaced: an unfinished team instead (the round's time ran out, $seconds is ignored) - its time spent is the
     * round's limit, as the import writes it
     */
    protected function seedTrackerlessTime(CompetitionRound $round, string $puzzleId, string $teamName, int $seconds, int $size = 4, null|int $piecesPlaced = null): PuzzleSolvingTime
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
            secondsToSolve: $piecesPlaced === null ? $seconds : null,
            solvedAt: $round->startsAt,
            unfinished: $piecesPlaced === null ? null : UnfinishedResult::fromInput($piecesPlaced, null, $puzzle->piecesCount, $round->minutesLimit),
        );
        $entityManager->persist($time);
        $entityManager->flush();

        return $time;
    }

    /**
     * A single person's result nobody has claimed yet (docs/features/event-results-import/solo-and-relays.md §1): a solo
     * result without its player, the sheet's name on its imported row - in any round (a single person at a team event is
     * linked to the event's teams round, D10). $piecesPlaced: unfinished instead, like seedTrackerlessTime().
     */
    protected function seedTrackerlessSoloTime(CompetitionRound $round, string $puzzleId, string $name, int $seconds, null|int $piecesPlaced = null): PuzzleSolvingTime
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $puzzle = $entityManager->find(Puzzle::class, $puzzleId);
        self::assertNotNull($puzzle);

        $time = PuzzleSolvingTime::importedSoloFromEvent(
            id: Uuid::uuid7(),
            puzzle: $puzzle,
            competition: $round->competition,
            competitionRound: $round,
            secondsToSolve: $piecesPlaced === null ? $seconds : null,
            solvedAt: $round->startsAt,
            unfinished: $piecesPlaced === null ? null : UnfinishedResult::fromInput($piecesPlaced, null, $puzzle->piecesCount, $round->minutesLimit),
        );
        $entityManager->persist($time);
        $entityManager->persist(new EventImportedResult(Uuid::uuid7(), $this->trackerlessSheetTab(), SearchText::fold($name), $time, $name));
        $entityManager->flush();

        return $time;
    }

    /**
     * The results sheet tab the seeded single people were imported from - one per test, made on first use.
     */
    private function trackerlessSheetTab(): EventResultsSheetTab
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $existing = $entityManager->getRepository(EventResultsSheetTab::class)->findOneBy(['gid' => 'seeded-solo']);

        if ($existing !== null) {
            return $existing;
        }

        $organization = $entityManager->find(Organization::class, OrganizationFixture::ORGANIZATION_RIVERBEND);
        $actingPlayer = $entityManager->find(Player::class, PlayerFixture::PLAYER_WITH_STRIPE);
        self::assertNotNull($organization);
        self::assertNotNull($actingPlayer);

        $sheet = new EventResultsSheet(
            id: Uuid::uuid7(),
            spreadsheetId: 'seeded-single-people-' . Uuid::uuid7()->toString(),
            organization: $organization,
            actingPlayer: $actingPlayer,
            timezone: 'America/Chicago',
            countryCode: 'us',
            addedAt: TestClock::now(),
        );
        $entityManager->persist($sheet);

        $tab = new EventResultsSheetTab(Uuid::uuid7(), $sheet, 'seeded-solo', 'Seeded single people');
        $entityManager->persist($tab);

        return $tab;
    }
}
