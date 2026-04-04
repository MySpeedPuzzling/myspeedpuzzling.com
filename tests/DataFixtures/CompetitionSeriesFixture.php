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

final class CompetitionSeriesFixture extends Fixture implements DependentFixtureInterface
{
    public const string SERIES_EJJ = '018d0005-0000-0000-0000-000000000001';
    public const string EDITION_EJJ_68 = '018d0005-0000-0000-0000-000000000010';
    public const string EDITION_EJJ_69 = '018d0005-0000-0000-0000-000000000011';
    public const string ROUND_EJJ_68 = '018d0005-0000-0000-0000-000000000020';
    public const string ROUND_EJJ_69 = '018d0005-0000-0000-0000-000000000021';

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
            slug: null,
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
            slug: null,
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

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            PlayerFixture::class,
        ];
    }
}
