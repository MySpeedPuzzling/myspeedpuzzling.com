<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\CompetitionSeries;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Value\RoundCategory;

final class CompetitionSeriesFixture extends Fixture implements DependentFixtureInterface
{
    public const string SERIES_EJJ = '018d0005-0000-0000-0000-000000000001';
    public const string SERIES_OFFLINE = '018d0005-0000-0000-0000-000000000002';
    public const string SERIES_PAST_ONLY = '018d0005-0000-0000-0000-000000000003';
    public const string SERIES_UNAPPROVED = '018d0005-0000-0000-0000-000000000004';
    public const string EDITION_EJJ_68 = '018d0005-0000-0000-0000-000000000010';
    public const string EDITION_EJJ_69 = '018d0005-0000-0000-0000-000000000011';
    public const string EDITION_OFFLINE_1 = '018d0005-0000-0000-0000-000000000012';
    public const string EDITION_PAST_ONLY_1 = '018d0005-0000-0000-0000-000000000013';
    public const string EDITION_UNAPPROVED_1 = '018d0005-0000-0000-0000-000000000014';
    public const string ROUND_EJJ_68 = '018d0005-0000-0000-0000-000000000020';
    public const string ROUND_EJJ_69 = '018d0005-0000-0000-0000-000000000021';
    public const string ROUND_OFFLINE_SOLO = '018d0005-0000-0000-0000-000000000022';
    public const string ROUND_OFFLINE_TEAM = '018d0005-0000-0000-0000-000000000023';
    public const string ROUND_PAST_ONLY = '018d0005-0000-0000-0000-000000000024';

    public const string SERIES_PAST_ONLY_NAME = 'Berlin Puzzle Cup';

    public function __construct(
        private readonly ClockInterface $clock,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $adminPlayer = $this->getReference(PlayerFixture::PLAYER_ADMIN, Player::class);

        $series = new CompetitionSeries(
            id: Uuid::fromString(self::SERIES_EJJ),
            name: 'Euro Jigsaw Jam',
            slug: 'euro-jigsaw-jam-series',
            logo: null,
            description: 'Monthly online jigsaw puzzle competition',
            link: 'https://eurojj.com',
            isOnline: true,
            addedByPlayer: $adminPlayer,
            approvedAt: $this->clock->now(),
            createdAt: $this->clock->now(),
        );
        $series->maintainers->add($adminPlayer);
        $manager->persist($series);
        $this->addReference(self::SERIES_EJJ, $series);

        // Past edition
        $edition68 = new Competition(
            id: Uuid::fromString(self::EDITION_EJJ_68),
            name: 'EJJ #68 — February 2026',
            slug: 'ejj-68-february-2026',
            shortcut: null,
            logo: null,
            description: null,
            link: null,
            registrationLink: null,
            resultsLink: 'https://eurojj.com/68/results',
            location: null,
            locationCountryCode: null,
            dateFrom: $this->clock->now()->modify('-30 days'),
            dateTo: $this->clock->now()->modify('-30 days'),
            tag: null,
            isOnline: true,
            series: $series,
        );
        $manager->persist($edition68);

        $round68 = new CompetitionRound(
            id: Uuid::fromString(self::ROUND_EJJ_68),
            competition: $edition68,
            name: 'EJJ #68 — February 2026',
            minutesLimit: 120,
            startsAt: $this->clock->now()->modify('-30 days'),
        );
        $manager->persist($round68);

        // Upcoming edition
        $edition69 = new Competition(
            id: Uuid::fromString(self::EDITION_EJJ_69),
            name: 'EJJ #69 — May 2026',
            slug: 'ejj-69-may-2026',
            shortcut: null,
            logo: null,
            description: null,
            link: null,
            registrationLink: 'https://eurojj.com/69/register',
            resultsLink: null,
            location: null,
            locationCountryCode: null,
            dateFrom: $this->clock->now()->modify('+30 days'),
            dateTo: $this->clock->now()->modify('+30 days'),
            tag: null,
            isOnline: true,
            series: $series,
        );
        $manager->persist($edition69);

        $round69 = new CompetitionRound(
            id: Uuid::fromString(self::ROUND_EJJ_69),
            competition: $edition69,
            name: 'EJJ #69 — May 2026',
            minutesLimit: 120,
            startsAt: $this->clock->now()->modify('+30 days'),
        );
        $manager->persist($round69);

        // Offline recurring series
        $offlineSeries = new CompetitionSeries(
            id: Uuid::fromString(self::SERIES_OFFLINE),
            name: 'Puzzle Meetup Prague',
            slug: 'puzzle-meetup-prague',
            logo: null,
            description: 'Monthly puzzle meetup in Prague',
            link: null,
            isOnline: false,
            location: 'Prague',
            locationCountryCode: 'cz',
            addedByPlayer: $adminPlayer,
            approvedAt: $this->clock->now(),
            createdAt: $this->clock->now(),
        );
        $manager->persist($offlineSeries);
        $this->addReference(self::SERIES_OFFLINE, $offlineSeries);

        // Offline edition with multiple rounds (solo + team)
        $offlineEdition = new Competition(
            id: Uuid::fromString(self::EDITION_OFFLINE_1),
            name: 'Puzzle Meetup #1',
            slug: 'puzzle-meetup-1',
            shortcut: null,
            logo: null,
            description: null,
            link: null,
            registrationLink: null,
            resultsLink: null,
            location: 'Prague',
            locationCountryCode: 'cz',
            dateFrom: $this->clock->now()->modify('+14 days'),
            dateTo: $this->clock->now()->modify('+14 days'),
            tag: null,
            isOnline: false,
            series: $offlineSeries,
        );
        $manager->persist($offlineEdition);

        $soloRound = new CompetitionRound(
            id: Uuid::fromString(self::ROUND_OFFLINE_SOLO),
            competition: $offlineEdition,
            name: 'Solo Round',
            minutesLimit: 60,
            startsAt: $this->clock->now()->modify('+14 days'),
            category: RoundCategory::Solo,
        );
        $manager->persist($soloRound);

        $teamRound = new CompetitionRound(
            id: Uuid::fromString(self::ROUND_OFFLINE_TEAM),
            competition: $offlineEdition,
            name: 'Team Round',
            minutesLimit: 90,
            startsAt: $this->clock->now()->modify('+14 days +2 hours'),
            category: RoundCategory::Team,
        );
        $manager->persist($teamRound);

        // Series whose only edition already happened — must never show as "upcoming"
        $pastOnlySeries = new CompetitionSeries(
            id: Uuid::fromString(self::SERIES_PAST_ONLY),
            name: self::SERIES_PAST_ONLY_NAME,
            slug: 'berlin-puzzle-cup',
            logo: null,
            description: 'Annual puzzle cup in Berlin',
            link: null,
            isOnline: false,
            location: 'Berlin',
            locationCountryCode: 'de',
            addedByPlayer: $adminPlayer,
            approvedAt: $this->clock->now(),
            createdAt: $this->clock->now(),
        );
        $manager->persist($pastOnlySeries);
        $this->addReference(self::SERIES_PAST_ONLY, $pastOnlySeries);

        $pastOnlyEdition = new Competition(
            id: Uuid::fromString(self::EDITION_PAST_ONLY_1),
            name: 'Berlin Puzzle Cup 2026',
            slug: 'berlin-puzzle-cup-2026',
            shortcut: null,
            logo: null,
            description: null,
            link: null,
            registrationLink: null,
            resultsLink: null,
            location: 'Berlin',
            locationCountryCode: 'de',
            dateFrom: $this->clock->now()->modify('-45 days'),
            dateTo: $this->clock->now()->modify('-45 days'),
            tag: null,
            isOnline: false,
            series: $pastOnlySeries,
        );
        $manager->persist($pastOnlyEdition);

        $pastOnlyRound = new CompetitionRound(
            id: Uuid::fromString(self::ROUND_PAST_ONLY),
            competition: $pastOnlyEdition,
            name: 'Berlin Puzzle Cup 2026',
            minutesLimit: 90,
            startsAt: $this->clock->now()->modify('-45 days'),
        );
        $manager->persist($pastOnlyRound);

        // Series still waiting for admin approval — its editions must stay publicly invisible
        // (editions are never approved individually; the series approval governs them).
        $unapprovedSeries = new CompetitionSeries(
            id: Uuid::fromString(self::SERIES_UNAPPROVED),
            name: 'Pending Puzzle League',
            slug: 'pending-puzzle-league',
            logo: null,
            description: 'Online league awaiting approval',
            link: null,
            isOnline: true,
            addedByPlayer: $adminPlayer,
            approvedAt: null,
            createdAt: $this->clock->now(),
        );
        $manager->persist($unapprovedSeries);
        $this->addReference(self::SERIES_UNAPPROVED, $unapprovedSeries);

        $unapprovedEdition = new Competition(
            id: Uuid::fromString(self::EDITION_UNAPPROVED_1),
            name: 'Pending Puzzle League #1',
            slug: 'pending-puzzle-league-1',
            shortcut: null,
            logo: null,
            description: null,
            link: null,
            registrationLink: null,
            resultsLink: null,
            location: null,
            locationCountryCode: null,
            dateFrom: $this->clock->now()->modify('+7 days'),
            dateTo: $this->clock->now()->modify('+7 days'),
            tag: null,
            isOnline: true,
            series: $unapprovedSeries,
        );
        $manager->persist($unapprovedEdition);

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            PlayerFixture::class,
        ];
    }
}
