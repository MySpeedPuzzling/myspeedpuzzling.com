<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\FeatureRequest;
use SpeedPuzzling\Web\Entity\FeatureRequestComment;
use SpeedPuzzling\Web\Entity\Player;

final class FeatureRequestCommentFixture extends Fixture implements DependentFixtureInterface
{
    public const string COMMENT_1 = '018d0012-0000-0000-0000-000000000001';
    public const string COMMENT_2 = '018d0012-0000-0000-0000-000000000002';

    public function __construct(
        private readonly ClockInterface $clock,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $playerAdmin = $this->getReference(PlayerFixture::PLAYER_ADMIN, Player::class);
        $playerRegular = $this->getReference(PlayerFixture::PLAYER_REGULAR, Player::class);
        $popularRequest = $this->getReference(FeatureRequestFixture::FEATURE_REQUEST_POPULAR, FeatureRequest::class);

        $comment1 = new FeatureRequestComment(
            id: Uuid::fromString(self::COMMENT_1),
            featureRequest: $popularRequest,
            author: $playerAdmin,
            content: 'I totally agree! Dark mode would be amazing for late night puzzling sessions.',
            createdAt: $this->clock->now()->modify('-5 days'),
        );
        $manager->persist($comment1);
        $this->addReference(self::COMMENT_1, $comment1);

        $comment2 = new FeatureRequestComment(
            id: Uuid::fromString(self::COMMENT_2),
            featureRequest: $popularRequest,
            author: $playerRegular,
            content: 'Yes please! My eyes would thank you.',
            createdAt: $this->clock->now()->modify('-3 days'),
        );
        $manager->persist($comment2);
        $this->addReference(self::COMMENT_2, $comment2);

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
