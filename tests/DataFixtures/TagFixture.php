<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Tag;

final class TagFixture extends Fixture
{
    public const string TAG_WJPC = '018d0001-0000-0000-0000-000000000001';
    public const string TAG_NATIONAL = '018d0001-0000-0000-0000-000000000002';
    public const string TAG_ONLINE = '018d0001-0000-0000-0000-000000000003';

    public function load(ObjectManager $manager): void
    {
        $wjpcTag = $this->createTag(
            id: self::TAG_WJPC,
            name: 'WJPC',
        );
        $manager->persist($wjpcTag);
        $this->addReference(self::TAG_WJPC, $wjpcTag);

        $nationalTag = $this->createTag(
            id: self::TAG_NATIONAL,
            name: 'National Championship',
        );
        $manager->persist($nationalTag);
        $this->addReference(self::TAG_NATIONAL, $nationalTag);

        $onlineTag = $this->createTag(
            id: self::TAG_ONLINE,
            name: 'Online Competition',
        );
        $manager->persist($onlineTag);
        $this->addReference(self::TAG_ONLINE, $onlineTag);

        $manager->flush();
    }

    private function createTag(string $id, string $name): Tag
    {
        return new Tag(
            id: Uuid::fromString($id),
            name: $name,
        );
    }
}
