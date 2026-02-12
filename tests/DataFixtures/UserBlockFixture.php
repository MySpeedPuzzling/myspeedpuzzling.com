<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\UserBlock;

final class UserBlockFixture extends Fixture implements DependentFixtureInterface
{
    public const string BLOCK_REGULAR_BLOCKS_PRIVATE = '018d0010-0000-0000-0000-000000000001';

    public function __construct(
        private readonly ClockInterface $clock,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $playerRegular = $this->getReference(PlayerFixture::PLAYER_REGULAR, Player::class);
        $playerPrivate = $this->getReference(PlayerFixture::PLAYER_PRIVATE, Player::class);

        $block = new UserBlock(
            id: Uuid::fromString(self::BLOCK_REGULAR_BLOCKS_PRIVATE),
            blocker: $playerRegular,
            blocked: $playerPrivate,
            blockedAt: $this->clock->now()->modify('-7 days'),
        );
        $manager->persist($block);

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
