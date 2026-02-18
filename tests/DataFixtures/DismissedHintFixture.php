<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\DataFixtures;

use DateTimeImmutable;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\DismissedHint;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Value\HintType;

final class DismissedHintFixture extends Fixture implements DependentFixtureInterface
{
    public const string DISMISSED_HINT_PRIVATE_MARKETPLACE = '018d000f-0000-0000-0000-000000000001';

    public function load(ObjectManager $manager): void
    {
        $playerPrivate = $this->getReference(PlayerFixture::PLAYER_PRIVATE, Player::class);

        $dismissedHint = new DismissedHint(
            id: Uuid::fromString(self::DISMISSED_HINT_PRIVATE_MARKETPLACE),
            player: $playerPrivate,
            type: HintType::MarketplaceDisclaimer,
            dismissedAt: new DateTimeImmutable('2025-01-15 10:00:00'),
        );
        $manager->persist($dismissedHint);

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
