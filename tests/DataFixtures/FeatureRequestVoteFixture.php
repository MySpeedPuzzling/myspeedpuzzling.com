<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\FeatureRequest;
use SpeedPuzzling\Web\Entity\FeatureRequestVote;
use SpeedPuzzling\Web\Entity\Player;

final class FeatureRequestVoteFixture extends Fixture implements DependentFixtureInterface
{
    public const string VOTE_ADMIN_FOR_POPULAR = '018d0011-0000-0000-0000-000000000002';
    public const string VOTE_REGULAR_FOR_POPULAR = '018d0011-0000-0000-0000-000000000003';

    public function __construct(
        private readonly ClockInterface $clock,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $playerAdmin = $this->getReference(PlayerFixture::PLAYER_ADMIN, Player::class);
        $playerRegular = $this->getReference(PlayerFixture::PLAYER_REGULAR, Player::class);
        $popularRequest = $this->getReference(FeatureRequestFixture::FEATURE_REQUEST_POPULAR, FeatureRequest::class);

        // Admin voted for popular this month
        $vote1 = new FeatureRequestVote(
            id: Uuid::fromString(self::VOTE_ADMIN_FOR_POPULAR),
            featureRequest: $popularRequest,
            voter: $playerAdmin,
            votedAt: $this->clock->now()->modify('first day of this month noon'),
        );
        $manager->persist($vote1);

        // Regular player voted for popular last month (doesn't count for current month budget)
        $vote2 = new FeatureRequestVote(
            id: Uuid::fromString(self::VOTE_REGULAR_FOR_POPULAR),
            featureRequest: $popularRequest,
            voter: $playerRegular,
            votedAt: $this->clock->now()->modify('-35 days'),
        );
        $manager->persist($vote2);

        $manager->flush();
    }

    /**
     * @return array<class-string<Fixture>>
     */
    public function getDependencies(): array
    {
        return [
            PlayerFixture::class,
            FeatureRequestFixture::class,
        ];
    }
}
