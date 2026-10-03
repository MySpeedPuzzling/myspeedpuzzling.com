<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\ComparisonSubject;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;

/**
 * Comparison line-ups (docs/features/player-comparison.md):
 * - PLAYER_REGULAR (free): Solo = themselves + PLAYER_WITH_STRIPE - exactly at the free cap.
 * - PLAYER_WITH_STRIPE (member): Solo = themselves + PLAYER_ADMIN + PLAYER_REGULAR; Pairs = the PLAYER_REGULAR &
 *   PLAYER_PRIVATE pair (TIME_12's puzzling team).
 */
final class ComparisonSubjectFixture extends Fixture implements DependentFixtureInterface
{
    public const string REGULAR_SELF = '018d0013-0000-0000-0000-000000000001';
    public const string REGULAR_STRIPE = '018d0013-0000-0000-0000-000000000002';
    public const string STRIPE_SELF = '018d0013-0000-0000-0000-000000000003';
    public const string STRIPE_ADMIN = '018d0013-0000-0000-0000-000000000004';
    public const string STRIPE_REGULAR = '018d0013-0000-0000-0000-000000000005';
    public const string STRIPE_PAIR = '018d0013-0000-0000-0000-000000000006';

    public function __construct(
        private readonly ClockInterface $clock,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $regular = $this->getReference(PlayerFixture::PLAYER_REGULAR, Player::class);
        $stripe = $this->getReference(PlayerFixture::PLAYER_WITH_STRIPE, Player::class);
        $admin = $this->getReference(PlayerFixture::PLAYER_ADMIN, Player::class);
        $pair = $this->getReference(PuzzleSolvingTimeFixture::TIME_12, PuzzleSolvingTime::class)->puzzlingTeam;
        assert($pair !== null);

        $now = $this->clock->now();

        $subjects = [
            ComparisonSubject::ofPlayer(Uuid::fromString(self::REGULAR_SELF), $regular, $regular, $now->modify('-5 days')),
            ComparisonSubject::ofPlayer(Uuid::fromString(self::REGULAR_STRIPE), $regular, $stripe, $now->modify('-5 days')),
            ComparisonSubject::ofPlayer(Uuid::fromString(self::STRIPE_SELF), $stripe, $stripe, $now->modify('-4 days')),
            ComparisonSubject::ofPlayer(Uuid::fromString(self::STRIPE_ADMIN), $stripe, $admin, $now->modify('-4 days')),
            ComparisonSubject::ofPlayer(Uuid::fromString(self::STRIPE_REGULAR), $stripe, $regular, $now->modify('-2 days')),
            ComparisonSubject::ofTeam(Uuid::fromString(self::STRIPE_PAIR), $stripe, $pair, $now->modify('-1 day')),
        ];

        foreach ($subjects as $subject) {
            $manager->persist($subject);
        }

        $manager->flush();
    }

    /**
     * @return array<class-string<Fixture>>
     */
    public function getDependencies(): array
    {
        return [
            PlayerFixture::class,
            PuzzleSolvingTimeFixture::class,
        ];
    }
}
