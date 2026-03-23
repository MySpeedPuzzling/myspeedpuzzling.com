<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\FeatureRequest;
use SpeedPuzzling\Web\Entity\Player;

final class FeatureRequestFixture extends Fixture implements DependentFixtureInterface
{
    public const string FEATURE_REQUEST_POPULAR = '018d0010-0000-0000-0000-000000000001';
    public const string FEATURE_REQUEST_NEW = '018d0010-0000-0000-0000-000000000002';

    public function __construct(
        private readonly ClockInterface $clock,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $playerWithStripe = $this->getReference(PlayerFixture::PLAYER_WITH_STRIPE, Player::class);
        $playerAdmin = $this->getReference(PlayerFixture::PLAYER_ADMIN, Player::class);

        $popularRequest = new FeatureRequest(
            id: Uuid::fromString(self::FEATURE_REQUEST_POPULAR),
            author: $playerWithStripe,
            title: 'Add dark mode support',
            description: 'It would be great to have a dark mode option for the website. Many users prefer dark themes, especially when puzzling late at night.',
            createdAt: $this->clock->now()->modify('-7 days'),
        );
        $popularRequest->voteCount = 3;
        $manager->persist($popularRequest);
        $this->addReference(self::FEATURE_REQUEST_POPULAR, $popularRequest);

        $newRequest = new FeatureRequest(
            id: Uuid::fromString(self::FEATURE_REQUEST_NEW),
            author: $playerAdmin,
            title: 'Puzzle difficulty rating',
            description: 'Allow users to rate puzzles by difficulty so others know what to expect before buying.',
            createdAt: $this->clock->now()->modify('-1 day'),
        );
        $newRequest->voteCount = 1;
        $manager->persist($newRequest);
        $this->addReference(self::FEATURE_REQUEST_NEW, $newRequest);

        $manager->flush();
    }

    /**
     * @return array<class-string<Fixture>>
     */
    public function getDependencies(): array
    {
        return [
            PlayerFixture::class,
        ];
    }
}
