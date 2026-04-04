<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\DataFixtures;

use DateTimeImmutable;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\Tag;

final class CompetitionFixture extends Fixture implements DependentFixtureInterface
{
    public const string COMPETITION_WJPC_2024 = '018d0004-0000-0000-0000-000000000001';
    public const string COMPETITION_CZECH_NATIONALS_2024 = '018d0004-0000-0000-0000-000000000002';
    public const string COMPETITION_UNAPPROVED = '018d0004-0000-0000-0000-000000000003';
    public const string COMPETITION_RECURRING_ONLINE = '018d0004-0000-0000-0000-000000000004';

    public function __construct(
        private readonly ClockInterface $clock,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $wjpcTag = $this->getReference(TagFixture::TAG_WJPC, Tag::class);
        $nationalTag = $this->getReference(TagFixture::TAG_NATIONAL, Tag::class);
        $regularPlayer = $this->getReference(PlayerFixture::PLAYER_REGULAR, Player::class);
        $adminPlayer = $this->getReference(PlayerFixture::PLAYER_ADMIN, Player::class);

        $wjpcCompetition = $this->createCompetition(
            id: self::COMPETITION_WJPC_2024,
            name: 'WJPC 2024',
            location: 'Prague',
            locationCountryCode: 'cz',
            tag: $wjpcTag,
            daysFromNow: 30,
            slug: 'wjpc-2024',
            shortcut: 'WJPC24',
            description: 'World Jigsaw Puzzle Championship 2024',
            link: 'https://wjpc2024.com',
            registrationLink: 'https://wjpc2024.com/register',
            resultsLink: 'https://wjpc2024.com/results',
            approvedAt: $this->clock->now(),
        );
        $manager->persist($wjpcCompetition);
        $this->addReference(self::COMPETITION_WJPC_2024, $wjpcCompetition);

        $czechNationalsCompetition = $this->createCompetition(
            id: self::COMPETITION_CZECH_NATIONALS_2024,
            name: 'Czech National Championship 2024',
            location: 'Brno',
            locationCountryCode: 'cz',
            tag: $nationalTag,
            daysFromNow: 60,
            slug: 'czech-nationals-2024',
            shortcut: 'CZE24',
            description: 'Czech National Jigsaw Puzzle Championship 2024',
            approvedAt: $this->clock->now(),
        );
        $manager->persist($czechNationalsCompetition);
        $this->addReference(self::COMPETITION_CZECH_NATIONALS_2024, $czechNationalsCompetition);

        $unapprovedCompetition = $this->createCompetition(
            id: self::COMPETITION_UNAPPROVED,
            name: 'Unapproved Puzzle Event',
            location: 'Vienna',
            locationCountryCode: 'at',
            tag: null,
            daysFromNow: 90,
            slug: 'unapproved-puzzle-event',
            addedByPlayer: $regularPlayer,
            createdAt: $this->clock->now(),
        );
        $unapprovedCompetition->maintainers->add($regularPlayer);
        $manager->persist($unapprovedCompetition);
        $this->addReference(self::COMPETITION_UNAPPROVED, $unapprovedCompetition);

        $recurringOnlineCompetition = $this->createCompetition(
            id: self::COMPETITION_RECURRING_ONLINE,
            name: 'Euro Jigsaw Jam',
            location: 'Online',
            locationCountryCode: 'eu',
            tag: null,
            daysFromNow: 0,
            slug: 'euro-jigsaw-jam',
            description: 'Monthly online jigsaw puzzle competition',
            isOnline: true,
            approvedAt: $this->clock->now(),
        );
        $manager->persist($recurringOnlineCompetition);
        $this->addReference(self::COMPETITION_RECURRING_ONLINE, $recurringOnlineCompetition);

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            TagFixture::class,
            PlayerFixture::class,
        ];
    }

    private function createCompetition(
        string $id,
        string $name,
        string $location,
        string $locationCountryCode,
        null|Tag $tag,
        int $daysFromNow,
        null|string $slug = null,
        null|string $shortcut = null,
        null|string $description = null,
        null|string $link = null,
        null|string $registrationLink = null,
        null|string $resultsLink = null,
        bool $isOnline = false,
        null|DateTimeImmutable $approvedAt = null,
        null|Player $addedByPlayer = null,
        null|DateTimeImmutable $createdAt = null,
    ): Competition {
        $dateFrom = $this->clock->now()->modify("+{$daysFromNow} days");
        $dateTo = $dateFrom->modify('+2 days');

        return new Competition(
            id: Uuid::fromString($id),
            name: $name,
            slug: $slug,
            shortcut: $shortcut,
            logo: null,
            description: $description,
            link: $link,
            registrationLink: $registrationLink,
            resultsLink: $resultsLink,
            location: $location,
            locationCountryCode: $locationCountryCode,
            dateFrom: $dateFrom,
            dateTo: $dateTo,
            tag: $tag,
            isOnline: $isOnline,
            approvedAt: $approvedAt,
            addedByPlayer: $addedByPlayer,
            createdAt: $createdAt,
        );
    }
}
