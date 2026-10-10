<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\DataFixtures;

use DateTimeImmutable;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use SpeedPuzzling\Web\Entity\XpSettings;

/**
 * The tests' own XP formula cutoff (production: the deploy moment, stored by the migration). Fixed, so solves the
 * tests date relative to it land on a known side. The launch marker is set, like after the launch (pages read the
 * switch through FixedXpLaunchReadiness in the tests).
 */
final class XpSettingsFixture extends Fixture
{
    public const string FULL_FORMULA_FROM = '2026-08-01 00:00:00';
    public const string LAUNCH_READY_AT = '2026-08-02 00:00:00';

    public static function fullFormulaFrom(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::FULL_FORMULA_FROM);
    }

    public function load(ObjectManager $manager): void
    {
        $manager->persist(new XpSettings(XpSettings::ID, self::fullFormulaFrom(), new DateTimeImmutable(self::LAUNCH_READY_AT)));
        $manager->flush();
    }
}
