<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\DataFixtures;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\DBAL\Connection;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionParticipant;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\CompetitionSeries;
use SpeedPuzzling\Web\Entity\FollowedCompetition;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Value\ParticipantSource;
use SpeedPuzzling\Web\Value\RegistrationStatus;

/**
 * Every kind of event the events page lists (docs/features/events-page/implementation-plan.md, 1.9). Made-up names.
 * Dates are anchored so they hold for weeks after the test database is built: upcoming ones lie at least 20 days
 * ahead, past ones in last year or the year before.
 */
final class EventsPageFixture extends Fixture implements DependentFixtureInterface
{
    // Online series with a country (ca) - counts under Online only
    public const string SERIES_HARBOR_NIGHTS = '018d0040-0000-0000-0000-000000000001';
    public const string SERIES_HARBOR_NIGHTS_NAME = 'Harbor Jigsaw Nights';
    // In person (us), its one edition runs for more than a year
    public const string SERIES_CLOCK_MARATHON = '018d0040-0000-0000-0000-000000000002';
    public const string SERIES_CLOCK_MARATHON_NAME = 'Lakeside Clock Marathon';
    // In person (at), approved, no editions
    public const string SERIES_SUMMIT_LEAGUE = '018d0040-0000-0000-0000-000000000003';
    public const string SERIES_SUMMIT_LEAGUE_NAME = 'Summit Puzzle League';
    // Online (us), one edition whose four rounds are a month or more apart - four sessions
    public const string SERIES_SPRINT_LEAGUE = '018d0040-0000-0000-0000-000000000005';
    public const string SERIES_SPRINT_LEAGUE_NAME = 'Moonlight Sprint League';
    // Approved, then rejected - never listed
    public const string SERIES_OLD_MILL_REJECTED = '018d0040-0000-0000-0000-000000000004';
    public const string SERIES_OLD_MILL_REJECTED_NAME = 'Old Mill Puzzle Nights';

    // Days 5, 12 and 19 of the month two months ahead - one month roll-up row
    public const string EDITION_HARBOR_1 = '018d0040-0000-0000-0000-000000000011';
    public const string EDITION_HARBOR_2 = '018d0040-0000-0000-0000-000000000012';
    public const string EDITION_HARBOR_3 = '018d0040-0000-0000-0000-000000000013';
    // 10 and 24 June of last year - archive roll-up "2 editions in <last year>"
    public const string EDITION_HARBOR_PAST_A = '018d0040-0000-0000-0000-000000000014';
    public const string EDITION_HARBOR_PAST_B = '018d0040-0000-0000-0000-000000000015';
    // No date, no rounds - counted in the series, never listed
    public const string EDITION_HARBOR_UNDATED = '018d0040-0000-0000-0000-000000000016';
    // -30 days to +400 days, named like its series
    public const string EDITION_CLOCK_LONG = '018d0040-0000-0000-0000-000000000017';
    public const string EDITION_OLD_MILL = '018d0040-0000-0000-0000-000000000018';
    // date_from = the first round, date_to = the last; rounds on days -60, -30, +25 and +70, each at 22:00 New York (the
    // next day in UTC)
    public const string EDITION_SPRINT_SEASON = '018d0040-0000-0000-0000-000000000019';
    public const string EDITION_SPRINT_SEASON_NAME = 'Season One';

    // Session 1 starts at 23:30 in Toronto - the next day in UTC
    public const string ROUND_HARBOR_1 = '018d0040-0000-0000-0000-000000000021';
    // Moonlight Sprint League rounds "Sprint 1".."Sprint 4": -60, -30, +25, +70 days
    public const string ROUND_SPRINT_1 = '018d0040-0000-0000-0000-000000000022';
    public const string ROUND_SPRINT_2 = '018d0040-0000-0000-0000-000000000023';
    public const string ROUND_SPRINT_3 = '018d0040-0000-0000-0000-000000000024';
    public const string ROUND_SPRINT_4 = '018d0040-0000-0000-0000-000000000025';
    public const array SPRINT_ROUND_DAYS = [
        self::ROUND_SPRINT_1 => -60,
        self::ROUND_SPRINT_2 => -30,
        self::ROUND_SPRINT_3 => 25,
        self::ROUND_SPRINT_4 => 70,
    ];

    // Hamburg (de), +20..+21 days, managed registration open, capacity 2, two going and one waitlisted
    public const string COMPETITION_RIVERSIDE_OPEN = '018d0040-0000-0000-0000-000000000031';
    public const string COMPETITION_RIVERSIDE_OPEN_NAME = 'Riverside Puzzle Open';
    // In person (ro), no dates, an external registration link
    public const string COMPETITION_MEADOW_TBA = '018d0040-0000-0000-0000-000000000032';
    public const string COMPETITION_MEADOW_TBA_NAME = 'Meadow Puzzle Championship';
    // Online, no dates - "Ongoing online"
    public const string COMPETITION_ENDLESS_RELAY = '018d0040-0000-0000-0000-000000000033';
    public const string COMPETITION_ENDLESS_RELAY_NAME = 'Endless Online Puzzle Relay';
    // In person (cz), 14 March last year, a results link
    public const string COMPETITION_VALLEY_CUP_LAST_YEAR = '018d0040-0000-0000-0000-000000000034';
    // The same, two years ago, no results
    public const string COMPETITION_VALLEY_CUP_TWO_YEARS_AGO = '018d0040-0000-0000-0000-000000000035';
    public const string COMPETITION_VALLEY_CUP_NAME = 'Valley Speed Puzzle Cup';
    // Created by PLAYER_REGULAR, rejected with a reason
    public const string COMPETITION_GARDEN_SWAP_REJECTED = '018d0040-0000-0000-0000-000000000036';
    public const string COMPETITION_GARDEN_SWAP_REJECTED_NAME = 'Garden Swap Evening';
    public const string GARDEN_SWAP_REJECTION_REASON = 'A swap meet without timed rounds.';

    public const string FOLLOW_REGULAR_HARBOR = '018d0040-0000-0000-0000-000000000041';
    public const string FOLLOW_REGULAR_MEADOW = '018d0040-0000-0000-0000-000000000042';
    public const string FOLLOW_FAVORITES_RIVERSIDE = '018d0040-0000-0000-0000-000000000043';

    // Riverside Open: PLAYER_WITH_FAVORITES, an unlinked participant, one on the waitlist
    public const string PARTICIPANT_RIVERSIDE_A = '018d0040-0000-0000-0000-000000000051';
    public const string PARTICIPANT_RIVERSIDE_B = '018d0040-0000-0000-0000-000000000052';
    public const string PARTICIPANT_RIVERSIDE_WAITLISTED = '018d0040-0000-0000-0000-000000000053';

    public function __construct(
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * The Moonlight Sprint League round days as stored (Y-m-d in New York, round order) - tests compare with these
     * instead of "+25 days" from the clock, which moves on after the test database was built.
     *
     * @return list<string>
     */
    public static function storedSprintRoundDays(Connection $connection): array
    {
        /** @var list<string> $startsAt */
        $startsAt = $connection->fetchFirstColumn(
            'SELECT starts_at FROM competition_round WHERE competition_id = :id ORDER BY starts_at',
            ['id' => self::EDITION_SPRINT_SEASON],
        );

        return array_map(
            static fn (string $instant): string => new DateTimeImmutable($instant, new DateTimeZone('UTC'))->setTimezone(new DateTimeZone('America/New_York'))->format('Y-m-d'),
            $startsAt,
        );
    }

    /**
     * The day the test database was built ("today" of this fixture): the first Sprint round is 60 days before it
     */
    public static function builtOn(Connection $connection): DateTimeImmutable
    {
        return new DateTimeImmutable(self::storedSprintRoundDays($connection)[0], new DateTimeZone('UTC'))->modify('+60 days');
    }

    public function load(ObjectManager $manager): void
    {
        $now = $this->clock->now();
        $utc = new DateTimeZone('UTC');
        $today = new DateTimeImmutable($now->setTimezone($utc)->format('Y-m-d'), $utc);
        $lastYear = (int) $today->format('Y') - 1;
        $twoYearsAgo = $lastYear - 1;
        $monthAhead = $today->modify('first day of +2 months');

        $admin = $this->getReference(PlayerFixture::PLAYER_ADMIN, Player::class);
        $regular = $this->getReference(PlayerFixture::PLAYER_REGULAR, Player::class);
        $favorites = $this->getReference(PlayerFixture::PLAYER_WITH_FAVORITES, Player::class);

        // Harbor Jigsaw Nights - online, Canada
        $harbor = $this->series(self::SERIES_HARBOR_NIGHTS, self::SERIES_HARBOR_NIGHTS_NAME, 'harbor-jigsaw-nights', true, null, 'ca', $admin, $now);
        $manager->persist($harbor);
        $this->addReference(self::SERIES_HARBOR_NIGHTS, $harbor);

        foreach ([[self::EDITION_HARBOR_1, 5], [self::EDITION_HARBOR_2, 12], [self::EDITION_HARBOR_3, 19]] as $number => [$id, $day]) {
            $date = $monthAhead->modify('+' . ($day - 1) . ' days');
            $edition = $this->competition($id, 'Session ' . ($number + 1), 'session-' . ($number + 1), null, null, $date, $date, isOnline: true, series: $harbor);
            $manager->persist($edition);
            $this->addReference($id, $edition);

            if ($id === self::EDITION_HARBOR_1) {
                $manager->persist(new CompetitionRound(
                    id: Uuid::fromString(self::ROUND_HARBOR_1),
                    competition: $edition,
                    name: 'Main round',
                    minutesLimit: 90,
                    startsAt: new DateTimeImmutable($date->format('Y-m-d') . ' 23:30', new DateTimeZone('America/Toronto'))->setTimezone($utc),
                    slug: 'main-round',
                    timezone: 'America/Toronto',
                ));
            }
        }

        foreach ([[self::EDITION_HARBOR_PAST_A, 'Spring Session', '06-10'], [self::EDITION_HARBOR_PAST_B, 'Summer Session', '06-24']] as [$id, $name, $day]) {
            $date = new DateTimeImmutable($lastYear . '-' . $day, $utc);
            $edition = $this->competition($id, $name, strtolower(str_replace(' ', '-', $name)), null, null, $date, $date, isOnline: true, series: $harbor);
            $manager->persist($edition);
            $this->addReference($id, $edition);
        }

        $undated = $this->competition(self::EDITION_HARBOR_UNDATED, 'Session to be planned', 'session-to-be-planned', null, null, null, null, isOnline: true, series: $harbor);
        $manager->persist($undated);

        // Lakeside Clock Marathon - in person, United States, a long-running edition named like the series
        $clock = $this->series(self::SERIES_CLOCK_MARATHON, self::SERIES_CLOCK_MARATHON_NAME, 'lakeside-clock-marathon', false, 'Lakeside', 'us', $admin, $now);
        $manager->persist($clock);
        $this->addReference(self::SERIES_CLOCK_MARATHON, $clock);

        $clockEdition = $this->competition(self::EDITION_CLOCK_LONG, self::SERIES_CLOCK_MARATHON_NAME, 'marathon', 'Lakeside', 'us', $today->modify('-30 days'), $today->modify('+400 days'), series: $clock);
        $manager->persist($clockEdition);
        $this->addReference(self::EDITION_CLOCK_LONG, $clockEdition);

        // Moonlight Sprint League - online, one edition with a round a month: every round is its own session
        $sprint = $this->series(self::SERIES_SPRINT_LEAGUE, self::SERIES_SPRINT_LEAGUE_NAME, 'moonlight-sprint-league', true, null, 'us', $admin, $now);
        $manager->persist($sprint);
        $this->addReference(self::SERIES_SPRINT_LEAGUE, $sprint);

        $sprintDays = array_values(self::SPRINT_ROUND_DAYS);
        $sprintSeason = $this->competition(self::EDITION_SPRINT_SEASON, self::EDITION_SPRINT_SEASON_NAME, 'season-one', null, null, $today->modify(sprintf('%+d days', $sprintDays[0])), $today->modify(sprintf('%+d days', $sprintDays[3])), isOnline: true, series: $sprint);
        $manager->persist($sprintSeason);
        $this->addReference(self::EDITION_SPRINT_SEASON, $sprintSeason);
        $number = 0;

        foreach (self::SPRINT_ROUND_DAYS as $roundId => $days) {
            $number++;
            $manager->persist(new CompetitionRound(
                id: Uuid::fromString($roundId),
                competition: $sprintSeason,
                name: 'Sprint ' . $number,
                minutesLimit: 60,
                startsAt: new DateTimeImmutable($today->modify(sprintf('%+d days', $days))->format('Y-m-d') . ' 22:00', new DateTimeZone('America/New_York'))->setTimezone($utc),
                slug: 'sprint-' . $number,
                timezone: 'America/New_York',
            ));
        }

        // Summit Puzzle League - no editions yet
        $summit = $this->series(self::SERIES_SUMMIT_LEAGUE, self::SERIES_SUMMIT_LEAGUE_NAME, 'summit-puzzle-league', false, 'Innsbruck', 'at', $admin, $now);
        $manager->persist($summit);
        $this->addReference(self::SERIES_SUMMIT_LEAGUE, $summit);

        // Old Mill Puzzle Nights - approved, rejected later: neither it nor its edition is ever listed
        $oldMill = $this->series(self::SERIES_OLD_MILL_REJECTED, self::SERIES_OLD_MILL_REJECTED_NAME, 'old-mill-puzzle-nights', false, 'Millbrook', 'gb', $admin, $now);
        $oldMill->reject($admin, $now, 'Duplicate of another series.');
        $manager->persist($oldMill);

        $oldMillEdition = $this->competition(self::EDITION_OLD_MILL, 'Old Mill Night 1', 'old-mill-night-1', 'Millbrook', 'gb', $today->modify('+10 days'), $today->modify('+10 days'), series: $oldMill);
        $manager->persist($oldMillEdition);

        // Riverside Puzzle Open - managed registration, full: two going, one on the waitlist
        $riverside = $this->competition(self::COMPETITION_RIVERSIDE_OPEN, self::COMPETITION_RIVERSIDE_OPEN_NAME, 'riverside-puzzle-open', 'Hamburg', 'de', $today->modify('+20 days'), $today->modify('+21 days'), approvedAt: $now, addedBy: $admin);
        $riverside->changeRegistrationSettings(true, 2, $now->modify('-10 days'), null, 'Europe/Berlin', null, null);
        $manager->persist($riverside);
        $this->addReference(self::COMPETITION_RIVERSIDE_OPEN, $riverside);

        $participantA = new CompetitionParticipant(Uuid::fromString(self::PARTICIPANT_RIVERSIDE_A), 'Riverside Puzzler A', 'us', $riverside, ParticipantSource::SelfJoined);
        $participantA->connect($favorites, $now);
        $participantA->register(RegistrationStatus::Reserved, $now);
        $manager->persist($participantA);

        $participantB = new CompetitionParticipant(Uuid::fromString(self::PARTICIPANT_RIVERSIDE_B), 'Riverside Puzzler B', 'de', $riverside, ParticipantSource::Imported);
        $participantB->register(RegistrationStatus::Reserved, $now);
        $manager->persist($participantB);

        $waitlisted = new CompetitionParticipant(Uuid::fromString(self::PARTICIPANT_RIVERSIDE_WAITLISTED), 'Riverside Puzzler C', 'de', $riverside, ParticipantSource::Imported);
        $waitlisted->register(RegistrationStatus::Waitlisted, $now);
        $manager->persist($waitlisted);

        // Meadow Puzzle Championship - in person, no dates yet, an external registration link
        $meadow = $this->competition(self::COMPETITION_MEADOW_TBA, self::COMPETITION_MEADOW_TBA_NAME, 'meadow-puzzle-championship', 'Brasov', 'ro', null, null, approvedAt: $now, registrationLink: 'https://example.com/meadow/register');
        $manager->persist($meadow);
        $this->addReference(self::COMPETITION_MEADOW_TBA, $meadow);

        // Endless Online Puzzle Relay - online, no dates
        $relay = $this->competition(self::COMPETITION_ENDLESS_RELAY, self::COMPETITION_ENDLESS_RELAY_NAME, 'endless-online-puzzle-relay', null, null, null, null, isOnline: true, approvedAt: $now);
        $manager->persist($relay);
        $this->addReference(self::COMPETITION_ENDLESS_RELAY, $relay);

        // Valley Speed Puzzle Cup - last year (with results) and the year before
        $valleyLastYear = $this->competition(self::COMPETITION_VALLEY_CUP_LAST_YEAR, self::COMPETITION_VALLEY_CUP_NAME . ' ' . $lastYear, 'valley-speed-puzzle-cup-' . $lastYear, 'Valley Town', 'cz', new DateTimeImmutable($lastYear . '-03-14', $utc), new DateTimeImmutable($lastYear . '-03-14', $utc), approvedAt: $now, resultsLink: 'https://example.com/valley/results');
        $manager->persist($valleyLastYear);
        $this->addReference(self::COMPETITION_VALLEY_CUP_LAST_YEAR, $valleyLastYear);

        $valleyTwoYearsAgo = $this->competition(self::COMPETITION_VALLEY_CUP_TWO_YEARS_AGO, self::COMPETITION_VALLEY_CUP_NAME . ' ' . $twoYearsAgo, 'valley-speed-puzzle-cup-' . $twoYearsAgo, 'Valley Town', 'cz', new DateTimeImmutable($twoYearsAgo . '-03-14', $utc), new DateTimeImmutable($twoYearsAgo . '-03-14', $utc), approvedAt: $now);
        $manager->persist($valleyTwoYearsAgo);
        $this->addReference(self::COMPETITION_VALLEY_CUP_TWO_YEARS_AGO, $valleyTwoYearsAgo);

        // Garden Swap Evening - rejected, its creator sees it under "You organize"
        $garden = $this->competition(self::COMPETITION_GARDEN_SWAP_REJECTED, self::COMPETITION_GARDEN_SWAP_REJECTED_NAME, 'garden-swap-evening', 'Garden City', 'cz', $today->modify('+50 days'), $today->modify('+50 days'), addedBy: $regular);
        $garden->reject($admin, $now, self::GARDEN_SWAP_REJECTION_REASON);
        $manager->persist($garden);
        $this->addReference(self::COMPETITION_GARDEN_SWAP_REJECTED, $garden);

        // Follows: PLAYER_REGULAR follows Harbor Jigsaw Nights and Meadow, PLAYER_WITH_FAVORITES follows Riverside Open
        $manager->persist(FollowedCompetition::ofSeries(Uuid::fromString(self::FOLLOW_REGULAR_HARBOR), $regular, $harbor, $now));
        $manager->persist(FollowedCompetition::ofCompetition(Uuid::fromString(self::FOLLOW_REGULAR_MEADOW), $regular, $meadow, $now));
        $manager->persist(FollowedCompetition::ofCompetition(Uuid::fromString(self::FOLLOW_FAVORITES_RIVERSIDE), $favorites, $riverside, $now));

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            PlayerFixture::class,
            CompetitionFixture::class,
            CompetitionSeriesFixture::class,
        ];
    }

    private function series(string $id, string $name, string $slug, bool $isOnline, null|string $location, string $countryCode, Player $addedBy, DateTimeImmutable $now): CompetitionSeries
    {
        return new CompetitionSeries(
            id: Uuid::fromString($id),
            name: $name,
            slug: $slug,
            logo: null,
            description: null,
            link: null,
            isOnline: $isOnline,
            location: $location,
            locationCountryCode: $countryCode,
            addedByPlayer: $addedBy,
            approvedAt: $now,
            approvedByPlayer: $addedBy,
            createdAt: $now,
        );
    }

    private function competition(
        string $id,
        string $name,
        string $slug,
        null|string $location,
        null|string $countryCode,
        null|DateTimeImmutable $dateFrom,
        null|DateTimeImmutable $dateTo,
        bool $isOnline = false,
        null|CompetitionSeries $series = null,
        null|DateTimeImmutable $approvedAt = null,
        null|Player $addedBy = null,
        null|string $registrationLink = null,
        null|string $resultsLink = null,
    ): Competition {
        return new Competition(
            id: Uuid::fromString($id),
            name: $name,
            slug: $slug,
            shortcut: null,
            logo: null,
            description: null,
            link: null,
            registrationLink: $registrationLink,
            resultsLink: $resultsLink,
            location: $location,
            locationCountryCode: $countryCode,
            dateFrom: $dateFrom,
            dateTo: $dateTo,
            tag: null,
            isOnline: $isOnline,
            series: $series,
            addedByPlayer: $addedBy,
            approvedAt: $approvedAt,
            createdAt: $this->clock->now(),
        );
    }
}
