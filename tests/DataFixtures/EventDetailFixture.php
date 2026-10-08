<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\DataFixtures;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\CompetitionRoundPuzzle;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Value\PuzzleHideMode;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\RoundPuzzleReveal;

/**
 * The series, edition and event pages (docs/features/events-page/detail-pages-plan.md 1.8) on top of EventsPageFixture:
 * round puzzles and a result on the Moonlight Sprint League's multi-session edition, and an in-person multi-day
 * championship with rounds. Made-up names.
 */
final class EventDetailFixture extends Fixture implements DependentFixtureInterface
{
    // Moonlight Sprint League, Season One: a puzzle on Sprint 1 and Sprint 2 (past), a secret one on Sprint 3 (the next)
    public const string ROUND_PUZZLE_SPRINT_1 = '018d0041-0000-0000-0000-000000000001';
    public const string ROUND_PUZZLE_SPRINT_2 = '018d0041-0000-0000-0000-000000000002';
    // hidden entirely until the round starts (automatic reveal): "Puzzles not announced yet", the puzzle nowhere
    public const string ROUND_PUZZLE_SPRINT_3_SECRET = '018d0041-0000-0000-0000-000000000003';

    // PLAYER_REGULAR, solo, PUZZLE_500_01 in Sprint 1 - results on Sprint 1 only
    public const string TIME_SPRINT_1 = '018d0041-0000-0000-0000-000000000011';

    // One-time, in person (cz), Friday-Sunday about five weeks ahead, three rounds - one session
    public const string COMPETITION_HILLTOP_WEEKEND = '018d0041-0000-0000-0000-000000000021';
    public const string COMPETITION_HILLTOP_WEEKEND_NAME = 'Hilltop Puzzle Weekend';
    public const string COMPETITION_HILLTOP_WEEKEND_SLUG = 'hilltop-puzzle-weekend';
    // Friday 18:00 solo, Saturday 10:00 duo (PUZZLE_500_04, picture hidden until it starts), Sunday 10:00 solo - Prague
    public const string ROUND_HILLTOP_FRI = '018d0041-0000-0000-0000-000000000022';
    public const string ROUND_HILLTOP_SAT = '018d0041-0000-0000-0000-000000000023';
    public const string ROUND_HILLTOP_SUN = '018d0041-0000-0000-0000-000000000024';
    public const string ROUND_PUZZLE_HILLTOP_SAT = '018d0041-0000-0000-0000-000000000025';

    public function __construct(
        private readonly ClockInterface $clock,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $now = $this->clock->now();
        $utc = new DateTimeZone('UTC');
        $prague = new DateTimeZone('Europe/Prague');
        $today = new DateTimeImmutable($now->setTimezone($utc)->format('Y-m-d'), $utc);

        $admin = $this->getReference(PlayerFixture::PLAYER_ADMIN, Player::class);
        $regular = $this->getReference(PlayerFixture::PLAYER_REGULAR, Player::class);
        $season = $this->getReference(EventsPageFixture::EDITION_SPRINT_SEASON, Competition::class);

        $sprint1 = $manager->find(CompetitionRound::class, EventsPageFixture::ROUND_SPRINT_1);
        $sprint2 = $manager->find(CompetitionRound::class, EventsPageFixture::ROUND_SPRINT_2);
        $sprint3 = $manager->find(CompetitionRound::class, EventsPageFixture::ROUND_SPRINT_3);
        assert($sprint1 !== null && $sprint2 !== null && $sprint3 !== null);

        $sprintPuzzle = $this->getReference(PuzzleFixture::PUZZLE_500_01, Puzzle::class);

        $manager->persist(new CompetitionRoundPuzzle(Uuid::fromString(self::ROUND_PUZZLE_SPRINT_1), $sprint1, $sprintPuzzle));
        $manager->persist(new CompetitionRoundPuzzle(
            Uuid::fromString(self::ROUND_PUZZLE_SPRINT_2),
            $sprint2,
            $this->getReference(PuzzleFixture::PUZZLE_500_02, Puzzle::class),
        ));
        $manager->persist(new CompetitionRoundPuzzle(
            Uuid::fromString(self::ROUND_PUZZLE_SPRINT_3_SECRET),
            $sprint3,
            $this->getReference(PuzzleFixture::PUZZLE_500_03, Puzzle::class),
            hideUntilRoundStarts: true,
            hideMode: PuzzleHideMode::Entirely,
            revealMode: RoundPuzzleReveal::Automatic,
        ));

        $solvedAt = $sprint1->startsAt->modify('+45 minutes');
        $manager->persist(new PuzzleSolvingTime(
            id: Uuid::fromString(self::TIME_SPRINT_1),
            secondsToSolve: 1800,
            player: $regular,
            puzzle: $sprintPuzzle,
            trackedAt: $solvedAt,
            verified: true,
            team: null,
            finishedAt: $solvedAt,
            comment: null,
            finishedPuzzlePhoto: null,
            firstAttempt: false,
            unboxed: false,
            competitionRound: $sprint1,
            competition: $season,
        ));

        // Hilltop Puzzle Weekend - Friday to Sunday, about five weeks ahead
        $friday = $today->modify('next friday')->modify('+4 weeks');
        $sunday = $friday->modify('+2 days');

        $hilltop = new Competition(
            id: Uuid::fromString(self::COMPETITION_HILLTOP_WEEKEND),
            name: self::COMPETITION_HILLTOP_WEEKEND_NAME,
            slug: self::COMPETITION_HILLTOP_WEEKEND_SLUG,
            shortcut: null,
            logo: null,
            description: null,
            link: null,
            registrationLink: null,
            resultsLink: null,
            location: 'Hilltop',
            locationCountryCode: 'cz',
            dateFrom: $friday,
            dateTo: $sunday,
            tag: null,
            isOnline: false,
            series: null,
            addedByPlayer: $admin,
            approvedAt: $now,
            createdAt: $now,
        );
        $manager->persist($hilltop);
        $this->addReference(self::COMPETITION_HILLTOP_WEEKEND, $hilltop);

        $rounds = [
            [self::ROUND_HILLTOP_FRI, 'Friday Sprint', $friday, '18:00', RoundCategory::Solo, 'friday-sprint'],
            [self::ROUND_HILLTOP_SAT, 'Saturday Pairs', $friday->modify('+1 day'), '10:00', RoundCategory::Duo, 'saturday-pairs'],
            [self::ROUND_HILLTOP_SUN, 'Sunday Final', $sunday, '10:00', RoundCategory::Solo, 'sunday-final'],
        ];

        foreach ($rounds as [$id, $name, $day, $time, $category, $slug]) {
            $round = new CompetitionRound(
                id: Uuid::fromString($id),
                competition: $hilltop,
                name: $name,
                minutesLimit: 90,
                startsAt: new DateTimeImmutable($day->format('Y-m-d') . ' ' . $time, $prague)->setTimezone($utc),
                category: $category,
                slug: $slug,
                timezone: 'Europe/Prague',
            );
            $manager->persist($round);

            if ($id === self::ROUND_HILLTOP_SAT) {
                $manager->persist(new CompetitionRoundPuzzle(
                    Uuid::fromString(self::ROUND_PUZZLE_HILLTOP_SAT),
                    $round,
                    $this->getReference(PuzzleFixture::PUZZLE_500_04, Puzzle::class),
                    hideUntilRoundStarts: true,
                    hideMode: PuzzleHideMode::ImageOnly,
                    revealMode: RoundPuzzleReveal::Automatic,
                ));
            }
        }

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            EventsPageFixture::class,
            PuzzleFixture::class,
            PlayerFixture::class,
            PuzzleSolvingTimeFixture::class,
        ];
    }
}
