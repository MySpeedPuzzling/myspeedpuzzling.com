<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Every fixture player has seen the XP launch announcement modal long ago. It goes to everybody with a solving time,
 * so without this it would open on the first page view of nearly every test (and intercept clicks in Panther). Tests
 * of the modal delete the row of their player (docs/features/announcement-modals.md, "Panther note").
 */
final class XpLaunchRevealSeenFixture extends Fixture implements DependentFixtureInterface
{
    public const string SEEN_AT = '2026-01-01 12:00:00';

    public function load(ObjectManager $manager): void
    {
        assert($manager instanceof EntityManagerInterface);
        $manager->flush();

        $manager->getConnection()->executeStatement(
            "INSERT INTO player_modal_impression (id, player_id, modal, displayed_at)
             SELECT gen_random_uuid(), player.id, 'xp_launch_reveal', :seenAt FROM player",
            ['seenAt' => self::SEEN_AT],
        );
    }

    /**
     * @return array<class-string<Fixture>>
     */
    public function getDependencies(): array
    {
        return [
            PlayerFixture::class,
            DuplicateResultsFixture::class,
            SuspiciousTimesFixture::class,
        ];
    }
}
